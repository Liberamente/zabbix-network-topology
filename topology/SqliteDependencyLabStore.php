<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\NetworkTopology\Topology;
use PDO;
use RuntimeException;

/** SQLite-only disposable laboratory adapter. NEVER wire into Zabbix. */
final class SqliteDependencyLabStore implements DependencyAtomicStore {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('SQLite lab connection required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    public static function initialize(PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS nt_dependency_lab (
            scope_key TEXT PRIMARY KEY, version INTEGER NOT NULL, payload TEXT NOT NULL)');
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO nt_dependency_lab
            (scope_key, version, payload) VALUES ('global', 0, '[]')");
        $stmt->execute();
    }
    public function snapshot(): array {
        $stmt = $this->pdo->query("SELECT version, payload FROM nt_dependency_lab WHERE scope_key='global'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new RuntimeException('Missing lab row');
        $data = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid lab document');
        return ['version'=>(string)$row['version'], 'dependencies'=>$data];
    }
    public function replaceIfVersion(string $expectedVersion, array $dependencies): bool {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $expectedVersion)) return false;
        $json = json_encode($dependencies, JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare("UPDATE nt_dependency_lab
            SET payload=:payload, version=version+1
            WHERE scope_key='global' AND version=:expected");
        $stmt->bindValue(':payload', $json, PDO::PARAM_STR);
        $stmt->bindValue(':expected', $expectedVersion, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() === 1;
    }
}
