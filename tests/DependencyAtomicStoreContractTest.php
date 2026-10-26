<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/DependencyAtomicStore.php';
use Modules\NetworkTopology\Topology\DependencyAtomicStore;
final class SyntheticAtomicStore implements DependencyAtomicStore {
    private int $version = 0;
    private array $data = [];
    public function snapshot(): array {
        return ['version'=>(string)$this->version, 'dependencies'=>$this->data];
    }
    public function replaceIfVersion(string $expectedVersion, array $dependencies): bool {
        if ($expectedVersion !== (string)$this->version) return false;
        $this->data = $dependencies;
        $this->version++;
        return true;
    }
}
function assertCase(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, 'FAIL: '.$label."\n"); exit(1); }
    echo 'PASS: '.$label."\n";
}
$store = new SyntheticAtomicStore();
$first = $store->snapshot();
$second = $store->snapshot();
assertCase($store->replaceIfVersion($first['version'], [['upstream'=>'101','downstream'=>'102']]),
    'first writer succeeds');
assertCase(!$store->replaceIfVersion($second['version'], [['upstream'=>'101','downstream'=>'103']]),
    'stale competing writer rejected');
assertCase($store->snapshot()['dependencies'] === [['upstream'=>'101','downstream'=>'102']],
    'first write not lost');
$third = $store->snapshot();
assertCase($store->replaceIfVersion($third['version'], []), 'fresh reset succeeds');
assertCase($store->snapshot()['dependencies'] === [], 'reset persisted');
echo "Atomic store contract model: PASS (synthetic only, no DB implementation)\n";
