<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

/**
 * Unit-Test fuer Topology\FdbResolver.
 *
 * Die heikle Aussage ist „Zugangsport gegen Uplink": greift sie daneben, nennt
 * die Suche einen Uplink als Fundort (also fuenf Switche statt einem) oder legt
 * sich bei Mehrdeutigkeit still auf einen fest. Beides sieht aus wie eine
 * Antwort und ist keine — genau das faengt dieser Test.
 *
 * Laeuft ohne DB/Session/HTTP/Zabbix — nur PHP.
 *
 * Aufruf:  php tests/FdbResolverTest.php
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

use Modules\NetworkTopology\Topology\FdbResolver;

$failures = 0;

function check(string $what, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %-52s got=%-22s want=%s\n",
        $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

// ── MAC-Normalisierung ───────────────────────────────────────────────────────
check('mac: Doppelpunkt+Gross', FdbResolver::normalizeMac('AA:BB:CC:DD:EE:FF'), 'aa:bb:cc:dd:ee:ff');
check('mac: Cisco-Punkt',       FdbResolver::normalizeMac('aabb.ccdd.eeff'),    'aa:bb:cc:dd:ee:ff');
check('mac: Bindestrich',       FdbResolver::normalizeMac('AA-BB-CC-DD-EE-FF'), 'aa:bb:cc:dd:ee:ff');
check('mac: nackte 12 Hex',     FdbResolver::normalizeMac('aabbccddeeff'),      'aa:bb:cc:dd:ee:ff');
check('mac: zu kurz -> null',   FdbResolver::normalizeMac('aabbcc'),            null);
check('mac: Unsinn -> null',    FdbResolver::normalizeMac('nope'),              null);

// ── Topologie: Core (h1) + zwei Access (h2,h3), ein Switch ohne LLDP (h4) ─────
// Uplink-Ports = Ports mit LLDP-Nachbar (Switch-zu-Switch).
$switchPorts = [
    'h1' => ['Te1/0/1' => true, 'Te1/0/2' => true],   // Core -> acc1, acc2
    'h2' => ['Gi1/0/48' => true],                      // acc1 -> Core
    'h3' => ['Gi1/0/48' => true],                      // acc2 -> Core
];
$lldpKnown = ['h1' => true, 'h2' => true, 'h3' => true];   // h4 FEHLT bewusst

$M = 'aa:bb:cc:00:00:';   // Praefix fuer die Test-MACs

// 1) Klarer Zugangsport: auf dem Core-Uplink UND am acc1-Zugangsport.
$r = FdbResolver::locate($M . '01', [
    ['hostid' => 'h1', 'mac' => $M . '01', 'port' => 'Te1/0/1',  'ts' => 100],  // Uplink
    ['hostid' => 'h2', 'mac' => $M . '01', 'port' => 'Gi1/0/8',  'ts' => 110],  // Zugang
], $switchPorts, $lldpKnown);
check('access: Status',        $r['status'],              'access');
check('access: ein Fundort',   count($r['access']),       1);
check('access: Switch',        $r['access'][0]['hostid'], 'h2');
check('access: Port',          $r['access'][0]['port'],   'Gi1/0/8');
check('access: ts_newest',     $r['ts_newest'],           110);

// 2) Mehrdeutig: zwei Zugangsports (zwei Edge-Switche / nachgelagert).
$r = FdbResolver::locate($M . '02', [
    ['hostid' => 'h2', 'mac' => $M . '02', 'port' => 'Gi1/0/8',  'ts' => 50],
    ['hostid' => 'h3', 'mac' => $M . '02', 'port' => 'Gi1/0/12', 'ts' => 60],
], $switchPorts, $lldpKnown);
check('ambiguous: Status',     $r['status'],        'ambiguous');
check('ambiguous: zwei Orte',  count($r['access']), 2);

// 3) Nur Uplinks: die MAC sitzt jenseits der bekannten Topologie.
$r = FdbResolver::locate($M . '03', [
    ['hostid' => 'h1', 'mac' => $M . '03', 'port' => 'Te1/0/1',  'ts' => 10],
    ['hostid' => 'h2', 'mac' => $M . '03', 'port' => 'Gi1/0/48', 'ts' => 12],
], $switchPorts, $lldpKnown);
check('uplink_only: Status',   $r['status'],        'uplink_only');
check('uplink_only: kein Ort', count($r['access']), 0);

// 4) Switch ohne LLDP-Wissen: nicht klassifizierbar, nicht stillschweigend Zugang.
$r = FdbResolver::locate($M . '04', [
    ['hostid' => 'h4', 'mac' => $M . '04', 'port' => 'Gi1/0/5', 'ts' => 20],
], $switchPorts, $lldpKnown);
check('no_lldp: Status',       $r['status'],        'no_lldp');
check('no_lldp: kein sicherer Ort', count($r['access']), 0);
check('no_lldp: Treffer in all',    $r['all'][0]['kind'], 'unknown');

// 5) Nicht gefunden.
$r = FdbResolver::locate($M . 'ff', [
    ['hostid' => 'h2', 'mac' => $M . '01', 'port' => 'Gi1/0/8', 'ts' => 1],
], $switchPorts, $lldpKnown);
check('not_found: Status',     $r['status'], 'not_found');

// 6) Ungueltige MAC.
$r = FdbResolver::locate('keine-mac', [], $switchPorts, $lldpKnown);
check('invalid: Status',       $r['status'], 'invalid');
check('invalid: mac null',     $r['mac'],    null);

// 7) VLAN-Dedup: dieselbe MAC am selben Zugangsport in zwei VLANs = EIN Ort.
$r = FdbResolver::locate($M . '05', [
    ['hostid' => 'h2', 'mac' => $M . '05', 'port' => 'Gi1/0/8', 'ts' => 70, 'vlan' => 10],
    ['hostid' => 'h2', 'mac' => $M . '05', 'port' => 'Gi1/0/8', 'ts' => 72, 'vlan' => 20],
], $switchPorts, $lldpKnown);
check('vlan-dedup: Status',    $r['status'],        'access');
check('vlan-dedup: ein Ort',   count($r['access']), 1);

// 8) Uplink-Schätzung aus der FDB (Rückfall ohne LLDP): viele MACs = Uplink.
$manyHits = [];
for ($i = 1; $i <= 6; $i++) {
    $manyHits[] = ['hostid' => 'core', 'mac' => sprintf('aa:bb:cc:00:01:%02x', $i),
                   'port' => 'Te1/0/1', 'ts' => 1];
}
$manyHits[] = ['hostid' => 'acc', 'mac' => 'aa:bb:cc:00:02:01', 'port' => 'Gi1/0/8', 'ts' => 1];
$up = FdbResolver::uplinkPortsByCount($manyHits, 5);
check('guess: Port mit 6 MACs = Uplink',   isset($up['core']['Te1/0/1']), true);
check('guess: Port mit 1 MAC kein Uplink', isset($up['acc']['Gi1/0/8']),  false);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "✖ {$failures} Fehlschlag(e).\n");
    exit(1);
}
echo "✓ FdbResolver: Zugangsport, Mehrdeutigkeit, Fallback, Uplink-Schätzung.\n";
