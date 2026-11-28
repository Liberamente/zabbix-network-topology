<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Attach scoped topology observations to inventory interfaces.
 * LLDP local port numbers are NOT inherently IF-MIB indexes.
 * No new edge, physical connector, traffic metric or confirmation is created.
 */
final class PortNeighborMap {
    public static function attach(array $ports, string $hostid, array $visibleHosts,
            array $edges, array $localPortMap = []): array {
        if (!isset($visibleHosts[$hostid])) return [];
        $names = [];
        foreach ($ports as $i => $port) {
            $ports[$i]['neighbors'] = [];
            $name = strtolower(trim((string) ($port['fields']['if_name']['value'] ?? '')));
            if ($name !== '') $names[$name][] = $i;
        }
        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from === $to || ($from !== $hostid && $to !== $hostid)
                    || !isset($visibleHosts[$from], $visibleHosts[$to])) continue;
            $neighbor = $from === $hostid ? $to : $from;
            $src = $edge['src'] ?? [];
            if (!is_array($src)) continue;
            $match = (string) ($edge['match'] ?? '');
            if (!empty($edge['stale']) || !empty($edge['_isGhostEdge'])
                    || !empty($edge['_isBundleEdge']) || !empty($edge['_isInternetEdge'])
                    || in_array($match, ['pass_through','pass_through_inferred','wifi'], true)
                    || in_array('wifi', $src, true)) continue;
            if (!array_intersect($src, ['lldp','cdp','fdb','arp'])) continue;
            $label = strtolower(trim((string) ($edge['ports'][$hostid] ?? '')));
            $rawIndex = (string) ($edge['port_idx'][$hostid] ?? '');
            // Reporter index in the legacy graph can be LLDP localPortNum.
            // Resolve it only through the explicit local-port mapping.
            $mapped = in_array('lldp', $src, true)
                ? strtolower(trim((string) ($localPortMap[$rawIndex] ?? ''))) : '';
            if ($mapped !== '') {
                if ($label !== '' && !ctype_digit($label) && $label !== $mapped) continue;
                $label = $mapped;
            }
            // Legacy graph labels can themselves be derived from an assumed
            // LLDP index. Require explicit mapping on an LLDP reporter.
            $reporters = array_map('strval', $edge['reporters'] ?? []);
            if (in_array('lldp', $src, true) && in_array($hostid, $reporters, true)
                    && $mapped === '' && $match !== 'lldp_portid') continue;
            if ($label === '' || ctype_digit($label) || count($names[$label] ?? []) !== 1) continue;
            $i = $names[$label][0];
            $verified = ($edge['confirmed'] ?? false) === true
                && (bool) array_intersect($src, ['lldp','cdp'])
                && !array_intersect($src, ['fdb','arp'])
                && $match !== 'lldp_portid';
            $ports[$i]['neighbors'][] = [
                'hostid'=>$neighbor,'edge_id'=>(string) ($edge['id'] ?? ''),
                'sources'=>array_values($src),'match'=>$match,
                'edge_confirmed'=>$verified,'confidence'=>$edge['confidence'] ?? null,
                'port_mapping'=>$mapped !== '' ? 'explicit_lldp_local_name' : 'unique_ifname',
                'shared_port'=>!empty($edge['fdb_shared_port']) || !empty($edge['shared_reporter_port'])
            ];
        }
        return $ports;
    }
}
