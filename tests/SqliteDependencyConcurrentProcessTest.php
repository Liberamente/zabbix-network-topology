<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__) . '/topology/DependencyAtomicStore.php';
require_once dirname(__DIR__) . '/topology/SqliteDependencyLabStore.php';
use Modules\NetworkTopology\Topology\SqliteDependencyLabStore;
if (!extension_loaded('pdo_sqlite') || !function_exists('proc_open')) {
    fwrite(STDERR, "SKIP: pdo_sqlite or proc_open unavailable (not PASS)\n");
    exit(77);
}
function verify(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label."\n";
}
$dir = sys_get_temp_dir() . '/nt_cas_parallel_' . bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new RuntimeException('Cannot create temp dir');
$db=$dir.'/lab.sqlite';
$gate=$dir.'/barrier';
$procs=[];
try {
    $dbConn=new PDO('sqlite:'.$db);
    SqliteDependencyLabStore::initialize($dbConn);
    $dbConn=null;
    foreach (['a','b'] as $id) {
        $cmd=[PHP_BINARY, __DIR__.'/SqliteDependencyConcurrentWorker.php', $db, $gate, $id];
        $descriptors=[0=>['pipe','r'],1=>['file',$dir.'/'.$id.'.out','w'],
            2=>['file',$dir.'/'.$id.'.err','w']];
        $proc=proc_open($cmd,$descriptors,$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start worker '.$id);
        fclose($pipes[0]);
        $procs[$id]=$proc;
    }
    $deadline=microtime(true)+10;
    while (!is_file($gate.'.a.ready') || !is_file($gate.'.b.ready')) {
        if (microtime(true)>$deadline) throw new RuntimeException('Workers did not reach barrier');
        usleep(10000);
    }
    verify(file_get_contents($gate.'.a.ready')==='0'
        && file_get_contents($gate.'.b.ready')==='0', 'both independent workers read version zero');
    file_put_contents($gate.'.go', 'go');
    while (!is_file($gate.'.a.result') || !is_file($gate.'.b.result')) {
        if (microtime(true)>$deadline+5) throw new RuntimeException('Concurrent workers timed out');
        usleep(10000);
    }
    $results=[file_get_contents($gate.'.a.result'),file_get_contents($gate.'.b.result')];
    sort($results);
    verify($results===['CONFLICT','WIN'], 'exactly one concurrent write succeeds');
    $dbConn=new PDO('sqlite:'.$db);
    $state=(new SqliteDependencyLabStore($dbConn))->snapshot();
    verify($state['version']==='1', 'database advanced by one version');
    verify(count($state['dependencies'])===1, 'no partial or duplicate document');
    echo "SQLite process contention: PASS (two PHP processes and separate DB connections)\n";
} finally {
    foreach ($procs as $proc) {
        $status=proc_get_status($proc);
        if ($status['running']) proc_terminate($proc);
        proc_close($proc);
    }
    $dbConn=null;
    foreach (glob($dir.'/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
