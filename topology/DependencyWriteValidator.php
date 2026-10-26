<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * Fail-closed request validation for admin-confirmed dependencies.
 * Separate from Zabbix API/HTTP so security decisions can be regression tested.
 */
final class DependencyWriteValidator {
    public static function validate(array $rows, array $hosts, array $scopeIds): bool {
        if (!array_is_list($rows) || count($rows) > 2000) return false;
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 4) return false;
            $keys = array_keys($row);
            sort($keys);
            if ($keys !== ['downstream', 'source', 'type', 'upstream']) return false;
            $from = (string) $row['upstream'];
            $to = (string) $row['downstream'];
            if (!preg_match('/^[1-9][0-9]{0,19}$/', $from)
                    || !preg_match('/^[1-9][0-9]{0,19}$/', $to)) return false;
            if (!isset($scopeIds[$from], $scopeIds[$to])) return false;
            if (!isset($hosts[$from], $hosts[$to])) return false;
            $a = (string) ($hosts[$from]['proxyid'] ?? '0');
            $b = (string) ($hosts[$to]['proxyid'] ?? '0');
            if ($a === '0' || $b === '0' || $a !== $b) return false;
            $key = $from . ':' . $to;
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            if ($row['type'] !== 'connectivity' || $row['source'] !== 'manual-confirmed') return false;
        }
        return count(DependencyResolver::resolve($hosts, $rows)) === count($rows);
    }
}
