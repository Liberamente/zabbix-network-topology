<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\NetworkTopology\Topology;
use PDO;
use RuntimeException;

/**
 * Disposable MySQL/PostgreSQL laboratory adapter; NEVER wired into Zabbix.
 * Caller must open a dedicated DB and attest to its disposable identity.
 */
final class DependencyPdoLabStore implements DependencyAtomicStore {
    private string $driver;
    public function __construct(private PDO $pdo, string $databaseName,
            bool $disposableConfirmed = false) {
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql'], true)
                || !preg_match('/^nt_lab_[a-z0-9_]{3,40}$/D', $databaseName)
                || !$disposableConfirmed) {
            throw new RuntimeException('Dedicated disposable nt_lab database required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Verify the actual selected DB; never trust a caller-provided label.
        $actual = $pdo->query($this->driver === 'mysql'
            ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn();
        if ($actual !== $databaseName) {
            throw new RuntimeException('Actual database does not match approved lab');
        }
    }
    public function snapshot(): array {
        $stmt=$this->pdo->prepare(DependencyCasSql::select());
        $stmt->execute(['global']);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Missing initialized lab row');
        $data=json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException('Invalid stored dependency list');
        }
        return ['version'=>(string)$row['version'], 'dependencies'=>$data];
    }
    public function replaceIfVersion(string $expectedVersion, array $dependencies): bool {
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D', $expectedVersion)) return false;
        if (!array_is_list($dependencies) || count($dependencies)>2000) {
            throw new \InvalidArgumentException('Invalid dependency document');
        }
        $payload=json_encode($dependencies, JSON_THROW_ON_ERROR);
        if (strlen($payload)>524288) {
            throw new \InvalidArgumentException('Dependency document too large');
        }
        $stmt=$this->pdo->prepare(DependencyCasSql::update($this->driver));
        $stmt->execute([$payload,'global',$expectedVersion]);
        return $stmt->rowCount()===1;
    }
}
