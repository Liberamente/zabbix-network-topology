<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

use InvalidArgumentException;

/**
 * Strict offline bridge: zabbix-tools logical_links -> Network Topology
 * nodes/edges contract. No API calls, file reads or production publication.
 *
 * The input is an independently verified report, NOT an instruction to
 * install or merge edges into an active Zabbix map. Every field is checked;
 * a partial or inconsistent report is rejected instead of drawing a guess.
 */
final class VerifiedTrunkAdapter {
    private const MAX_LINKS = 500;
    private const MAX_MEMBERS = 64;
    private const MAX_REMOTE_AGE = 900;
    private const MAX_FDB_AGE = 1800;
    private const MAX_DYNAMIC_SKEW = 120;

    /** @param array<int|string> $visibleHostids */
    public static function preview(array $report, array $visibleHostids,
            string $groupid): array {
        self::assertThat(
            ($report['schema'] ?? null) === 2
                && ($report['publication_allowed'] ?? null) === false,
            'unsupported or publishable report');
        self::assertThat(self::hostId($groupid) !== null, 'invalid expected groupid');
        $groups = $report['groupids'] ?? null;
        self::assertThat(is_array($groups) && count($groups) === 1
            && (string) reset($groups) === $groupid,
            'report host-group scope does not match the expected group');
        $captured = self::positiveInt($report['generated_at'] ?? null);
        self::assertThat($captured !== null, 'invalid snapshot generated_at');

        $allowed = [];
        foreach ($visibleHostids as $hid) {
            $id = self::hostId($hid);
            self::assertThat($id !== null && !isset($allowed[$id]),
                'invalid or duplicate visible host ID');
            $allowed[$id] = true;
        }
        self::assertThat(count($allowed) >= 2 && count($allowed) <= 5000,
            'visible host scope missing or too broad');

        $logical = $report['logical_links'] ?? null;
        $issues = $report['logical_link_issues'] ?? null;
        $summary = $report['logical_link_summary'] ?? null;
        self::assertThat(is_array($logical) && array_is_list($logical)
            && count($logical) <= self::MAX_LINKS
            && is_array($issues) && $issues === []
            && is_array($summary), 'logical link evidence missing or has exclusions');
        self::assertThat(
            self::nonNegativeInt($summary['logical_links_total'] ?? null) === count($logical)
            && self::nonNegativeInt($summary['complete_static_trunk_links'] ?? null)
                === count($logical)
            && self::nonNegativeInt($summary['direct_physical_links'] ?? null) === 0
            && self::nonNegativeInt($summary['excluded_link_diagnostics'] ?? null) === 0,
            'logical link summary mismatches input');

        $nodes = [];
        $edges = [];
        $seenLinks = [];
        $usedAggregates = [];
        $usedPorts = [];
        $physicalCount = 0;
        foreach ($logical as $link) {
            self::assertThat(is_array($link)
                && ($link['kind'] ?? '') === 'static_trunk'
                && ($link['evidence_status'] ?? '')
                    === 'reciprocal_lldp_and_verified_static_membership'
                && ($link['publication_allowed'] ?? null) === false,
                'invalid or non-static logical link');
            $ends = $link['endpoints'] ?? null;
            self::assertThat(is_array($ends) && count($ends) === 2
                && array_is_list($ends), 'invalid logical endpoints');
            [$left, $right] = $ends;
            $a = self::aggregate($left, $allowed);
            $b = self::aggregate($right, $allowed);
            self::assertThat($a['hostid'] !== $b['hostid'],
                'self-linked switch is forbidden');
            $keyA = $a['hostid'] . '/' . $a['ifindex'];
            $keyB = $b['hostid'] . '/' . $b['ifindex'];
            self::assertThat(strcmp($keyA, $keyB) < 0,
                'aggregate endpoints must be canonical');
            $linkId = 'trunk:' . $keyA . '--' . $keyB;
            self::assertThat(($link['link_id'] ?? null) === $linkId
                && !isset($seenLinks[$linkId])
                && !isset($usedAggregates[$keyA], $usedAggregates[$keyB]),
                'duplicate or inconsistent aggregate link');
            $seenLinks[$linkId] = true;
            $usedAggregates[$keyA] = true;
            $usedAggregates[$keyB] = true;
            foreach ([$a, $b] as $end) {
                $hid = $end['hostid'];
                if (isset($nodes[$hid])) {
                    self::assertThat($nodes[$hid]['label'] === $end['name'],
                        'conflicting visible switch identity');
                } else {
                    $nodes[$hid] = [
                        'id' => $hid, 'label' => $end['name'], 'host' => '',
                        'type' => 'switch', 'ping' => null,
                        'severity' => null, 'problems' => null,
                        'nt_offline_preview' => true,
                    ];
                }
            }
            $pairs = $link['physical_member_links'] ?? null;
            $count = self::positiveInt($link['physical_member_count'] ?? null);
            self::assertThat(is_array($pairs) && array_is_list($pairs)
                && $count !== null && count($pairs) === $count
                && $count >= 2 && $count <= self::MAX_MEMBERS
                && count($a['members']) === $count
                && count($b['members']) === $count,
                'incomplete logical/physical trunk count');
            $mappedA = [];
            $mappedB = [];
            $members = [];
            foreach ($pairs as $pair) {
                self::assertThat(is_array($pair)
                    && ($pair['evidence'] ?? '') === 'reciprocal_lldp_ports'
                    && ($pair['publication_allowed'] ?? null) === false
                    && is_array($pair['endpoints'] ?? null)
                    && count($pair['endpoints']) === 2,
                    'invalid reciprocal physical pair');
                $clockList = [];
                $ports = [];
                $labels = [];
                foreach ([$a, $b] as $side => $agg) {
                    $p = $pair['endpoints'][$side] ?? null;
                    self::assertThat(is_array($p)
                        && self::hostId($p['hostid'] ?? null) === $agg['hostid'],
                        'physical member points outside its aggregate');
                    $ix = self::positiveInt($p['ifindex'] ?? null);
                    self::assertThat($ix !== null
                        && isset($agg['members'][$ix])
                        && ($ix !== $agg['ifindex']),
                        'unknown physical member ifIndex');
                    $portNo = self::positiveInt($p['local_port_num'] ?? null);
                    $oid = $p['lldp_index'] ?? '';
                    self::assertThat($portNo !== null && is_string($oid)
                        && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $oid) === 1,
                        'missing native LLDP member index');
                    $parts = explode('.', $oid);
                    self::assertThat((int) $parts[1] === $portNo,
                        'LLDP native port number disagrees with member');
                    $lldpClock = self::positiveInt($p['lldp_clock'] ?? null);
                    $fdbClock = self::positiveInt($p['fdb_clock'] ?? null);
                    self::assertThat($lldpClock !== null && $fdbClock !== null
                        && $lldpClock <= $captured && $fdbClock <= $captured
                        && $captured - $lldpClock <= self::MAX_REMOTE_AGE
                        && $captured - $fdbClock <= self::MAX_FDB_AGE,
                        'stale or future reciprocal member evidence');
                    $itemIds = $p['source_itemids'] ?? null;
                    self::assertThat(is_array($itemIds) && count($itemIds) > 0,
                        'physical LLDP source missing');
                    foreach ($itemIds as $itemid) {
                        self::assertThat(self::hostId($itemid) !== null,
                            'invalid LLDP source itemid');
                    }
                    $portKey = $agg['hostid'] . '/' . $ix;
                    self::assertThat(!isset($usedPorts[$portKey]),
                        'physical member reused by two links');
                    $usedPorts[$portKey] = true;
                    $clockList[] = $lldpClock;
                    $clockList[] = $fdbClock;
                    $ports[$agg['hostid']] = (string) $ix;
                    $labels[$agg['hostid']] = $agg['members'][$ix];
                    if ($side === 0) {
                        $mappedA[$ix] = true;
                    } else {
                        $mappedB[$ix] = true;
                    }
                }
                $spread = max($clockList) - min($clockList);
                self::assertThat($spread <= self::MAX_DYNAMIC_SKEW
                    && self::nonNegativeInt($pair['dynamic_skew_seconds'] ?? null)
                        === $spread,
                    'dynamic evidence exceeds verified 120s gate');
                $members[] = [
                    'ports' => $labels, 'port_idx' => $ports,
                    'lldp_index' => [
                        $a['hostid'] => $pair['endpoints'][0]['lldp_index'],
                        $b['hostid'] => $pair['endpoints'][1]['lldp_index'],
                    ],
                    'last_seen' => max($clockList),
                    'dynamic_skew_seconds' => $spread,
                ];
            }
            self::assertThat(count($mappedA) === $count
                && count($mappedB) === $count
                && array_diff_key($a['members'], $mappedA) === []
                && array_diff_key($b['members'], $mappedB) === [],
                'physical members do not cover entire trunk on both sides');
            $physicalCount += $count;
            $edges[] = [
                'id' => 'ntv_' . $a['hostid'] . '_' . $a['ifindex']
                       . '__' . $b['hostid'] . '_' . $b['ifindex'],
                'from' => $a['hostid'], 'to' => $b['hostid'],
                'iface' => 'nt:verified-static-trunk',
                'src' => ['lldp'], 'reporters' => [$a['hostid'], $b['hostid']],
                'confirmed' => true, 'match' => 'exact',
                'port_match' => 'exact',
                'ports' => [
                    $a['hostid'] => $a['ifname'],
                    $b['hostid'] => $b['ifname'],
                ],
                'port_idx' => [
                    $a['hostid'] => (string) $a['ifindex'],
                    $b['hostid'] => (string) $b['ifindex'],
                ],
                'port_metrics' => [],
                'nt_verified_static_trunk' => true,
                'nt_members' => $members,
                'nt_member_count' => $count,
                'nt_evidence_link_id' => $linkId,
                'nt_offline_preview' => true,
            ];
        }
        self::assertThat(
            self::nonNegativeInt($summary['unique_reciprocal_physical_pairs'] ?? null)
                === $physicalCount
            && self::nonNegativeInt($summary['reciprocal_directional_observations'] ?? null)
                === 2 * $physicalCount,
            'reciprocal physical/directional summary inconsistent');

        usort($edges, static fn(array $a, array $b): int =>
            strcmp($a['id'], $b['id']));
        ksort($nodes, SORT_STRING);
        return [
            'schema' => 1,
            'preview_only' => true,
            'publication_allowed' => false,
            'live_node_status_available' => false,
            'source' => 'zabbix-tools/offline-logical-links',
            'groupid' => $groupid,
            'snapshot_generated_at' => $captured,
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'summary' => [
                'logical_links' => count($edges),
                'physical_member_pairs' => $physicalCount,
                'distinct_switches' => count($nodes),
            ],
        ];
    }

    private static function aggregate($input, array $allowed): array {
        self::assertThat(is_array($input)
            && ($input['vendor'] ?? '') === 'hpe_procurve'
            && ($input['mode'] ?? '') === 'static',
            'unsupported or non-static aggregate');
        $hid = self::hostId($input['hostid'] ?? null);
        $idx = self::positiveInt($input['logical_ifindex'] ?? null);
        $label = $input['logical_ifname'] ?? null;
        $name = $input['name'] ?? null;
        self::assertThat($hid !== null && isset($allowed[$hid])
            && $idx !== null && is_string($name) && $name !== ''
            && strlen($name) <= 256 && is_string($label)
            && preg_match('/^Trk[1-9][0-9]*$/D', $label) === 1,
            'out-of-scope or invalid aggregate endpoint');
        $items = $input['source_itemids'] ?? null;
        self::assertThat(is_array($items) && count($items) >= 2,
            'missing HPE aggregate source evidence');
        foreach ($items as $item) {
            self::assertThat(self::hostId($item) !== null,
                'invalid HPE source itemid');
        }
        $indices = $input['member_ifindexes'] ?? null;
        $labels = $input['member_port_labels'] ?? null;
        self::assertThat(is_array($indices) && is_array($labels)
            && count($indices) >= 2 && count($indices) <= self::MAX_MEMBERS,
            'missing aggregate member inventory');
        $members = [];
        foreach ($indices as $rawIndex) {
            $ix = self::positiveInt($rawIndex);
            self::assertThat($ix !== null && !isset($members[$ix])
                && isset($labels[(string) $ix])
                && is_scalar($labels[(string) $ix])
                && preg_match('/^[1-9][0-9]*$/D',
                    (string) $labels[(string) $ix]) === 1,
                'ambiguous member ifIndex or port label');
            $members[$ix] = (string) $labels[(string) $ix];
        }
        self::assertThat(count($members) === count($labels)
            && !isset($members[$idx]),
            'duplicate aggregate interface or extra member label');
        return [
            'hostid' => $hid, 'ifindex' => $idx,
            'ifname' => $label, 'name' => $name, 'members' => $members,
        ];
    }

    private static function hostId($value): ?string {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $s = (string) $value;
        return preg_match('/^[1-9][0-9]*$/D', $s) === 1 ? $s : null;
    }

    private static function positiveInt($value): ?int {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            return null;
        }
        $n = filter_var((string) $value, FILTER_VALIDATE_INT);
        return $n !== false && $n > 0 ? (int) $n : null;
    }

    private static function nonNegativeInt($value): ?int {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) {
            return null;
        }
        $n = filter_var((string) $value, FILTER_VALIDATE_INT);
        return $n !== false && $n >= 0 ? (int) $n : null;
    }

    private static function assertThat(bool $ok, string $message): void {
        if (!$ok) {
            throw new InvalidArgumentException($message);
        }
    }
}
