<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__) . '/topology/DependencyAtomicStore.php';
require_once dirname(__DIR__) . '/topology/SqliteDependencyLabStore.php';
use Modules\NetworkTopology\Topology\SqliteDependencyLabStore;
if ($argc !== 4) exit(2);
[$script,$db,$prefix,$id] = $argv;
try {
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $store = new SqliteDependencyLabStore($pdo);
    $snapshot = $store->snapshot();
    file_put_contents($prefix . '.' . $id . '.ready', $snapshot['version']);
    $deadline = microtime(true) + 8;
    while (!is_file($prefix . '.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout');
        usleep(10000);
    }
    $data = [['upstream'=>'101', 'downstream'=>($id==='a'?'102':'103'),
        'type'=>'connectivity', 'source'=>'manual-confirmed']];
    $won = $store->replaceIfVersion($snapshot['version'], $data);
    file_put_contents($prefix . '.' . $id . '.result', $won ? 'WIN' : 'CONFLICT');
} catch (Throwable $e) {
    file_put_contents($prefix . '.' . $id . '.result', 'ERROR');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
