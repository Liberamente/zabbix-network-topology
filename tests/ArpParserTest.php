<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

/**
 * Unit-Test fuer Topology\ArpParser.
 *
 * Die IP steckt in den LETZTEN VIER Index-Oktetten (davor der ifIndex), die MAC
 * im WERT — und der Wert kommt je nach Geraet/Template in verschiedenen
 * Schreibweisen ("Hex-STRING: ..", "0x..", Doppelpunkte). Der Parser muss alle
 * auf dieselbe MAC bringen und einen unvollstaendigen (all-zero) Eintrag lassen.
 *
 * ALLE MACs HIER SIND ERFUNDEN, die IPs aus 10.x (Testnetz). Kein echtes ARP.
 *
 * Laeuft ohne DB/Session/HTTP/Zabbix — nur PHP.  Aufruf: php tests/ArpParserTest.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file  = array_pop($parts);
    $dir   = implode('/', array_map('strtolower', $parts));
    $path  = dirname(__DIR__) . '/' . $dir . '/' . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Modules\NetworkTopology\Topology\ArpParser;
use Modules\NetworkTopology\Topology\FdbResolver;

$failures = 0;
function check(string $what, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %-46s got=%-24s want=%s\n",
        $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

echo "\n  ArpParser — ipNetToMediaPhysAddress-Walk -> IP/MAC\n\n";

// Basis-OID 1.3.6.1.2.1.4.22.1.2, dann ifIndex (10 bzw. 4) + 4 IP-Oktette.
// Drei Schreibweisen des Werts, bewusst gemischt.
$walk = <<<TXT
.1.3.6.1.2.1.4.22.1.2.10.10.20.30.40 = Hex-STRING: AA BB CC 00 00 01
1.3.6.1.2.1.4.22.1.2.10.10.20.30.41 = 0xAABBCC000002
.1.3.6.1.2.1.4.22.1.2.4.10.20.30.42 = AA:BB:CC:00:00:03
TXT;
$map = ArpParser::parse($walk);
check('Hex-STRING -> IP/MAC',  $map['10.20.30.40'] ?? null, 'aa:bb:cc:00:00:01');
check('0x-Form',               $map['10.20.30.41'] ?? null, 'aa:bb:cc:00:00:02');
check('Doppelpunkt-Form',      $map['10.20.30.42'] ?? null, 'aa:bb:cc:00:00:03');
check('drei Eintraege',        count($map),                 3);

// Mehrere IPs auf dieselbe MAC (ein Router-Interface) — beide bleiben.
$dup = ArpParser::parse(<<<TXT
.1.3.6.1.2.1.4.22.1.2.10.10.20.30.1 = AABBCC0000FF
.1.3.6.1.2.1.4.22.1.2.10.10.20.30.2 = AABBCC0000FF
TXT);
check('zwei IPs -> eine MAC: beide da', count($dup), 2);
check('… und zeigen auf dieselbe MAC',  $dup['10.20.30.1'] === $dup['10.20.30.2'], true);

// Unvollstaendiger (all-zero) Eintrag und ungueltiges Oktett fallen raus.
check('all-zero raus', count(ArpParser::parse(
    '.1.3.6.1.2.1.4.22.1.2.10.10.20.30.50 = Hex-STRING: 00 00 00 00 00 00')), 0);
check('Oktett > 255 raus', count(ArpParser::parse(
    '.1.3.6.1.2.1.4.22.1.2.10.10.20.30.999 = AA:BB:CC:00:00:09')), 0);

// macFromValue direkt: Praefix + Cisco-Punkt, und zu kurz -> null.
check('STRING-Praefix + Cisco', ArpParser::macFromValue('STRING: aabb.cc00.0004'), 'aa:bb:cc:00:00:04');
check('zu kurz -> null',        ArpParser::macFromValue('AA BB CC'),               null);

// ── IPv6: EUI-64 (MAC steckt in der Adresse, ohne SNMP) ──────────────────────
echo "\n  ArpParser — IPv6 EUI-64\n\n";
// fe80::211:22ff:fe33:4455 -> 02 11 22 ff fe 33 44 55 -> 00:11:22:33:44:55
check('Link-Local EUI-64 -> MAC',  ArpParser::macFromEui64('fe80::211:22ff:fe33:4455'), '00:11:22:33:44:55');
// Globale SLAAC-Adresse, gleiche Interface-ID -> gleiche MAC.
check('globale SLAAC -> gleiche MAC', ArpParser::macFromEui64('2001:db8::211:22ff:fe33:4455'), '00:11:22:33:44:55');
// Gross/Klein + komprimiert egal (inet_pton normalisiert).
check('Grossschreibung egal',      ArpParser::macFromEui64('FE80::0211:22FF:FE33:4455'), '00:11:22:33:44:55');
// Zone-ID wird abgeschnitten.
check('Zone-ID abgeschnitten',     ArpParser::macFromEui64('fe80::211:22ff:fe33:4455%en0'), '00:11:22:33:44:55');
// Privacy-Extension (kein ff:fe) -> keine EUI-64 -> null.
check('Privacy-v6 -> null',        ArpParser::macFromEui64('2001:db8::dead:beef:cafe:1234'), null);
// ff:fe vorhanden, aber U/L-Bit = 0 (erstes IID-Byte 0x00): KEINE echte EUI-64
// (Privacy/Random), sonst gaebe es eine erfundene MAC statt ND-Lookup.
check('ff:fe aber U/L=0 -> null',  ArpParser::macFromEui64('fe80::11:22ff:fe33:4455'), null);
// U/L-Bit gesetzt (0x02) ist die echte EUI-64 -> MAC mit zurueckgedrehtem Bit.
check('U/L gesetzt -> echte EUI-64', ArpParser::macFromEui64('fe80::211:22ff:fe33:4455'), '00:11:22:33:44:55');
// IPv4 oder Unsinn -> null.
check('IPv4 -> null',              ArpParser::macFromEui64('192.0.2.1'), null);
check('Unsinn -> null',            ArpParser::macFromEui64('nope'), null);

// ── IPv6: ND-Tabelle (ipNetToPhysicalPhysAddress) fuer Nicht-EUI-64 ──────────
echo "\n  ArpParser — IPv6 ND-Tabelle\n\n";
// 2001:db8::dead:beef:cafe:1234 = 32.1.13.184.0.0.0.0.222.173.190.239.202.254.18.52
// Index: ifIndex(2).type(2=ipv6).len(16).<16 Oktette>
$nd = <<<TXT
.1.3.6.1.2.1.4.35.1.4.2.2.16.32.1.13.184.0.0.0.0.222.173.190.239.202.254.18.52 = Hex-STRING: AA BB CC 00 00 07
.1.3.6.1.2.1.4.35.1.4.2.1.4.10.20.30.40 = Hex-STRING: AA BB CC 00 00 08
TXT;
$ndMap = ArpParser::parseNd($nd);
$canon = ArpParser::canonicalV6('2001:db8::dead:beef:cafe:1234');
check('ND: IPv6 -> MAC',           $ndMap[$canon] ?? null, 'aa:bb:cc:00:00:07');
check('ND: IPv4-Zeile ignoriert',  count($ndMap), 1);
check('canonicalV6: kompakt == lang', ArpParser::canonicalV6('2001:db8:0:0:dead:beef:cafe:1234'), $canon);
check('canonicalV6 von IPv4 -> null', ArpParser::canonicalV6('10.0.0.1'), null);

// ── E2E-Idee: IP -> MAC (ARP) -> dieselbe Normalisierung wie der FDB-Resolver.
// Die vom ArpParser gelieferte MAC muss der Resolver 1:1 wiederfinden.
$ip  = '10.20.30.40';
$mac = $map[$ip];
check('ARP-MAC = Resolver-Normalform', FdbResolver::normalizeMac($mac), $mac);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "✖ {$failures} Fehlschlag(e).\n");
    exit(1);
}
echo "✓ ArpParser: Index -> IP, Wert -> MAC (tolerant), all-zero/ungueltig raus.\n";
