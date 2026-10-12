<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

/**
 * Unit-Test fuer Topology\FdbParser.
 *
 * Die MAC steckt im OID-INDEX (sechs Dezimal-Oktette), der Port im WERT — und
 * dazwischen liegt die Bridge-Port->ifIndex->Name-Aufloesung, bei der Bridge-Port
 * != ifIndex der klassische Stolperstein ist. Ein falscher Parser nennt einen
 * ifIndex als Port oder verliert die MAC — beides sieht aus wie eine Antwort.
 *
 * ALLE MACs HIER SIND ERFUNDEN. Kein Walk aus einem echten Netz.
 *
 * Laeuft ohne DB/Session/HTTP/Zabbix — nur PHP.
 * Aufruf:  php tests/FdbParserTest.php
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

use Modules\NetworkTopology\Topology\FdbParser;
use Modules\NetworkTopology\Topology\FdbResolver;

$failures = 0;

function check(string $what, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %-50s got=%-22s want=%s\n",
        $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

/** Ersten Treffer zu einer MAC finden. */
function hitFor(array $hits, string $mac): ?array {
    foreach ($hits as $h) {
        if ($h['mac'] === $mac) {
            return $h;
        }
    }
    return null;
}

// Bridge-Port 5 -> ifIndex 8 -> "GE0/0/8";  Bridge-Port 10 -> ifIndex 48 -> "GE0/0/48".
// Format bewusst gemischt: mit/ohne fuehrenden Punkt, mit/ohne Typ-Praefix.
$basePort = <<<TXT
.1.3.6.1.2.1.17.1.4.1.2.5 = INTEGER: 8
1.3.6.1.2.1.17.1.4.1.2.10 = 48
TXT;
$ifName = <<<TXT
.1.3.6.1.2.1.31.1.1.1.1.8 = STRING: "GE0/0/8"
.1.3.6.1.2.1.31.1.1.1.1.48 = STRING: GE0/0/48
TXT;
// MAC aa:bb:cc:00:00:01 = 170.187.204.0.0.1 -> Bridge-Port 5 -> GE0/0/8
// MAC aa:bb:cc:00:00:02 = 170.187.204.0.0.2 -> Bridge-Port 10 -> GE0/0/48
$fdb = <<<TXT
.1.3.6.1.2.1.17.4.3.1.2.170.187.204.0.0.1 = INTEGER: 5
.1.3.6.1.2.1.17.4.3.1.2.170.187.204.0.0.2 = INTEGER: 10
TXT;

$hits = FdbParser::parse('sw1', 1700, $fdb, $basePort, $ifName);
check('dot1d: zwei Treffer',  count($hits),                           2);
$h1 = hitFor($hits, 'aa:bb:cc:00:00:01');
check('mac aus OID-Index',    $h1['mac'] ?? null,                     'aa:bb:cc:00:00:01');
check('port aufgeloest (Name)', $h1['port'] ?? null,                  'GE0/0/8');
check('ts durchgereicht',     $h1['ts'] ?? null,                      1700);
check('vlan null bei dot1d',  $h1['vlan'],                            null);
check('zweiter Port',         hitFor($hits, 'aa:bb:cc:00:00:02')['port'], 'GE0/0/48');

// dot1q: Index = VLAN(10) + 6 MAC-Oktette.
$qfdb = ".1.3.6.1.2.1.17.7.1.2.2.1.2.10.170.187.204.0.0.3 = INTEGER: 5";
$hq = FdbParser::parse('sw1', 1700, '', $basePort, $ifName, $qfdb);
$h3 = hitFor($hq, 'aa:bb:cc:00:00:03');
check('dot1q: mac',           $h3['mac'] ?? null,                     'aa:bb:cc:00:00:03');
check('dot1q: vlan',          $h3['vlan'] ?? null,                    10);
check('dot1q: port',          $h3['port'] ?? null,                    'GE0/0/8');

// Fallback: ohne ifName -> ifIndex als Port; ohne basePort -> Bridge-Port.
$hNoName = FdbParser::parse('sw1', 1, $fdb, $basePort, '');
check('ohne ifName -> ifIndex', hitFor($hNoName, 'aa:bb:cc:00:00:01')['port'], '8');
$hNoBase = FdbParser::parse('sw1', 1, $fdb, '', '');
check('ohne basePort -> Bridge-Port', hitFor($hNoBase, 'aa:bb:cc:00:00:01')['port'], '5');

// Leere Eingabe -> keine Treffer, kein Fehler.
check('leer -> keine Treffer', count(FdbParser::parse('sw1', 1, '', '', '')), 0);

// ifNames: der ifName-Walk -> [ifIndex => Name]. Gemeinsame Quelle fuer die
// FDB-Treffer UND die LLDP-Uplink-Erkennung, damit die Portnamen zusammenpassen.
$ifn = FdbParser::ifNames($ifName);
check('ifNames: ifIndex 8 -> Name',  $ifn['8'] ?? null,  'GE0/0/8');
check('ifNames: ifIndex 48 -> Name', $ifn['48'] ?? null, 'GE0/0/48');
check('ifNames: leer -> leer',       count(FdbParser::ifNames('')), 0);

// ── End-to-End: Parser -> Resolver nennt den Zugangsport ─────────────────────
// Core (swC) Uplink Te1/0/1; der Zugang sitzt an sw1 GE0/0/8.
$fdbC = <<<TXT
.1.3.6.1.2.1.17.4.3.1.2.170.187.204.0.0.1 = INTEGER: 2
TXT;
$baseC = ".1.3.6.1.2.1.17.1.4.1.2.2 = INTEGER: 1";
$ifnC  = '.1.3.6.1.2.1.31.1.1.1.1.1 = STRING: "Te1/0/1"';

$allHits = array_merge(
    FdbParser::parse('sw1', 1700, $fdb,  $basePort, $ifName),  // Zugang GE0/0/8
    FdbParser::parse('swC', 1700, $fdbC, $baseC,    $ifnC)     // Uplink Te1/0/1
);
$res = FdbResolver::locate('aa:bb:cc:00:00:01', $allHits,
    ['swC' => ['Te1/0/1' => true]],                 // Te1/0/1 ist Switch-zu-Switch
    ['sw1' => true, 'swC' => true]);
check('E2E: Status access',   $res['status'],              'access');
check('E2E: Switch',          $res['access'][0]['hostid'], 'sw1');
check('E2E: Zugangsport',     $res['access'][0]['port'],   'GE0/0/8');

// ── Regression: numerischer Host-Schluessel (HTTP-500-Fix 2026-10-09) ────────
// Die fdb_search-Action legt $byHost unter (string) $hostid ab. PHP normalisiert
// einen numerischen Array-Schluessel aber zu int, also kam $hid beim Iterieren
// als int zurueck und parse(string $hostid) warf einen TypeError (HTTP 500).
// parseHostMap castet an der Grenze zurueck. Ein numerisch beschluesselter
// $byHost beweist es — die anderen Tests nutzen 'sw1' (nicht-numerisch) und
// loesen die Falle nie aus, genau die blinde Stelle, die live zuschlug.
$byHost = [];
$byHost[(string) 10084] = ['dot1d' => $fdb, 'baseport' => $basePort,
                           'ifname' => $ifName, 'ts' => 1700];
$hitsNum = FdbParser::parseHostMap($byHost);   // warf vor dem Fix TypeError
check('numerische hostid: Treffer', count($hitsNum),                    2);
check('hostid bleibt string',       $hitsNum[0]['hostid'] ?? null,      '10084');
check('wirklich string, nicht int', is_string($hitsNum[0]['hostid'] ?? null), true);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "✖ {$failures} Fehlschlag(e).\n");
    exit(1);
}
echo "✓ FdbParser: OID-Index -> MAC, Wert -> Port, aufgeloest; E2E mit Resolver.\n";
