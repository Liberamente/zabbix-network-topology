<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__) . '/topology/DependencyCasSql.php';
use Modules\NetworkTopology\Topology\DependencyCasSql as S;
function checkSql(bool $ok,string $name): void {
    if (!$ok) { fwrite(STDERR,"FAIL: ".$name."\n"); exit(1); }
    echo "PASS: ".$name."\n";
}
foreach (['mysql','pgsql'] as $driver) {
    $sql=S::update($driver);
    checkSql(substr_count($sql,'?')===3,$driver.' bound parameters');
    checkSql(str_contains($sql,'version = version + 1'),$driver.' version increment');
    checkSql(str_contains($sql,'version = ?'),$driver.' revision predicate');
}
checkSql(substr_count(S::select(),'?')===1,'bound read scope');
try { S::update('sqlite'); exit("FAIL: sqlite allowed\n"); }
catch (InvalidArgumentException $e) { echo "PASS: unsupported driver rejected\n"; }
echo "DependencyCasSql: PASS (parameterized templates only; no database touched)\n";
