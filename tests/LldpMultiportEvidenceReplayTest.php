<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Synthetic evidence only. No Zabbix API, network requests, customer data,
// template imports, or filesystem writes.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (!str_starts_with($class, $prefix)) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $dir = implode('/', array_map('strtolower', $parts));
    $path = dirname(__DIR__) . '/' . $dir . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;

$failures = 0;
function evidenceCheck(string $name, bool $ok): void {
    global $failures;
    if (!$ok) ++$failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . "\n";
}

$hosts = [
    '101' => ['host' => 'synthetic-switch-a', 'name' => 'synthetic-switch-a',
              'proxyid' => '900', 'tags' => [], 'interfaces' => []],
    '102' => ['host' => 'synthetic-switch-b', 'name' => 'synthetic-switch-b',
              'proxyid' => '900', 'tags' => [], 'interfaces' => []],
    '103' => ['host' => 'synthetic-switch-c', 'name' => 'synthetic-switch-c',
              'proxyid' => '900', 'tags' => [], 'interfaces' => []],
];

function observation(string $key, string $neighbor): array {
    return ['hostid' => '101', 'key_' => 'lldpRemSysName[' . $key . ']',
            'lastvalue' => $neighbor, 'src' => 'lldp'];
}
function replay(array $observations, array $ports = []): array {
    global $hosts;
    return LldpEdgeBuilder::build($hosts, $observations, ['101' => $ports]);
}

$onePort = [
    observation('0.12.1111111', 'synthetic-switch-b'),
    observation('0.12.2222222', 'synthetic-switch-b'),
];
$portEvidence = [
    '0.12.1111111' => ['id' => 'eth0', 'desc' => 'uplink-a'],
    '0.12.2222222' => ['id' => 'eth1', 'desc' => 'uplink-b'],
];

$result = replay($onePort, $portEvidence);
evidenceCheck('two Port-ID observations on one local port -> one pair edge',
    count($result['edges']) === 1);
$edge = $result['edges'][0] ?? [];
evidenceCheck('same-port duplicate is not reciprocally confirmed',
    ($edge['confirmed'] ?? null) === false);
evidenceCheck('only the actual reporter is recorded',
    array_map('strval', $edge['reporters'] ?? []) === ['101']);
evidenceCheck('one reported local physical port retained',
    (string) ($edge['ports']['101'] ?? '') === '12');

$reordered = replay(array_reverse($onePort), $portEvidence);
evidenceCheck('reordered same-port observations still yield one edge',
    count($reordered['edges']) === 1
    && ($reordered['edges'][0]['confirmed'] ?? null) === false);

$differentNeighbor = replay([
    observation('0.12.1111111', 'synthetic-switch-b'),
    observation('0.12.2222222', 'synthetic-switch-c'),
], $portEvidence);
evidenceCheck('two different advertised neighbors remain distinct evidence',
    count($differentNeighbor['edges']) === 2);
evidenceCheck('one-sided shared-port reports never confirm either link',
    count(array_filter($differentNeighbor['edges'],
        static fn(array $e): bool => ($e['confirmed'] ?? false) === true)) === 0);
evidenceCheck('shared local port is not fabricated into two distinct port IDs',
    count(array_unique(array_map(
        static fn(array $e): string => (string) ($e['ports']['101'] ?? ''),
        $differentNeighbor['edges']
    ))) === 1);

$twoPorts = replay([
    observation('0.12.1111111', 'synthetic-switch-b'),
    observation('0.13.3333333', 'synthetic-switch-b'),
], [
    '0.12.1111111' => ['id' => 'eth0'],
    '0.13.3333333' => ['id' => 'eth1'],
]);
evidenceCheck('distinct local ports preserve parallel physical evidence',
    count($twoPorts['edges']) === 2);
evidenceCheck('no reciprocal reporter -> no falsely verified trunk members',
    count(array_filter($twoPorts['edges'],
        static fn(array $e): bool => ($e['confirmed'] ?? false) === true)) === 0);
evidenceCheck('both distinct reporter ports preserved',
    array_unique(array_map(
        static fn(array $e): string => (string) ($e['ports']['101'] ?? ''),
        $twoPorts['edges']
    )) === ['12', '13']);

if ($failures > 0) {
    fwrite(STDERR, "LLDP_MULTIPORT_BACKEND_REPLAY=FAIL: {$failures}\n");
    exit(1);
}
echo "LLDP_MULTIPORT_BACKEND_REPLAY=PASS\n";
