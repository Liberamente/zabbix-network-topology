<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Fail-closed, tenant-neutral runtime gate.
 * All mode works for a single selected, visibility-scoped group at a time.
 * Identity reconciliation still requires same nonzero proxy in the resolver.
 */
final class PortIdPilotScope {
    public static function mode(
        string $allConfig,
        string $groupIdConfig,
        string $proxyIdConfig,
        array $requestedGroups,
        bool $hostMode
    ): ?string {
        if ($hostMode || count($requestedGroups) !== 1) return null;
        if ($allConfig === '1') return 'all';
        if (!preg_match('/^[1-9][0-9]*$/D', $groupIdConfig)
            || !preg_match('/^[1-9][0-9]*$/D', $proxyIdConfig)) {
            return null;
        }
        if ((string) reset($requestedGroups) !== $groupIdConfig) return null;
        return $proxyIdConfig;
    }

    /** For restricted pilots only; global mode keeps the scoped host set. */
    public static function eligibleHosts(array $hosts, string $proxyId): array {
        return array_filter($hosts, static function ($host) use ($proxyId): bool {
            return (string) ($host['proxyid'] ?? '0') === $proxyId;
        });
    }
}
