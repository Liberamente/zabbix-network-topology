<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Synthetic fixtures only. No real customer labels, IPs or Zabbix calls. */
require_once dirname(__DIR__) . '/topology/VerifiedTrunkAdapter.php';

use Modules\NetworkTopology\Topology\VerifiedTrunkAdapter;

$failures = 0;
$checks = 0;

function checkPreview(string $name, bool $condition): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $name\n");
    }
}

function expectReject(string $name, array $report,
        array $scope = ['1001','1002','1003','1004','1005'],
        string $group = '900'): void {
    try {
        VerifiedTrunkAdapter::preview($report, $scope, $group);
        checkPreview($name, false);
    } catch (InvalidArgumentException $ex) {
        checkPreview($name, true);
    }
}

function fixtureReport(): array {
    $now = 1800000000;
    // Five synthetic switches, four logical links, eight reciprocal pairs.
    $links = [
        ['1001','Trk1',[23,24], '1002','Trk1',[45,46]],
        ['1002','Trk2',[47,48], '1003','Trk1',[45,46]],
        ['1003','Trk2',[47,48], '1004','Trk2',[23,24]],
        ['1004','Trk1',[21,22], '1005','Trk1',[47,48]],
    ];
    $result = [];
    $logicalIndex = static fn(string $hostid, string $trunk): int =>
        (int) $hostid * 1000 + (int) substr($trunk, 3);
    $ifindex = static fn(string $hostid, int $port): int =>
        (int) $hostid * 100 + $port;
    foreach ($links as [$hostA,$trunkA,$portsA,$hostB,$trunkB,$portsB]) {
        $ends = [];
        foreach ([[$hostA,$trunkA,$portsA], [$hostB,$trunkB,$portsB]] as $end) {
            [$hid,$trunk,$ports] = $end;
            $mapping = [];
            $members = [];
            foreach ($ports as $port) {
                $ix = $ifindex($hid, $port);
                $mapping[(string) $ix] = (string) $port;
                $members[] = $ix;
            }
            $ends[] = [
                'hostid' => $hid,
                'name' => 'SW-' . $hid,
                'logical_ifindex' => $logicalIndex($hid, $trunk),
                'logical_ifname' => $trunk,
                'mode' => 'static',
                'vendor' => 'hpe_procurve',
                'member_ifindexes' => $members,
                'member_port_labels' => $mapping,
                'source_itemids' => ['90010', '90011'],
            ];
        }
        $pairs = [];
        foreach ($portsA as $i => $portA) {
            $portB = $portsB[$i];
            $pairs[] = [
                'endpoints' => [
                    ['hostid' => $hostA, 'ifindex' => $ifindex($hostA, $portA),
                     'local_port_num' => $portA,
                     'lldp_index' => '0.'.$portA.'.1',
                     'lldp_clock' => $now-65, 'fdb_clock' => $now-44,
                     'source_itemids' => ['90020']],
                    ['hostid' => $hostB, 'ifindex' => $ifindex($hostB, $portB),
                     'local_port_num' => $portB,
                     'lldp_index' => '0.'.$portB.'.2',
                     'lldp_clock' => $now-61, 'fdb_clock' => $now-48,
                     'source_itemids' => ['90021']],
                ],
                'evidence' => 'reciprocal_lldp_ports',
                'dynamic_skew_seconds' => 21,
                'publication_allowed' => false,
            ];
        }
        $result[] = [
            'link_id' => 'trunk:' . $hostA . '/' . $logicalIndex($hostA,$trunkA)
                       . '--' . $hostB . '/' . $logicalIndex($hostB,$trunkB),
            'kind' => 'static_trunk',
            'evidence_status' => 'reciprocal_lldp_and_verified_static_membership',
            'endpoints' => $ends,
            'physical_member_links' => $pairs,
            'physical_member_count' => 2,
            'publication_allowed' => false,
        ];
    }
    return [
        'schema' => 2, 'groupids' => ['900'],
        'generated_at' => $now,
        'logical_links' => $result,
        'logical_link_issues' => [],
        'logical_link_summary' => [
            'reciprocal_directional_observations' => 16,
            'unique_reciprocal_physical_pairs' => 8,
            'complete_static_trunk_links' => 4,
            'direct_physical_links' => 0,
            'logical_links_total' => 4,
            'excluded_link_diagnostics' => 0,
        ],
        'publication_allowed' => false,
        'results' => [
            ['classification' => 'candidate_unverified', 'ip' => '198.51.100.99'],
        ],
    ];
}

$input = fixtureReport();
$scope = ['1001','1002','1003','1004','1005'];
$output = VerifiedTrunkAdapter::preview($input, $scope, '900');
checkPreview('five nodes', count($output['nodes']) === 5);
checkPreview('four logical edges', count($output['edges']) === 4);
checkPreview('eight physical pairs remain inside four edges',
    array_sum(array_column($output['edges'], 'nt_member_count')) === 8);
checkPreview('all four edges describe static verified trunks',
    count(array_filter($output['edges'],
        static fn($e) => ($e['nt_verified_static_trunk'] ?? null) === true
            && ($e['confirmed'] ?? null) === true)) === 4);
checkPreview('edge IDs are unique',
    count(array_unique(array_column($output['edges'], 'id'))) === 4);
checkPreview('module API endpoints use hostids',
    ($output['edges'][0]['from'] ?? '') === '1001'
        && ($output['edges'][0]['to'] ?? '') === '1002');
checkPreview('module API ports use the logical Trk names',
    ($output['edges'][0]['ports']['1001'] ?? null) === 'Trk1'
        && ($output['edges'][0]['ports']['1002'] ?? null) === 'Trk1');
checkPreview('physical evidence stays in member details',
    ($output['edges'][0]['nt_members'][0]['ports']['1001'] ?? '') === '23'
        && ($output['edges'][0]['nt_members'][0]['ports']['1002'] ?? '') === '45');
checkPreview('node statuses are unknown, not invented healthy',
    count(array_filter($output['nodes'],
        static fn($n) => array_key_exists('severity', $n)
            && $n['severity'] === null
            && array_key_exists('ping', $n) && $n['ping'] === null)) === 5);
checkPreview('never publish',
    $output['publication_allowed'] === false
        && $output['preview_only'] === true);
checkPreview('does not promote IP-to-port candidates',
    strpos(json_encode($output), '198.51.100.99') === false);
checkPreview('same report yields stable output',
    VerifiedTrunkAdapter::preview($input, $scope, '900') === $output);
checkPreview('no cross group or unscoped nodes emitted',
    $output['groupid'] === '900'
        && count(array_unique(array_column($output['nodes'], 'id'))) === 5);

$bad = fixtureReport(); $bad['publication_allowed'] = true;
expectReject('publishable report', $bad);
$bad = fixtureReport(); unset($bad['publication_allowed']);
expectReject('missing publication guard', $bad);
$bad = fixtureReport(); $bad['groupids'] = ['900','901'];
expectReject('multi-group report not silently scoped', $bad);
expectReject('wrong group selection', fixtureReport(), $scope, '901');
expectReject('out-of-scope switch', fixtureReport(), array_slice($scope,0,4));
$bad = fixtureReport(); $bad['logical_link_issues'][] = ['reason'=>'partial'];
expectReject('diagnostic exclusions block entire preview', $bad);
$bad = fixtureReport(); array_pop($bad['logical_links'][0]['physical_member_links']);
expectReject('missing one physical member', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['endpoints'][0]['local_port_num'] = 24;
expectReject('SNMP index does not match local port', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['endpoints'][0]['ifindex'] = 0;
expectReject('zero/invalid ifIndex', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['endpoints'][0]['lldp_clock'] -= 1000;
expectReject('stale LLDP read cannot become a current link', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['endpoints'][0]['fdb_clock'] -= 130;
expectReject('cross-endpoint skew more than 120s', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['dynamic_skew_seconds'] = 0;
expectReject('faked evidence skew metadata', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['publication_allowed'] = true;
expectReject('per-edge publication guard must be false', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['endpoints'][0]['mode'] = 'lacp';
expectReject('unverified vendor LACP is not a static HPE trunk', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['endpoints'][0]['logical_ifname'] = 'Interface23';
expectReject('unknown aggregation name', $bad);
$bad = fixtureReport(); $bad['logical_links'][1]['endpoints'][0]['name'] = 'Conflicting name';
expectReject('conflicting identity for same host', $bad);
$bad = fixtureReport(); $bad['logical_link_summary']['complete_static_trunk_links'] = 3;
expectReject('contradictory count summary', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['physical_member_links'][0]['endpoints'][0]['source_itemids'] = [];
expectReject('missing LLDP evidence item ID', $bad);
$bad = fixtureReport(); $bad['logical_links'][0]['link_id'] = 'manually-edited';
expectReject('noncanonical duplicate-prone link ID', $bad);

printf("VerifiedTrunkAdapter: %d checks, %d failures\n", $checks, $failures);
exit($failures > 0 ? 1 : 0);
