<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only view filter: keep only hosts with a fresh successful ICMP probe.
 *
 * The caller supplies ONLY hosts/items already scoped by the Zabbix API to
 * the viewer's authorized group(s) or hop selection. This helper never changes
 * Zabbix hosts, items, edges, monitoring, history or discovered identities.
 *
 * Unknown/unsupported/missing ping data is NOT equivalent to ping success.
 * "icmppingsec" measures latency and MUST NOT be used as a reachability flag.
 */
final class PingOnlyFilter
{
    public const MAX_AGE_SECONDS = 300;
    private const MAX_FUTURE_SKEW_SECONDS = 60;

    /**
     * @param array $nodes Visible Zabbix host nodes
     * @param array $edges Existing typed edges (LLDP/CDP, FDB, Wi-Fi, etc.)
     * @param array $icmpItems Scoped item.get rows for exact key "icmpping"
     * @return array{nodes:array, edges:array, summary:array}
     */
    public static function apply(
        array $nodes, array $edges, array $icmpItems, int $now
    ): array {
        $perHost = [];
        foreach ($icmpItems as $item) {
            if (!is_array($item) || (string) ($item['key_'] ?? '') !== 'icmpping') {
                continue;
            }
            $hostId = (string) ($item['hostid'] ?? '');
            if ($hostId === '' || !ctype_digit($hostId)) {
                continue;
            }
            $perHost[$hostId][] = $item;
        }

        $summary = [
            'source_key' => 'icmpping',
            'max_age_seconds' => self::MAX_AGE_SECONDS,
            'total_hosts' => count($nodes),
            'reachable_hosts' => 0,
            'excluded_ping_zero' => 0,
            'excluded_missing_item' => 0,
            'excluded_unusable_item' => 0,
            'relationships_shown' => 0
        ];
        $allowed = [];
        $keptNodes = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            $items = $perHost[$id] ?? [];

            if (count($items) === 0) {
                $summary['excluded_missing_item']++;
                continue;
            }
            // More than one probe for a host is ambiguous, never silently
            // select whichever one last happened to return "1".
            if (count($items) !== 1) {
                $summary['excluded_unusable_item']++;
                continue;
            }
            $item = $items[0];
            $clock = (int) ($item['lastclock'] ?? 0);
            if ((string) ($item['status'] ?? '-1') !== '0'
                    || (string) ($item['state'] ?? '-1') !== '0'
                    || $clock <= 0
                    || ($now - $clock) > self::MAX_AGE_SECONDS
                    || ($clock - $now) > self::MAX_FUTURE_SKEW_SECONDS) {
                $summary['excluded_unusable_item']++;
                continue;
            }

            if ((string) ($item['lastvalue'] ?? '') !== '1') {
                if ((string) ($item['lastvalue'] ?? '') === '0') {
                    $summary['excluded_ping_zero']++;
                }
                else {
                    $summary['excluded_unusable_item']++;
                }
                continue;
            }
            $allowed[$id] = true;
            $keptNodes[] = $node;
        }

        // Never draw a half-edge, resurrect a ghost, invent a link or promote
        // a MAC-learned association to a verified physical cable.
        $keptEdges = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? $edge['source'] ?? '');
            $to = (string) ($edge['to'] ?? $edge['target'] ?? '');
            if (isset($allowed[$from], $allowed[$to])) {
                $keptEdges[] = $edge; // preserve evidence, type, ports, members
            }
        }
        $summary['reachable_hosts'] = count($keptNodes);
        $summary['relationships_shown'] = count($keptEdges);

        return [
            'nodes' => $keptNodes,
            'edges' => $keptEdges,
            'summary' => $summary
        ];
    }
}
