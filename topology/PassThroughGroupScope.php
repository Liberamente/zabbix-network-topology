<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * One explicit host tag on ONE visible FDB switch enables pass-through
 * inference for that selected host group, within the switch's proxy only:
 *
 *   tag:   nt:pass_through
 *   value: <numeric host group ID>
 *
 * The same module and discovery work for every installation. No network,
 * customer name, proxy ID, or host ID is hard-coded. No API calls or writes.
 *
 * An unrelated group containing the same switch must NOT inherit this opt-in.
 * If a group has several proxies, tag one FDB switch on each desired proxy.
 */
final class PassThroughGroupScope
{
    public const OPT_IN_TAG = 'nt:pass_through';

    /**
     * @param array $hosts Already-visible host.get records, keyed by hostid.
     * @param array $fdbSwitchIds Switch IDs from IN-SCOPE nt.fdb item records.
     * @param array $requestedGroups Selected Zabbix host group IDs.
     *
     * @return array{hosts:array, switch_ids:array, proxy_ids:array}
     */
    public static function eligible(
        array $hosts,
        array $fdbSwitchIds,
        array $requestedGroups,
        bool $hostMode
    ): array {
        $off = ['hosts' => [], 'switch_ids' => [], 'proxy_ids' => []];

        if ($hostMode || count($requestedGroups) !== 1 || !$fdbSwitchIds) {
            return $off;
        }

        $groupId = (string) reset($requestedGroups);
        if (!preg_match('/^[1-9][0-9]*$/D', $groupId)) {
            return $off;
        }

        $switchIds = array_fill_keys(array_map('strval', $fdbSwitchIds), true);
        $permittedProxies = [];

        // Tag must be on a visible FDB source switch, not on an endpoint.
        foreach ($switchIds as $switchId => $_) {
            $switch = $hosts[(string) $switchId] ?? null;
            if (!is_array($switch)) {
                continue;
            }

            $proxyId = (string) ($switch['proxyid'] ?? '0');
            if (!preg_match('/^[1-9][0-9]*$/D', $proxyId)) {
                continue; // No cross-client or server-wide fallback.
            }

            foreach (($switch['tags'] ?? []) as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                if ((string) ($tag['tag'] ?? '') === self::OPT_IN_TAG
                        && (string) ($tag['value'] ?? '') === $groupId) {
                    $permittedProxies[$proxyId] = true;
                    break;
                }
            }
        }

        if (!$permittedProxies) {
            return $off;
        }

        $visibleHosts = array_filter(
            $hosts,
            static function ($host) use ($permittedProxies): bool {
                return is_array($host)
                    && isset($permittedProxies[(string) ($host['proxyid'] ?? '0')]);
            }
        );
        $visibleSwitches = [];
        foreach ($switchIds as $switchId => $_) {
            if (isset($visibleHosts[(string) $switchId])) {
                $visibleSwitches[] = (string) $switchId;
            }
        }

        return [
            'hosts' => $visibleHosts,
            'switch_ids' => $visibleSwitches,
            'proxy_ids' => array_keys($permittedProxies),
        ];
    }
}
