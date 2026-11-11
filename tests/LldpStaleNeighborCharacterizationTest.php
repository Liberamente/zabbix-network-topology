<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Characterization only: no Zabbix API, customer identifiers or network data.
// This test demonstrates that the current builder itself has no time cutoff.
// It does NOT assert that a historic row caused a particular production edge.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/'
        . implode('/', array_map('strtolower', $parts))
        . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;

$errors = 0;
function staleCheck(string $label, bool $ok): void {
    global $errors;
    if (!$ok) ++$errors;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}

$now = 1800000000;
$hosts = [
    '100' => ['host'=>'synthetic-core', 'name'=>'Synthetic core',
              'proxyid'=>'77', 'tags'=>[], 'interfaces'=>[]],
    '101' => ['host'=>'synthetic-a', 'name'=>'Synthetic A',
              'proxyid'=>'77', 'tags'=>[], 'interfaces'=>[]],
    '102' => ['host'=>'synthetic-b', 'name'=>'Synthetic B',
              'proxyid'=>'77', 'tags'=>[], 'interfaces'=>[]],
    '103' => ['host'=>'synthetic-c', 'name'=>'Synthetic C',
              'proxyid'=>'77', 'tags'=>[], 'interfaces'=>[]],
];
function neighborRow(string $key, string $name, int $clock, string $source): array {
    return [
        'hostid'=>'100',
        'key_'=>$key,
        'lastvalue'=>$name,
        'lastclock'=>$clock,
        'src'=>$source
    ];
}
$recentA = neighborRow('cdpCacheDeviceId[28.1]', 'synthetic-a', $now-1200, 'cdp');
$recentB = neighborRow('cdpCacheDeviceId[28.2]', 'synthetic-b', $now-1200, 'cdp');
$historicC = neighborRow('cdpCacheDeviceId[28.3]', 'synthetic-c', $now-80000, 'cdp');
$historicDuplicate = neighborRow('cdpCacheDeviceId[28.3]', 'synthetic-a', $now-80000, 'cdp');

$allThree = LldpEdgeBuilder::build($hosts, [$recentA, $recentB, $historicC]);
staleCheck('Raw three distinct CDP identities produce three links even with old lastclock',
    count($allThree['edges']) === 3);
staleCheck('No old row is silently treated as a confirmed reciprocal report',
    count(array_filter($allThree['edges'],
        static fn(array $edge): bool => ($edge['confirmed'] ?? false) === true)) === 0);

// A duplicate historic identity by itself must not be mistaken for a
// third physically distinct destination. Distinguish it from historicC.
$duplicated = LldpEdgeBuilder::build($hosts, [$recentA, $recentB, $historicDuplicate]);
staleCheck('Historical duplicate identity alone does not produce a third pair edge',
    count($duplicated['edges']) === 2);

// A *hypothetical* upstream 6h TTL would exclude historicC.  This is
// NOT applied to any production source; the test checks evidence only.
$filteredRows = array_values(array_filter([$recentA, $recentB, $historicC],
    static fn(array $row): bool =>
        (int) $row['lastclock'] > 0
        && (int) $row['lastclock'] >= $now-21600
        && (int) $row['lastclock'] <= $now+60
));
$filtered = LldpEdgeBuilder::build($hosts, $filteredRows);
staleCheck('Synthetic 6h prefilter would keep two recent identities',
    count($filtered['edges']) === 2);
staleCheck('Synthetic prefilter preserves both current reported destinations',
    count(array_unique(array_map(
        static fn(array $edge): string => (string) ($edge['to'] ?? ''),
        $filtered['edges']))) === 2);

echo $errors ? "STALE_NEIGHBOR_CHARACTERIZATION=FAIL ($errors)\n"
             : "STALE_NEIGHBOR_CHARACTERIZATION=PASS\n";
exit($errors ? 1 : 0);
