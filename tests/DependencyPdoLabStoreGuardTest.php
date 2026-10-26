<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__).'/topology/DependencyAtomicStore.php';
require_once dirname(__DIR__).'/topology/DependencyCasSql.php';
require_once dirname(__DIR__).'/topology/DependencyPdoLabStore.php';
use Modules\NetworkTopology\Topology\DependencyPdoLabStore;
function guardCheck(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR,"SKIP: pdo_sqlite unavailable (not PASS)\n"); exit(77);
}
$pdo=new PDO('sqlite::memory:');
foreach (['zabbix','nt_lab_example','nt_lab_abc'] as $name) {
    try { new DependencyPdoLabStore($pdo,$name,true); exit("FAIL: driver rejection\n"); }
    catch (RuntimeException $e) { guardCheck(true,'unsupported SQLite driver rejected'); }
}
echo "PDO lab guard: PASS (SQLite rejected; vendor/live connections NOT tested)\n";
