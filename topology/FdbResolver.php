<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * FdbResolver — aus einem rohen FDB-Abzug die eine brauchbare Aussage machen:
 * „die MAC haengt an SWITCH X, Port Y".
 *
 * DAS PROBLEM
 * -----------
 * Eine MAC steht in der Forwarding-Database JEDES Switches auf dem Pfad — auf
 * jedem jeweils auf dem Uplink-Port, der Richtung Gerät zeigt. Ein nackter
 * FDB-Dump liefert also fuenf Treffer und keine Antwort. Brauchbar ist allein
 * der ZUGANGSPORT: der eine Port, auf dem die MAC steht und der KEIN Uplink
 * (= keine Switch-zu-Switch-Verbindung) ist.
 *
 * Genau diese Unterscheidung kann dieses Modul treffen und ein generisches
 * Item nicht: es weiss aus LLDP bereits, welche Ports Switch-zu-Switch gehen.
 * Diese Klasse bekommt die FDB-Treffer PLUS diese Portkarte rein und gibt die
 * Aussage raus — rein, kein SNMP, kein API-Call, testbar wie LldpEdgeBuilder.
 *
 * WAS SIE NICHT VERSCHWEIGT (die drei ehrlichen Faelle)
 * -----------------------------------------------------
 *   access       genau ein Nicht-Uplink-Treffer -> klare Antwort.
 *   ambiguous    mehrere Nicht-Uplink-Treffer (nachgelagerter unmanaged Switch,
 *                VMs hinter einem Hypervisor, zwei Edge-Switche): die Anzeige
 *                legt sich NICHT auf einen fest, sie zeigt alle.
 *   uplink_only  die MAC steht nur auf Uplink-Ports — das Geraet sitzt jenseits
 *                der bekannten Topologie.
 *   no_lldp      mindestens ein meldender Switch hat KEIN LLDP-Portwissen; dann
 *                ist „Uplink oder nicht" fuer ihn nicht entscheidbar, und das
 *                gehoert gesagt statt geraten.
 *
 * Jeder Treffer traegt seinen ZEITSTEMPEL (FDB altert, Standard 300 s): „auf
 * Port 18 gefunden" ist immer eine Momentaufnahme. ts_newest reicht das an die
 * Anzeige durch, die entscheidet, ab wann sie „vor einer Weile" sagt.
 */
final class FdbResolver {

    /**
     * Uplink-Ports aus der FDB SCHÄTZEN, wenn kein LLDP-Portwissen vorliegt.
     *
     * Ein Uplink trägt den Verkehr des ganzen dahinterliegenden Netzes, steht
     * also in der FDB mit VIELEN MACs; ein Zugangsport mit wenigen. Ports ab
     * $minMacs verschiedenen MACs gelten damit als Switch-zu-Switch.
     *
     * Das ist eine HEURISTIK und ausdrücklich schwächer als LLDP: ein Access-
     * Port zu einem Hypervisor (viele VMs) oder einem nachgelagerten unmanaged
     * Switch sieht genauso aus. Deshalb ist LLDP (switchPorts von außen) der
     * bessere Weg — diese Methode ist der Rückfall, solange das Modul die
     * LLDP-Portkarte nicht an die FDB-Suche reicht. locate() nimmt switchPorts
     * als Eingabe, ein späterer LLDP-Pfad ist also ein Drop-in.
     *
     * @param array $hits  wie bei locate()
     * @return array<string, array<string, bool>>  [ hostid => [ port => true ] ]
     */
    public static function uplinkPortsByCount(array $hits, int $minMacs = 5): array {
        // Pro (Host,Port) die verschiedenen MACs zählen.
        $macs = [];
        foreach ($hits as $h) {
            if (!is_array($h)) {
                continue;
            }
            $mac = self::normalizeMac((string) ($h['mac'] ?? ''));
            if ($mac === null) {
                continue;
            }
            $hid  = (string) ($h['hostid'] ?? '');
            $port = (string) ($h['port'] ?? '');
            $macs[$hid][$port][$mac] = true;
        }
        $uplinks = [];
        foreach ($macs as $hid => $ports) {
            foreach ($ports as $port => $set) {
                if (count($set) >= $minMacs) {
                    $uplinks[$hid][$port] = true;
                }
            }
        }
        return $uplinks;
    }

    /**
     * MAC auf aa:bb:cc:dd:ee:ff normalisieren (lowercase, Doppelpunkt).
     *
     * Nimmt die ueblichen Schreibweisen: Doppelpunkt, Bindestrich, Cisco-Punkt
     * (aabb.ccdd.eeff), nackte 12 Hex, Gross/Klein. Alles Nicht-Hex faellt weg,
     * danach muessen genau 12 Hexziffern bleiben — sonst null (keine MAC).
     */
    public static function normalizeMac(string $raw): ?string {
        $hex = strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', $raw));
        if (strlen($hex) !== 12) {
            return null;
        }
        return implode(':', str_split($hex, 2));
    }

    /**
     * @param string $mac          gesuchte MAC in beliebiger Schreibweise
     * @param array  $hits         FDB-Treffer:
     *     [ ['hostid'=>string, 'mac'=>string, 'port'=>string, 'ts'=>int, 'vlan'=>?int], ... ]
     *     'port' ist der bereits aufgeloeste Portname/ifIndex (BRIDGE-Port ->
     *     ifIndex -> ifName macht der Aufrufer, nicht diese reine Logik).
     * @param array  $switchPorts  Ports MIT LLDP-Nachbar (= Switch-zu-Switch):
     *     [ hostid => [ port => true, ... ] ]
     * @param array  $lldpKnown    Switches, fuer die ueberhaupt LLDP-Portwissen
     *     vorliegt: [ hostid => true ]. Fehlt ein Host hier, sind seine Treffer
     *     'unknown' (nicht klassifizierbar), nicht stillschweigend 'access'.
     *
     * @return array{
     *     mac: ?string, status: string,
     *     access: list<array{hostid:string,port:string,ts:int,vlan:?int}>,
     *     all: list<array{hostid:string,port:string,ts:int,vlan:?int,kind:string}>,
     *     ts_newest: int
     * }
     *   status: 'access' | 'ambiguous' | 'uplink_only' | 'no_lldp'
     *           | 'not_found' | 'invalid'
     */
    public static function locate(string $mac, array $hits, array $switchPorts, array $lldpKnown): array {
        $norm = self::normalizeMac($mac);
        if ($norm === null) {
            return ['mac' => null, 'status' => 'invalid',
                    'access' => [], 'all' => [], 'ts_newest' => 0];
        }

        $all       = [];
        $ts_newest = 0;
        foreach ($hits as $h) {
            if (!is_array($h)) {
                continue;
            }
            if (self::normalizeMac((string) ($h['mac'] ?? '')) !== $norm) {
                continue;
            }
            $hid  = (string) ($h['hostid'] ?? '');
            $port = (string) ($h['port'] ?? '');
            $ts   = (int) ($h['ts'] ?? 0);
            $vlan = isset($h['vlan']) && $h['vlan'] !== null ? (int) $h['vlan'] : null;

            // Klassifikation: kein LLDP-Wissen -> unklassifiziert; sonst Uplink,
            // wenn der Port einen Switch-Nachbarn hat, sonst Zugangsport.
            $kind = !isset($lldpKnown[$hid])
                ? 'unknown'
                : (isset($switchPorts[$hid][$port]) ? 'uplink' : 'access');

            $all[] = ['hostid' => $hid, 'port' => $port, 'ts' => $ts,
                      'vlan' => $vlan, 'kind' => $kind];
            if ($ts > $ts_newest) {
                $ts_newest = $ts;
            }
        }

        if (!$all) {
            return ['mac' => $norm, 'status' => 'not_found',
                    'access' => [], 'all' => [], 'ts_newest' => 0];
        }

        // Zugangs-Treffer nach (Host,Port) eindeutig zaehlen: dieselbe MAC am
        // selben Port in zwei VLANs (dot1q) ist EIN Ort, nicht zwei — sonst
        // meldete jeder VLAN-fähige Switch faelschlich „mehrdeutig".
        $access = [];
        $seen   = [];
        foreach ($all as $h) {
            if ($h['kind'] !== 'access') {
                continue;
            }
            $key = $h['hostid'] . '|' . $h['port'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key]   = true;
            $access[]     = ['hostid' => $h['hostid'], 'port' => $h['port'],
                             'ts' => $h['ts'], 'vlan' => $h['vlan']];
        }

        $hasUnknown = false;
        foreach ($all as $h) {
            if ($h['kind'] === 'unknown') {
                $hasUnknown = true;
                break;
            }
        }

        if (count($access) === 1) {
            $status = 'access';
        }
        elseif (count($access) > 1) {
            $status = 'ambiguous';
        }
        elseif ($hasUnknown) {
            // Keine sicheren Zugangsports, aber ein Switch ohne LLDP-Wissen:
            // vielleicht IST einer seiner Ports der Zugang, wir koennen es nur
            // nicht sagen.
            $status = 'no_lldp';
        }
        else {
            // Alles Uplink: die MAC sitzt jenseits der bekannten Topologie.
            $status = 'uplink_only';
        }

        return ['mac' => $norm, 'status' => $status, 'access' => $access,
                'all' => $all, 'ts_newest' => $ts_newest];
    }
}
