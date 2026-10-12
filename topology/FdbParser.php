<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * FdbParser — aus den rohen SNMP-Walk-Texten eines Switches die FDB-Treffer
 * fuer den FdbResolver machen. Rein, kein SNMP, kein API-Call, testbar.
 *
 * WOHER DER TEXT KOMMT
 * --------------------
 * Das Modul bleibt API-only: die FDB kommt NICHT aus einem Direkt-Walk des
 * Frontends (das erreicht einen Switch hinter einem Zabbix-Proxy gar nicht),
 * sondern aus Zabbix-`walk[]`-Master-Items, die der Proxy sammelt. Das Modul
 * liest deren lastvalue (API) und reicht die drei Texte hier herein.
 *
 * DIE DREI TABELLEN UND WIE SIE INEINANDERGREIFEN
 * -----------------------------------------------
 *   dot1dTpFdbPort   (1.3.6.1.2.1.17.4.3.1.2)   OID-Index = MAC (6 Oktette),
 *                                               Wert = BRIDGE-Port.
 *   dot1qTpFdbPort   (1.3.6.1.2.1.17.7.1.2.2.1.2) Index = VLAN + MAC (7 Zahlen),
 *                                               Wert = BRIDGE-Port. VLAN-fähig.
 *   dot1dBasePortIfIndex (1.3.6.1.2.1.17.1.4.1.2) Index = BRIDGE-Port,
 *                                               Wert = ifIndex.
 *   ifName           (1.3.6.1.2.1.31.1.1.1.1)   Index = ifIndex, Wert = Portname.
 *
 * Der Bridge-Port ist NICHT der ifIndex — das ist der Schritt, der gern
 * vergessen wird. Also: MAC -> Bridge-Port (FDB) -> ifIndex (BasePortIfIndex)
 * -> Portname (ifName). Fehlt eine Aufloesung, fallen wir ehrlich zurueck:
 * ohne ifName auf den ifIndex, ohne BasePortIfIndex auf die Bridge-Port-Nummer.
 */
final class FdbParser {

    /**
     * @param string  $hostid    der Switch, dessen Walks das sind
     * @param int     $ts        Mess-Zeitpunkt (lastclock des walk[]-Items)
     * @param string  $fdbWalk   dot1dTpFdbPort-Walk (oder '')
     * @param string  $basePort  dot1dBasePortIfIndex-Walk (oder '')
     * @param string  $ifName    ifName-Walk (oder '')
     * @param ?string $qfdbWalk  dot1qTpFdbPort-Walk (optional, VLAN-fähig)
     *
     * @return list<array{hostid:string,mac:string,port:string,ts:int,vlan:?int}>
     *   Die 'hits' fuer FdbResolver::locate(). 'port' ist bereits aufgeloest
     *   (Portname, sonst ifIndex, sonst Bridge-Port). Doppelte MAC/Port aus
     *   dot1d UND dot1q dedupt der Resolver ohnehin (nach host|port).
     */
    public static function parse(string $hostid, int $ts, string $fdbWalk,
            string $basePort, string $ifName, ?string $qfdbWalk = null): array {
        // Bridge-Port -> ifIndex
        $bp2if = [];
        foreach (self::lines($basePort) as [$oid, $val]) {
            $bport = self::lastN($oid, 1);
            if ($bport !== null && is_numeric($val)) {
                $bp2if[$bport[0]] = (int) $val;
            }
        }
        // ifIndex -> Portname
        $if2name = self::ifNames($ifName);

        // Bridge-Port -> bester Portname (ifName > ifIndex > Bridge-Port).
        $portOf = static function (int $bport) use ($bp2if, $if2name): string {
            if (!isset($bp2if[$bport])) {
                return (string) $bport;            // kein ifIndex: Bridge-Port
            }
            $ifx = $bp2if[$bport];
            return $if2name[$ifx] ?? (string) $ifx; // kein Name: ifIndex
        };

        $hits = [];

        // dot1d: die letzten 6 OID-Zahlen sind die MAC, der Wert der Bridge-Port.
        foreach (self::lines($fdbWalk) as [$oid, $val]) {
            $octs = self::lastN($oid, 6);
            if ($octs === null || !is_numeric($val)) {
                continue;
            }
            $mac = self::octetsToMac($octs);
            if ($mac === null) {
                continue;
            }
            $hits[] = ['hostid' => $hostid, 'mac' => $mac,
                       'port' => $portOf((int) $val), 'ts' => $ts, 'vlan' => null];
        }

        // dot1q: die letzten 7 Zahlen sind VLAN + 6 MAC-Oktette.
        if ($qfdbWalk !== null && $qfdbWalk !== '') {
            foreach (self::lines($qfdbWalk) as [$oid, $val]) {
                $idx = self::lastN($oid, 7);
                if ($idx === null || !is_numeric($val)) {
                    continue;
                }
                $vlan = (int) $idx[0];
                $mac  = self::octetsToMac(array_slice($idx, 1));
                if ($mac === null) {
                    continue;
                }
                $hits[] = ['hostid' => $hostid, 'mac' => $mac,
                           'port' => $portOf((int) $val), 'ts' => $ts, 'vlan' => $vlan];
            }
        }

        return $hits;
    }

    /**
     * Die nach Host gruppierten Walk-Texte (wie die fdb_search-Action sie aus
     * den Item-lastvalues baut) auf einen Schlag parsen.
     *
     * Eigene Methode, weil genau hier die numerische-Array-Schluessel-Falle
     * sitzt: der Aufrufer legt $byHost unter (string) $hostid ab, PHP normalisiert
     * einen numerischen Schluessel aber zu int, und beim Iterieren kommt $hid als
     * int zurueck — worauf parse(string $hostid) mit einem TypeError abbrach
     * (HTTP 500, 2026-10-09). Der Cast gehoert an DIESE Grenze, nicht in jeden
     * Aufrufer, und ist hier mit einem numerisch beschluesselten $byHost testbar.
     *
     * @param array<array-key, array{dot1d?:string,dot1q?:?string,baseport?:string,ifname?:string,ts?:int}> $byHost
     * @return list<array{hostid:string,mac:string,port:string,ts:int,vlan:?int}>
     */
    public static function parseHostMap(array $byHost): array {
        $hits = [];
        foreach ($byHost as $hid => $w) {
            $hits = array_merge($hits, self::parse(
                (string) $hid, (int) ($w['ts'] ?? 0),
                $w['dot1d'] ?? '', $w['baseport'] ?? '', $w['ifname'] ?? '',
                $w['dot1q'] ?? null));
        }
        return $hits;
    }

    /**
     * ifName-Walk -> [ifIndex => Portname]. Eine Quelle: sowohl die FDB-Treffer
     * (Bridge-Port -> ifIndex -> Name) als auch die LLDP-Uplink-Erkennung
     * (lokaler Port == ifIndex -> Name) muessen denselben Namen bekommen, sonst
     * passt der Uplink-Portname nicht zum Treffer-Portnamen.
     *
     * @return array<string,string> ifIndex (als String) => Portname
     */
    public static function ifNames(string $ifNameWalk): array {
        $map = [];
        foreach (self::lines($ifNameWalk) as [$oid, $val]) {
            $ifx = self::lastN($oid, 1);
            if ($ifx !== null && $val !== '') {
                $map[$ifx[0]] = $val;
            }
        }
        return $map;
    }

    /**
     * Eine Walk-Ausgabe in [oid, value]-Paare zerlegen. Toleriert die ueblichen
     * Formate: numerische OID, mit/ohne fuehrenden Punkt, Wert mit oder ohne
     * SNMP-Typ-Praefix ("INTEGER: 5", "STRING: \"Gi1/0/1\"", "5", "1/0/1").
     *
     * Oeffentlich, weil ArpParser dieselbe Walk-Zerlegung braucht — eine Quelle
     * statt zweier, die auseinanderlaufen.
     */
    public static function lines(string $walk): array {
        $out = [];
        foreach (preg_split('/\r?\n/', $walk) ?: [] as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }
            $pos = strpos($zeile, '=');
            if ($pos === false) {
                continue;
            }
            $oid = trim(substr($zeile, 0, $pos));
            $val = trim(substr($zeile, $pos + 1));
            // SNMP-Typ-Praefix abschneiden, dann Anfuehrungszeichen.
            $val = (string) preg_replace(
                '/^(INTEGER|STRING|Gauge32|Counter32|Counter64|Hex-STRING|OID|IpAddress|Timeticks|BITS|OCTET STRING):\s*/',
                '', $val);
            $val = trim($val, "\"'");
            $out[] = [$oid, $val];
        }
        return $out;
    }

    /**
     * Die letzten $n punktgetrennten Zahlen einer OID als int-Array, oder null
     * wenn es weniger sind oder ein Teil keine Zahl ist. Oeffentlich fuer
     * ArpParser (IP sind die letzten vier Index-Oktette).
     *
     * @return ?list<int>
     */
    public static function lastN(string $oid, int $n): ?array {
        $parts = explode('.', trim($oid, '.'));
        if (count($parts) < $n) {
            return null;
        }
        $tail = array_slice($parts, -$n);
        $nums = [];
        foreach ($tail as $p) {
            if (!ctype_digit($p)) {
                return null;
            }
            $nums[] = (int) $p;
        }
        return $nums;
    }

    /**
     * Sechs Dezimal-Oktette (0..255) zu aa:bb:cc:dd:ee:ff. Null, wenn ein Oktett
     * ausserhalb liegt.
     *
     * @param list<int> $octs
     */
    private static function octetsToMac(array $octs): ?string {
        if (count($octs) !== 6) {
            return null;
        }
        $teile = [];
        foreach ($octs as $o) {
            if ($o < 0 || $o > 255) {
                return null;
            }
            $teile[] = sprintf('%02x', $o);
        }
        return implode(':', $teile);
    }
}
