<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Pure adapter from scoped Zabbix item rows to LLDP Port-ID
 * candidates. It does not access Zabbix, SNMP or the database.
 *
 * All hosts and input items must already be visibility-scoped.
 * FDB decoding is delegated to the existing FdbParser.
 */
final class LldpPortIdFeed {
    private const FDB_TTL = 900;
    private const CLOCK_SKEW = 300;

    public static function append(
        array $hosts,
        array $lldpItems,
        array $fdbItems,
        array $localItems,
        array $edges,
        int $now
    ): array {
        if (!$hosts || !$lldpItems || !$fdbItems || !$localItems) {
            return $edges;
        }

        $walkKeys = [
            'nt.fdb.dot1d' => 'dot1d',
            'nt.fdb.dot1q' => 'dot1q',
            'nt.fdb.baseport' => 'baseport',
            'nt.fdb.ifname' => 'ifname',
        ];

        $walks = [];
        $conflicts = [];
        $arpRows = [];

        foreach ($fdbItems as $item) {
            $hid = (string) ($item['hostid'] ?? '');
            $key = (string) ($item['key_'] ?? '');

            if (!isset($hosts[$hid])) {
                continue;
            }

            if ($key === 'nt.arp') {
                $arpRows[] = $item;
                continue;
            }

            if (!isset($walkKeys[$key])
                    || !self::validFdbItem($item, $now)) {
                continue;
            }

            $role = $walkKeys[$key];
            $value = (string) ($item['lastvalue'] ?? '');

            // Fail closed on conflicting active walks.
            if (isset($walks[$hid][$role])
                    && $walks[$hid][$role]['value'] !== $value) {
                $conflicts[$hid] = true;
            }

            $walks[$hid][$role] = [
                'value' => $value,
                'clock' => (int) $item['lastclock'],
            ];
        }

        $byHost = [];

        foreach ($walks as $hid => $walk) {
            if (isset($conflicts[$hid])
                    || !isset($walk['baseport'], $walk['ifname'])
                    || (!isset($walk['dot1d'])
                        && !isset($walk['dot1q']))) {
                continue;
            }

            $byHost[$hid] = [
                'dot1d' => $walk['dot1d']['value'] ?? '',
                'dot1q' => $walk['dot1q']['value'] ?? '',
                'baseport' => $walk['baseport']['value'],
                'ifname' => $walk['ifname']['value'],
                'ts' => max(
                    $walk['dot1d']['clock'] ?? 0,
                    $walk['dot1q']['clock'] ?? 0
                ),
            ];
        }

        if (!$byHost || !$arpRows) {
            return $edges;
        }

        $hits = FdbParser::parseHostMap($byHost);
        if (!$hits) {
            return $edges;
        }

        $portMap = LldpPortIdIdentity::localPortMap(
            $hosts,
            array_merge($fdbItems, $localItems),
            $now
        );

        if (!$portMap) {
            return $edges;
        }

        $candidates = LldpPortIdIdentity::candidates(
            $hosts, $lldpItems, $arpRows, $hits, $portMap, $now
        );

        return LldpPortIdIdentity::appendEdges(
            $hosts, $candidates, $edges
        );
    }

    private static function validFdbItem(array $item, int $now): bool {
        $ts = (int) ($item['lastclock'] ?? 0);

        return $ts > 0
            && $ts <= $now + self::CLOCK_SKEW
            && ($now - $ts) <= self::FDB_TTL
            && (string) ($item['status'] ?? '0') === '0'
            && (string) ($item['state'] ?? '0') === '0'
            && (string) ($item['lastvalue'] ?? '') !== '';
    }
}
