<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only host visibility policy for the rendered topology.
 *
 * Zabbix discovery, host monitoring, items, history, graphs and triggers
 * are never modified. A hidden host remains in the LLDP/CDP identity
 * resolver input until the links have been resolved, avoiding a new
 * phantom/ghost neighbor for an intentionally hidden real host.
 *
 * Only exact host tag nt:topology=exclude opts in. No customer IDs/IPs,
 * group/proxy bypasses or hidden-by-default behavior.
 */
final class HostTopologyFilter
{
    /** @return array<string,bool> hostid => true */
    public static function excludedIds(array $hosts): array
    {
        $excluded = [];
        foreach ($hosts as $key => $host) {
            if (!is_array($host)) {
                continue;
            }
            $hostid = (string) ($host['hostid'] ?? $key);
            if ($hostid === '' || !ctype_digit($hostid)) {
                continue;
            }
            foreach ($host['tags'] ?? [] as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                if (($tag['tag'] ?? '') === 'nt:topology'
                        && strtolower(trim((string) ($tag['value'] ?? ''))) === 'exclude') {
                    $excluded[$hostid] = true;
                    break;
                }
            }
        }
        return $excluded;
    }

    /** Keep the key-preserving host map without deliberately hidden hosts. */
    public static function visibleHosts(array $hosts, array $excluded): array
    {
        return $excluded ? array_diff_key($hosts, $excluded) : $hosts;
    }

    /** @return array{nodes:array,edges:array} */
    public static function apply(array $nodes, array $edges, array $excluded): array
    {
        if (!$excluded) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $keptNodes = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($excluded[(string) ($node['id'] ?? '')])) {
                $keptNodes[] = $node;
            }
        }

        $keptEdges = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                $keptEdges[] = $edge;
                continue;
            }
            $from = (string) ($edge['from'] ?? $edge['source'] ?? '');
            $to = (string) ($edge['to'] ?? $edge['target'] ?? '');
            if (isset($excluded[$from]) || isset($excluded[$to])) {
                continue;
            }
            // Never reinterpret LLDP, CDP, FDB, radio, manual, trunk/LAG
            // or stale evidence. Keep all non-excluded edges byte-for-byte.
            $keptEdges[] = $edge;
        }

        return ['nodes' => $keptNodes, 'edges' => $keptEdges];
    }

    /** Omit only the hidden reporting host's diagnostic row. */
    public static function quality(array $rows, array $excluded): array
    {
        if (!$excluded) {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($row) use ($excluded): bool {
            return !is_array($row) || !isset($excluded[(string) ($row['id'] ?? '')]);
        }));
    }

    /** LLDP unmatched strings name their reporter as "(from hostid=N, src=...)". */
    public static function unmatched(array $rows, array $excluded): array
    {
        if (!$excluded) {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($row) use ($excluded): bool {
            if (!is_string($row)) {
                return true;
            }
            return !preg_match('/\(from hostid=([0-9]+), src=/', $row, $m)
                || !isset($excluded[$m[1]]);
        }));
    }

    /** Manual hop-mode links cannot traverse a deliberately hidden host. */
    public static function links(array $links, array $excluded): array
    {
        if (!$excluded) {
            return $links;
        }
        return array_values(array_filter($links, static function ($link) use ($excluded): bool {
            if (!is_array($link)) {
                return false;
            }
            return !isset($excluded[(string) ($link['s'] ?? '')])
                && !isset($excluded[(string) ($link['t'] ?? '')]);
        }));
    }
}
