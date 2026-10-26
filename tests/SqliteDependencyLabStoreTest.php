<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__) . '/topology/DependencyAtomicStore.php';
require_once dirname(__DIR__) . '/topology/SqliteDependencyLabStore.php';
require_once dirname(__DIR__) . '/topology/DependencyScopeMerge.php';
use Modules\NetworkTopology\Topology\SqliteDependencyLabStore as Store;
use Modules\NetworkTopology\Topology\DependencyScopeMerge as Merge;
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "SKIP: pdo_sqlite not installed (not a PASS)\n");
    exit(77);
}
function ok(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, 'FAIL: '.$label."\n"); exit(1); }
    echo 'PASS: '.$label."\n";
}
function dep(string $a, string $b): array {
    return ['upstream'=>$a,'downstream'=>$b,'type'=>'connectivity','source'=>'manual-confirmed'];
}
$path = tempnam(sys_get_temp_dir(), 'nt_cas_lab_');
if ($path === false) throw new RuntimeException('Temp file unavailable');
try {
    $dsn='sqlite:'.$path;
    $a=new PDO($dsn);
    $b=new PDO($dsn);
    $a->setAttribute(PDO::ATTR_TIMEOUT, 2);
    $b->setAttribute(PDO::ATTR_TIMEOUT, 2);
    Store::initialize($a);
    $sa=new Store($a);
    $sb=new Store($b);
    $av=$sa->snapshot();
    $bv=$sb->snapshot();
    ok($sa->replaceIfVersion($av['version'], [dep('101','102')]), 'first connection writes');
    ok(!$sb->replaceIfVersion($bv['version'], [dep('101','103')]), 'stale second connection rejected');
    ok($sb->snapshot()['dependencies']===[dep('101','102')], 'first write retained');
    $current=$sb->snapshot();
    $combined=Merge::replace($current['dependencies'], [dep('201','202')], ['201'=>true,'202'=>true]);
    ok($sb->replaceIfVersion($current['version'], $combined), 'scoped merge stored atomically');
    ok($sa->snapshot()['dependencies']===[dep('101','102'),dep('201','202')], 'unrelated scope retained');
    $previous=$sa->snapshot();
    $clean=Merge::replace($previous['dependencies'], [], ['101'=>true,'102'=>true]);
    ok($sa->replaceIfVersion($previous['version'], $clean), 'scoped delete stored');
    ok($sb->snapshot()['dependencies']===[dep('201','202')], 'other scope survived delete');
    $stale=$sa->snapshot();
    ok(!$sa->replaceIfVersion('not-a-version', []), 'invalid version rejected');
    ok($sa->snapshot()===$stale, 'invalid version leaves DB unchanged');
    echo "SQLite lab: PASS (two connections, sequential CAS interleaving; not simultaneous stress)\n";
} finally {
    $a=null; $b=null; $sa=null; $sb=null;
    @unlink($path);
}
