<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\NetworkTopology\Topology;

/**
 * Parameterized SQL templates for isolated PDO MySQL/PostgreSQL laboratories.
 * Never interpolate user-supplied JSON or version in SQL text.
 * Not integrated with Zabbix frontend DB abstraction.
 */
final class DependencyCasSql {
    private const TABLE = 'nt_dependency_state';
    public static function select(): string {
        return "SELECT version, payload FROM " . self::TABLE
            . " WHERE scope_key = ?";
    }
    public static function update(string $driver): string {
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new \InvalidArgumentException('Unsupported driver');
        }
        return "UPDATE ".self::TABLE
            ." SET payload = ?, version = version + 1"
            ." WHERE scope_key = ? AND version = ?";
    }
}
