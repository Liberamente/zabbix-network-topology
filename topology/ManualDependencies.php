<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

use APP;

/**
 * Shared, admin-confirmed connectivity dependencies. Different storage key
 * from undirected manual links: direction must not be lost by pair sorting.
 * Writes through SharedConfig (Zabbix module API authorization).
 *
 * No API route is exposed in this phase; reads/writes are not yet wired.
 */
final class ManualDependencies {
    private const MODULE_ID = 'network_topology';
    private const CONFIG_KEY = 'manual_dependencies';
    private const MAX_RELATIONS = 2000;
    private const ID_PATTERN = '/^[0-9]{1,20}$/';

    public static function sanitize(array $raw): array {
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $from = (string) ($row['upstream'] ?? '');
            $to = (string) ($row['downstream'] ?? '');
            if (!preg_match(self::ID_PATTERN, $from)
                    || !preg_match(self::ID_PATTERN, $to)
                    || $from === $to || $from === '0' || $to === '0') continue;
            if (($row['type'] ?? '') !== 'connectivity'
                    || ($row['source'] ?? '') !== 'manual-confirmed') continue;
            $key = $from . ':' . $to;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['upstream' => $from, 'downstream' => $to,
                'type' => 'connectivity', 'source' => 'manual-confirmed'];
            if (count($out) >= self::MAX_RELATIONS) break;
        }
        return $out;
    }

    public static function loadShared(): array {
        $module = APP::ModuleManager()->getModule(self::MODULE_ID);
        if ($module === null) return [];
        $config = $module->getConfig();
        return is_array($config[self::CONFIG_KEY] ?? null)
            ? self::sanitize($config[self::CONFIG_KEY]) : [];
    }

    /**
     * For Super admins only. Caller must also validate host visibility and
     * proxy consistency before persisting; this method checks only shape.
     */
    public static function saveShared(array $rows): array {
        $module = APP::ModuleManager()->getModule(self::MODULE_ID);
        if ($module === null) return [];
        $clean = self::sanitize($rows);
        SharedConfig::write($module, self::CONFIG_KEY, $clean);
        return $clean;
    }
}
