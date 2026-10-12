<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

/**
 * Unit-Test fuer Topology\ServiceTree::build() — die REINE Umwandlung des
 * service.get-Ergebnisses in {nodes, edges} fuer den Dependencies-Tab.
 *
 * Der API-Aufruf selbst liegt in actions/NetworkTopologyServices.php und ist
 * hier bewusst nicht getestet; die Logik, die schiefgehen kann, ist rein:
 *   - Status-Normalisierung: -1 (OK) -> 0, >5 geklemmt, sonst durchgereicht
 *   - gerichtete Kanten Eltern->Kind, dedupliziert
 *   - eine Kante auf ein NICHT bekanntes Kind (Permission-gefiltert) faellt weg
 *   - problems = Anzahl problemevents
 *
 * Aufruf:  php tests/ServiceTreeTest.php
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

use Modules\NetworkTopology\Topology\ServiceTree;

$failures = 0;

function check(string $what, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %-58s got=%-14s want=%s\n",
        $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

// Baum: Online-Shop (OK, 3 Kinder) -> Webserver (OK), PHP-FPM (High+Problem),
// Datenbank (OK). Zusaetzlich: ein Kind-Verweis auf "99" (nicht im Ergebnis =
// Permission-gefiltert) und ein DOPPELTER Kind-Verweis auf "2".
$services = [
    ['serviceid' => '1', 'name' => 'Online-Shop', 'status' => '-1', 'children' => [
        ['serviceid' => '2'], ['serviceid' => '3'], ['serviceid' => '4'],
        ['serviceid' => '2'],            // Duplikat -> nur eine Kante
        ['serviceid' => '99'],           // unbekannt -> keine Kante
    ]],
    ['serviceid' => '2', 'name' => 'Webserver',  'status' => '-1', 'children' => []],
    ['serviceid' => '3', 'name' => 'PHP-FPM',    'status' => '4',
        'problemevents' => [['eventid' => '500']], 'children' => []],
    ['serviceid' => '4', 'name' => 'Datenbank',  'status' => '-1', 'children' => []],
];

$g = ServiceTree::build($services);
$nodes = $g['nodes'];
$edges = $g['edges'];

// Index Knoten nach id fuer gezielte Checks.
$byId = [];
foreach ($nodes as $n) { $byId[$n['id']] = $n; }

echo "\nKnoten\n";
check('vier Knoten',                         count($nodes), 4);
check('id ist svc<serviceid>',               $byId['svc1']['id'] ?? null, 'svc1');
check('Label durchgereicht',                 $byId['svc1']['label'] ?? null, 'Online-Shop');
check('isService-Flag gesetzt',              $byId['svc1']['isService'] ?? null, true);

echo "\nStatus-Normalisierung\n";
check('OK (-1) wird Severity 0',             $byId['svc1']['severity'] ?? null, 0);
check('Problem-Severity 4 durchgereicht',    $byId['svc3']['severity'] ?? null, 4);
check('problems = Anzahl problemevents',     $byId['svc3']['problems'] ?? null, 1);
check('ohne problemevents -> 0',             $byId['svc2']['problems'] ?? null, 0);

echo "\nKanten (gerichtet Eltern->Kind, dedupliziert, bekannte Ziele)\n";
check('genau drei Kanten (Dup + Unbekannt weg)', count($edges), 3);
// Kanten als "source>target"-Set pruefen (Reihenfolge egal).
$set = [];
foreach ($edges as $e) { $set[$e['source'] . '>' . $e['target']] = $e['kind'] ?? null; }
check('1->2 vorhanden',                      $set['svc1>svc2'] ?? null, 'depends');
check('1->3 vorhanden',                      $set['svc1>svc3'] ?? null, 'depends');
check('1->4 vorhanden',                      $set['svc1>svc4'] ?? null, 'depends');
check('keine Kante auf unbekanntes 99',      isset($set['svc1>svc99']), false);

echo "\nRandfall: leeres Ergebnis\n";
$empty = ServiceTree::build([]);
check('leer -> keine Knoten',                count($empty['nodes']), 0);
check('leer -> keine Kanten',                count($empty['edges']), 0);

echo "\nparseWebKey\n";
$pk = ServiceTree::parseWebKey('web.test.time[Shop,Home,resp]');
check('metric = time',                       $pk['metric'] ?? null, 'time');
check('scenario = Shop',                     $pk['scenario'] ?? null, 'Shop');
check('step = Home',                         $pk['step'] ?? null, 'Home');
check('field = resp',                        $pk['field'] ?? null, 'resp');
check('fail-Key -> metric fail',             ServiceTree::parseWebKey('web.test.fail[Shop]')['metric'] ?? null, 'fail');
check('Nicht-web-Key -> null',               ServiceTree::parseWebKey('system.cpu.util'), null);

echo "\naggregateWebItems\n";
$witems = [
    ['key_' => 'web.test.fail[Shop]',            'lastvalue' => '0'],
    ['key_' => 'web.test.time[Shop,Home,resp]',  'lastvalue' => '0.12'],
    ['key_' => 'web.test.time[Shop,Login,resp]', 'lastvalue' => '0.08'],
    ['key_' => 'web.test.in[Shop,,bps]',         'lastvalue' => '99999'],
];
$wagg = ServiceTree::aggregateWebItems($witems);
check('Shop ok (fail=0)',                    $wagg['Shop']['ok'] ?? null, true);
check('resp_ms = Summe (0.12+0.08 -> 200)',  $wagg['Shop']['resp_ms'] ?? null, 200);
$wdown = ServiceTree::aggregateWebItems([['key_' => 'web.test.fail[Shop]', 'lastvalue' => '2']]);
check('fail!=0 -> ok=false',                 $wdown['Shop']['ok'] ?? null, false);

echo "\nmergeExtras\n";
$mnodes = [
    ['id' => 'svc1', 'serviceid' => '1', 'label' => 'A'],
    ['id' => 'svc2', 'serviceid' => '2', 'label' => 'B'],
];
$merged = ServiceTree::mergeExtras(
    $mnodes,
    ['1' => ['name' => 'Shop SLA', 'slo' => 99.9, 'sli' => 99.95]],
    ['2' => ['ok' => true, 'resp_ms' => 120]]
);
check('SLA an svc1',                         $merged[0]['sla']['sli'] ?? null, 99.95);
check('kein SLA an svc2',                    isset($merged[1]['sla']), false);
check('Web an svc2',                         $merged[1]['web']['resp_ms'] ?? null, 120);
check('kein Web an svc1',                    isset($merged[0]['web']), false);

echo "\nStatus > 5 wird auf 5 geklemmt\n";
$clamp = ServiceTree::build([['serviceid' => '1', 'name' => 'x', 'status' => '7', 'children' => []]]);
check('status 7 -> severity 5', $clamp['nodes'][0]['severity'] ?? null, 5);

echo "\nMAX_NODES: keine Kante auf einen nicht-emittierten Knoten\n";
// Service 1 (im Limit) verweist auf ein Kind, das weit ueber MAX_NODES (2000)
// liegt und darum NICHT emittiert wird. Frueher entstand dadurch eine Kante ins
// Leere (svc1 -> svc9001) → Cytoscape wirft. Jetzt darf keine Kante entstehen.
$big = [['serviceid' => '1', 'name' => 'root', 'status' => '-1', 'children' => [['serviceid' => '9001']]]];
for ($i = 2; $i <= 2005; $i++) {
    $big[] = ['serviceid' => (string) $i, 'name' => 's' . $i, 'status' => '-1', 'children' => []];
}
$big[] = ['serviceid' => '9001', 'name' => 'late', 'status' => '-1', 'children' => []];
$bg = ServiceTree::build($big);
check('Knoten bei MAX_NODES gekappt (2000)', count($bg['nodes']), 2000);
check('keine Kante (Kind lag ueber dem Limit)', count($bg['edges']), 0);

echo "\naggregateWebItems: Tri-State bei fehlendem Messwert\n";
$wnull = ServiceTree::aggregateWebItems([['key_' => 'web.test.fail[Shop]', 'lastvalue' => null]]);
check('fail ohne Wert -> ok bleibt null (unbekannt, nicht down)', $wnull['Shop']['ok'], null);
check('kein resp-Item -> resp_ms null', $wnull['Shop']['resp_ms'], null);

echo "\n", $failures === 0
    ? "=== ALLE TESTS PASS ===\n"
    : "=== {$failures} TEST(S) FEHLGESCHLAGEN ===\n";

exit($failures === 0 ? 0 : 1);
