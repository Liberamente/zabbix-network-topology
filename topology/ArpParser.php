<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * ArpParser — aus dem ipNetToMediaPhysAddress-Walk eines Routers/L3-Switches
 * die IP->MAC-Tabelle machen. Rein, kein SNMP, kein API-Call, testbar.
 *
 * WOZU
 * ----
 * Der MAC/FDB-Finder beantwortet „die MAC haengt an Port Y". Oft kennt man aber
 * nur die IP. Die ARP-Tabelle (IP->MAC) eines Routers schliesst die Luecke:
 * IP -> MAC (hier) -> Zugangsport (FdbParser/FdbResolver). Wie die FDB kommt sie
 * API-only ueber ein Zabbix-`walk[]`-Item (proxy-tauglich), NICHT aus einem
 * Direkt-Walk des Frontends.
 *
 * OID: ipNetToMediaPhysAddress  1.3.6.1.2.1.4.22.1.2
 *   Index = ifIndex + 4 IPv4-Oktette (fuenf Zahlen); die IP sind die LETZTEN
 *           vier. Der ifIndex davor interessiert hier nicht.
 *   Wert  = die MAC (PhysAddress, 6 Byte). Die Schreibweise haengt an Geraet
 *           und Template ("Hex-STRING: 00 11 ..", "0x0011..", Doppelpunkte) —
 *           deshalb tolerant: zwoelf Hex-Ziffern heraus, egal wie getrennt.
 *
 * Mehrere IPs auf dieselbe MAC sind normal (ein Router-Interface traegt viele).
 * Dieselbe IP auf zwei MACs waere ein Konflikt — den entscheidet die Action
 * (ueber mehrere Router und deren Zeitstempel), nicht diese reine Logik.
 */
final class ArpParser {

    /**
     * Ein ipNetToMediaPhysAddress-Walk -> [IPv4 => MAC].
     *
     * @return array<string, string> IPv4 (als String) => MAC aa:bb:cc:dd:ee:ff
     */
    public static function parse(string $arpWalk): array {
        $map = [];
        foreach (FdbParser::lines($arpWalk) as [$oid, $val]) {
            $ipOcts = FdbParser::lastN($oid, 4);
            if ($ipOcts === null) {
                continue;
            }
            foreach ($ipOcts as $o) {
                if ($o < 0 || $o > 255) {
                    continue 2;                 // kein gueltiges IPv4-Oktett
                }
            }
            $mac = self::macFromValue($val);
            if ($mac === null) {
                continue;
            }
            $map[implode('.', $ipOcts)] = $mac;
        }
        return $map;
    }

    /**
     * Eine IPv6 mit EUI-64-Interface-ID -> die darin eingebettete MAC, sonst
     * null. OHNE SNMP: SLAAC und Link-Local tragen die MAC selbst.
     *
     * Die Interface-ID (die letzten 64 Bit) ist bei EUI-64 die MAC mit `ff:fe`
     * in der Mitte und umgedrehtem U/L-Bit (0x02) im ersten Byte. Also:
     *   fe80::0211:22ff:fe33:4455  ->  02 11 22 ff fe 33 44 55
     *   ff:fe raus, 02^02=00       ->  00:11:22:33:44:55
     * Fehlt der ff:fe-Marker (Privacy-Extensions, DHCPv6, manuell), ist es KEINE
     * EUI-64 und die MAC steht nicht drin -> null (dann hilft nur die ND-Tabelle).
     */
    public static function macFromEui64(string $ipv6): ?string {
        $ip = (string) preg_replace('/%.*$/', '', trim($ipv6));   // Zone-ID weg
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        $b = array_values(unpack('C16', $packed));
        // Interface-ID = Bytes 8..15; EUI-64-Marker: Byte 11 == 0xff, 12 == 0xfe.
        if ($b[11] !== 0xff || $b[12] !== 0xfe) {
            return null;
        }
        // Das U/L-Bit (0x02) im ersten IID-Byte MUSS gesetzt sein: eine echte
        // EUI-64 aus einer universellen MAC dreht es auf 1, waehrend Privacy-/
        // Random-Adressen (RFC 4941/7217) es bewusst auf 0 lassen. Ohne diese
        // Pruefung wuerde eine Privacy-Adresse, die zufaellig 0xff,0xfe an diesen
        // Offsets traegt (1:65536), als EUI-64 durchgehen und eine erfundene MAC
        // liefern, statt in die ND-Tabelle zu fallen.
        if (($b[8] & 0x02) === 0) {
            return null;
        }
        $octets = [$b[8] ^ 0x02, $b[9], $b[10], $b[13], $b[14], $b[15]];
        $hex = '';
        foreach ($octets as $o) {
            $hex .= sprintf('%02x', $o & 0xff);
        }
        if ($hex === '000000000000' || $hex === 'ffffffffffff') {
            return null;
        }
        return implode(':', str_split($hex, 2));
    }

    /**
     * Ein ipNetToPhysicalPhysAddress-Walk (1.3.6.1.2.1.4.35.1.4) -> [IPv6 => MAC].
     * Die ND-/Neighbor-Tabelle fuer die v6-Adressen, die KEINE EUI-64 sind
     * (Privacy-Extensions, DHCPv6). Nur IPv6-Zeilen (addressType 2, len 16); der
     * IPv4-Teil dieser Tabelle interessiert hier nicht (dafuer ist nt.arp da).
     *
     * Index = ifIndex . inetAddressType . inetAddressLen . inetAddress(len Byte).
     * Schluessel ist die KANONISCHE v6 (inet_ntop), damit `fe80::1` und
     * `fe80:0:0:0:0:0:0:1` denselben Treffer geben.
     *
     * @return array<string, string> kanonische IPv6 => MAC aa:bb:cc:dd:ee:ff
     */
    public static function parseNd(string $ndWalk): array {
        $map = [];
        foreach (FdbParser::lines($ndWalk) as [$oid, $val]) {
            $idx = (string) preg_replace('/^\.?1\.3\.6\.1\.2\.1\.4\.35\.1\.4\./', '', $oid);
            if ($idx === $oid) {
                continue;                           // nicht diese Tabelle
            }
            $parts = explode('.', $idx);
            // ifIndex, type, len, dann len Adress-Oktette.
            if (count($parts) < 4) {
                continue;
            }
            $type = (int) $parts[1];
            $len  = (int) $parts[2];
            $addr = array_slice($parts, 3);
            if ($type !== 2 || $len !== 16 || count($addr) !== 16) {
                continue;                           // nur IPv6
            }
            foreach ($addr as $o) {
                if (!ctype_digit($o) || (int) $o > 255) {
                    continue 2;
                }
            }
            $packed = pack('C16', ...array_map('intval', $addr));
            $canon  = @inet_ntop($packed);
            if ($canon === false) {
                continue;
            }
            $mac = self::macFromValue($val);
            if ($mac === null) {
                continue;
            }
            $map[$canon] = $mac;
        }
        return $map;
    }

    /**
     * Eine IPv6-Eingabe auf ihre kanonische Form bringen (inet_pton/inet_ntop),
     * damit der Nachschlag in parseNd() unabhaengig von Schreibweise/Zone trifft.
     */
    public static function canonicalV6(string $ip): ?string {
        $clean  = (string) preg_replace('/%.*$/', '', trim($ip));
        $packed = @inet_pton($clean);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        return inet_ntop($packed);
    }

    /**
     * Eine PhysAddress-Schreibweise auf aa:bb:cc:dd:ee:ff bringen. Tolerant:
     * Typ-Praefix ("Hex-STRING: ..", "STRING: ..") und ein "0x" weg, dann genau
     * zwoelf Hex-Ziffern, sonst null. Die 00:00:00:00:00:00 taugt nicht als
     * Identitaet (unvollstaendiger/incomplete ARP-Eintrag).
     */
    public static function macFromValue(string $val): ?string {
        $v = trim($val);
        // Typ-Praefix wie "Hex-STRING: " abschneiden: Wort, ":", dann Leerraum.
        // Eine nackte MAC "00:11:.." beginnt mit einer Ziffer (nicht [A-Za-z])
        // und hat nach dem ":" kein Leerzeichen — sie bleibt unangetastet.
        $v = (string) preg_replace('/^[A-Za-z][A-Za-z0-9 -]*:\s+/', '', $v);
        $v = (string) preg_replace('/0x/i', '', $v);
        $hex = strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', $v));
        if (strlen($hex) !== 12 || $hex === '000000000000') {
            return null;
        }
        return implode(':', str_split($hex, 2));
    }
}
