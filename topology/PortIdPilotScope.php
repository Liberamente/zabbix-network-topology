<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Generic, fail-closed pilot gate. Values must be supplied privately by the
 * PHP-FPM/container runtime; no tenant IDs belong in the shared repository.
 */
final class PortIdPilotScope {
    public static function enabledProxy(
        string $groupIdConfig,
        string $proxyIdConfig,
        array $requestedGroups,
        bool $hostMode
    ): ?string {
        if ($hostMode || count($requestedGroups) !== 1) return null;
        if (!preg_match('/^[1-9][0-9]*$/D', $groupIdConfig)
            || !preg_match('/^[1-9][0-9]*$/D', $proxyIdConfig)) {
            return null;
        }
        if ((string) reset($requestedGroups) !== $groupIdConfig) return null;
        return $proxyIdConfig;
    }

    /** Restrict candidates to the configured proxy; never drop other map nodes. */
    public static function eligibleHosts(array $hosts, string $proxyId): array {
        return array_filter($hosts, static function ($host) use ($proxyId): bool {
            return (string) ($host['proxyid'] ?? '0') === $proxyId;
        });
    }
}
