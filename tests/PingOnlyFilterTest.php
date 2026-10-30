<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require_once __DIR__ . '/../topology/PingOnlyFilter.php';

use Modules\NetworkTopology\Topology\PingOnlyFilter;

const PING_NOW = 2000000000;

function pingCheck(bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
function pingItem(string $hostId, string $value, int $clock,
        string $status = '0', string $state = '0'): array {
    return [
        'hostid' => $hostId, 'key_' => 'icmpping',
        'status' => $status, 'state' => $state,
        'lastclock' => (string) $clock, 'lastvalue' => $value
    ];
}

$nodes = [];
foreach (range(1, 11) as $id) {
    $nodes[] = ['id' => (string) $id, 'label' => 'fixture-' . $id];
}
$edges = [
    ['id' => 'physical-1', 'from' => '1', 'to' => '2',
     'src' => ['lldp'], 'confirmed' => true, 'ports' => ['1' => 'GE1']],
    ['id' => 'physical-2', 'from' => '1', 'to' => '2',
     'src' => ['lldp'], 'confirmed' => true, 'ports' => ['1' => 'GE2']],
    ['id' => 'inferred-down', 'from' => '1', 'to' => '3', 'match' => 'fdb'],
    ['id' => 'radio', 'from' => '1', 'to' => '8',
     'match' => 'wifi', 'confirmed' => false, 'signal_dbm' => -58],
    ['id' => 'stale', 'from' => '1', 'to' => '5', 'stale' => true],
    ['id' => 'manual', 'from' => '2', 'to' => '1', '_type' => 'manual'],
    ['id' => 'inferred-up', 'from' => '2', 'to' => '8', 'match' => 'fdb',
     'confirmed' => false, 'fdb_shared_port' => true],
    ['id' => 'hidden-endpoint', 'from' => '1', 'to' => '9', 'src' => ['cdp']],
    ['id' => 'missing-foreign', 'from' => '1', 'to' => '999',
     'src' => ['lldp'], 'confirmed' => true]
];
$items = [
    pingItem('1', '1', PING_NOW - 30),
    pingItem('2', '1', PING_NOW - 300), // inclusive edge of freshness window
    pingItem('3', '0', PING_NOW - 10),
    // Host 4 has no ICMP item.
    pingItem('5', '1', PING_NOW - 301), // just outside window
    pingItem('6', '1', PING_NOW - 10, '1'),
    pingItem('7', '1', PING_NOW - 10),
    pingItem('7', '0', PING_NOW - 10), // conflict: fail closed
    pingItem('8', '1', PING_NOW + 60), // tolerated clock skew
    pingItem('9', '1', PING_NOW + 61), // excessive future skew
    pingItem('10', '1', PING_NOW - 10, '0', '1'),
    ['hostid' => '11', 'key_' => 'icmppingsec', 'lastvalue' => '0.003',
     'lastclock' => (string) PING_NOW, 'status' => '0', 'state' => '0'],
    pingItem('999', '1', PING_NOW - 5) // out of pre-scoped host set
];
$before = serialize([$nodes, $edges, $items]);
$out = PingOnlyFilter::apply($nodes, $edges, $items, PING_NOW);

pingCheck(serialize([$nodes, $edges, $items]) === $before,
    'Input arrays are not modified');
pingCheck(array_column($out['nodes'], 'id') === ['1', '2', '8'],
    'Only fresh positive icmpping host IDs remain');
pingCheck(array_column($out['edges'], 'id') ===
    ['physical-1', 'physical-2', 'radio', 'manual', 'inferred-up'],
    'Only edges between responding endpoints remain, including parallel cables');
pingCheck($out['edges'][0] === $edges[0] && $out['edges'][1] === $edges[1],
    'Physical port evidence and parallel members preserved unchanged');
pingCheck($out['edges'][2] === $edges[3] && $out['edges'][4] === $edges[6],
    'Wi-Fi source/RSSI and inferred FDB provenance preserved unchanged');
pingCheck($out['summary']['total_hosts'] === 11
    && $out['summary']['reachable_hosts'] === 3
    && $out['summary']['excluded_ping_zero'] === 1
    && $out['summary']['excluded_missing_item'] === 2
    && $out['summary']['excluded_unusable_item'] === 5,
    'Diagnostic counts distinguish down, missing and unusable observations');
pingCheck($out['summary']['relationships_shown'] === 5,
    'Edge count matches rendered source relationship list');

$allDown = PingOnlyFilter::apply(
    [['id' => '1'], ['id' => '2']],
    [['id' => 'edge', 'from' => '1', 'to' => '2']],
    [pingItem('1', '0', PING_NOW), pingItem('2', '0', PING_NOW)],
    PING_NOW
);
pingCheck(!$allDown['nodes'] && !$allDown['edges'],
    'No ping-positive hosts produces no visible edges');

$old = PingOnlyFilter::apply(
    [['id' => '1']], [],
    [pingItem('1', '1', PING_NOW - 86400)],
    PING_NOW
);
pingCheck($old['summary']['excluded_unusable_item'] === 1,
    'Stale positive ping never resurrects a host');

echo "PingOnlyFilterTest: all checks PASS\n";
