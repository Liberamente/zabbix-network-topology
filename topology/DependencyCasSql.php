<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\NetworkTopology\Topology;

/**
 * Strict SQL construction for a laboratory CAS statement.
 * This class does not connect, migrate, or modify the Zabbix database.
 */
final class DependencyCasSql {
    private const TABLE = 'nt_dependency_state';
    public static function select(): string {
        return "SELECT version, payload FROM " . self::TABLE
            . " WHERE scope_key='global'";
    }
    public static function update(string $driver, string $json,
            string $expectedVersion): string {
        if (!in_array($driver, ['mysql','pgsql'], true)) {
            throw new \InvalidArgumentException('Unsupported driver');
        }
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $expectedVersion)
                || strlen($expectedVersion) > 18) {
            throw new \InvalidArgumentException('Invalid version');
        }
        if (strlen($json) > 524288
                || !is_array(json_decode($json, true, 512, JSON_THROW_ON_ERROR))) {
            throw new \InvalidArgumentException('Invalid dependency JSON');
        }
        // A single literal uses SQL-standard doubled apostrophes, supported
        // by PostgreSQL and by MySQL under NO_BACKSLASH_ESCAPES. For normal
        // MySQL mode, json_encode emits backslashes: use a prepared-statement
        // backend instead; this method is therefore PostgreSQL-only.
        if ($driver !== 'pgsql') {
            throw new \LogicException('MySQL requires bound parameters; no SQL literals');
        }
        $escaped = str_replace("'", "''", $json);
        return "UPDATE ".self::TABLE." SET payload='".$escaped
            ."', version=version+1 WHERE scope_key='global' AND version="
            .$expectedVersion;
    }
}
