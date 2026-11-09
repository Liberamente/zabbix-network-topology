<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require_once __DIR__ . '/../topology/HostTopologyFilter.php';

use Modules\NetworkTopology\Topology\HostTopologyFilter;

function topoCheck(bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

$hosts = [
    '101' => ['hostid' => '101', 'host' => 'fixture-host-101', 'tags' => [
        ['tag' => 'nt:topology', 'value' => 'exclude']]],
    '102' => ['hostid' => '102', 'host' => 'fixture-host-102', 'tags' => [
        ['tag' => 'nt:topology', 'value' => 'include']]],
    '103' => ['hostid' => '103', 'host' => 'fixture-host-103', 'tags' => [
        ['tag' => 'nt:name-mode', 'value' => 'manual']]],
    '104' => ['hostid' => '104', 'host' => 'fixture-host-104', 'tags' => [
        ['tag' => 'nt:topology', 'value' => ' ExClUdE ']]],
    '105' => ['hostid' => '105', 'host' => 'fixture-host-105', 'tags' => [
        ['tag' => 'nt:topology', 'value' => 'excluded']]],
];
$original = serialize($hosts);
$excluded = HostTopologyFilter::excludedIds($hosts);
topoCheck($excluded === ['101' => true, '104' => true],
    'Only the exact nt:topology exclude tag hides a host');
topoCheck(serialize($hosts) === $original, 'Tag scan does not mutate Zabbix host records');

$nodes = [
    ['id' => '101', 'label' => 'hidden fixture'],
    ['id' => '102', 'label' => 'switch fixture'],
    ['id' => '103', 'label' => 'endpoint fixture'],
    ['id' => 'ghost_observed', 'label' => 'unmatched neighbor'],
];
$edges = [
    ['id' => 'lldp-hidden', 'from' => '101', 'to' => '102',
        'src' => ['lldp'], 'confirmed' => true, 'ports' => ['102' => 'GE8']],
    ['id' => 'fdb-kept', 'from' => '102', 'to' => '103',
        'match' => 'fdb', 'confirmed' => false, 'fdb_shared_port' => true],
    ['id' => 'wifi-hidden', 'from' => '103', 'to' => '101',
        'match' => 'wifi', 'signal_dbm' => -57],
    ['id' => 'trunk-a', 'from' => '102', 'to' => '103',
        'src' => ['lldp'], 'confirmed' => true, 'ports' => ['102' => 'GE1']],
    ['id' => 'trunk-b', 'from' => '102', 'to' => '103',
        'src' => ['lldp'], 'confirmed' => true, 'ports' => ['102' => 'GE2']],
    ['id' => 'manual-hidden', 'from' => '102', 'to' => '104', '_type' => 'manual'],
    ['id' => 'ghost-hidden-reporter', 'from' => '101', 'to' => 'ghost_observed'],
    ['id' => 'ghost-kept', 'from' => '102', 'to' => 'ghost_observed'],
    ['id' => 'stale-hidden', 'from' => '104', 'to' => '103', 'stale' => true],
];
$before = serialize([$nodes, $edges]);
$actual = HostTopologyFilter::apply($nodes, $edges, $excluded);
topoCheck(array_column($actual['nodes'], 'id') === ['102','103','ghost_observed'],
    'Hidden real hosts never appear as nodes; unrelated ghosts remain');
topoCheck(array_column($actual['edges'], 'id') ===
    ['fdb-kept', 'trunk-a', 'trunk-b', 'ghost-kept'],
    'All edges incident on hidden hosts are removed, including manual and stale');
topoCheck($actual['edges'][0] === $edges[1]
    && $actual['edges'][1] === $edges[3]
    && $actual['edges'][2] === $edges[4],
    'FDB confidence and distinct verified trunk member ports stay intact');
topoCheck(serialize([$nodes, $edges]) === $before, 'Filtering never modifies evidence arrays');

$noHidden = HostTopologyFilter::apply($nodes, $edges, []);
topoCheck($noHidden['nodes'] === $nodes && $noHidden['edges'] === $edges,
    'Without opt-in the output is unchanged');

$quality = [
    ['id' => '101', 'label' => 'hidden', 'matched' => 3],
    ['id' => '102', 'label' => 'visible', 'matched' => 2],
];
topoCheck(array_column(HostTopologyFilter::quality($quality, $excluded), 'id') === ['102'],
    'Quality diagnostics omit a hidden LLDP reporter');
$unmatched = [
    'excluded-neighbor (from hostid=101, src=lldp)',
    'visible-neighbor (from hostid=102, src=lldp)',
    'other-data',
];
topoCheck(HostTopologyFilter::unmatched($unmatched, $excluded) ===
    [$unmatched[1], $unmatched[2]],
    'Unmatched diagnostics do not resurrect a hidden reporter');

$links = [
    ['s' => '101','t' => '102'],
    ['s' => '102','t' => '103'],
    ['s' => '103','t' => '104'],
];
topoCheck(HostTopologyFilter::links($links, $excluded) === [$links[1]],
    'Hop-mode manual links never traverse excluded hosts');

$dataPhp = file_get_contents(__DIR__ . '/../actions/NetworkTopologyData.php');
topoCheck(strpos($dataPhp, 'HostTopologyFilter::excludedIds($hosts)') !== false
    && strpos($dataPhp, 'HostTopologyFilter::apply($nodes, $edges, $excludedHostIds)') !== false
    && strpos($dataPhp, 'HostTopologyFilter::links($links, $graph[') !== false,
    'Both group response and hop scope are wired into the visibility policy');

echo "HostTopologyFilterTest: all checks PASS\n";
