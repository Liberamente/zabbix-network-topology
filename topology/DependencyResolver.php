<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * Build a separate, directed connectivity-dependency graph from explicitly
 * confirmed relations. Never convert FDB/ARP observations into dependencies.
 * Caller must supply only hosts visible in the current topology scope.
 *
 * This class does not change topology edges, Zabbix triggers or stored tags.
 */
final class DependencyResolver {
    /**
     * @param array $hosts Visible hostid => host rows (with proxyid).
     * @param array $declared Explicit administrator-confirmed relations:
     *   ['upstream' => hostid, 'downstream' => hostid, 'type' => 'connectivity',
     *    'source' => 'manual-confirmed']
     * @return array Validated, directed relationships (not Cytoscape edges).
     */
    public static function resolve(array $hosts, array $declared): array {
        $result = [];
        $graph = [];
        $seen = [];
        foreach ($declared as $relation) {
            if (!is_array($relation)) continue;
            $from = (string) ($relation['upstream'] ?? '');
            $to = (string) ($relation['downstream'] ?? '');
            if ($from === '' || $to === '' || $from === $to
                    || !isset($hosts[$from], $hosts[$to])) continue;
            if (($relation['type'] ?? '') !== 'connectivity'
                    || ($relation['source'] ?? '') !== 'manual-confirmed') continue;
            $a = (string) ($hosts[$from]['proxyid'] ?? '0');
            $b = (string) ($hosts[$to]['proxyid'] ?? '0');
            if ($a === '0' || $b === '0' || $a !== $b) continue;
            $key = $from . ':' . $to;
            if (isset($seen[$key])) continue;
            // A confirmed dependency must never create a directed cycle.
            if (self::reachable($graph, $to, $from)) continue;
            $seen[$key] = true;
            $graph[$from][$to] = true;
            $result[] = [
                'upstream' => $from,
                'downstream' => $to,
                'type' => 'connectivity',
                'source' => 'manual-confirmed',
                'confirmed' => true
            ];
        }
        return $result;
    }

    private static function reachable(array $graph, string $start, string $target): bool {
        $pending = [$start];
        $visited = [];
        while ($pending) {
            $current = array_pop($pending);
            if ($current === $target) return true;
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            foreach ($graph[$current] ?? [] as $next => $_) {
                $pending[] = (string) $next;
            }
        }
        return false;
    }
}
