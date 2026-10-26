<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__) . '/topology/DependencyCasSql.php';
use Modules\NetworkTopology\Topology\DependencyCasSql as S;
function checkSql(bool $ok,string $name): void {
    if (!$ok) { fwrite(STDERR,"FAIL: ".$name."\n"); exit(1); }
    echo "PASS: ".$name."\n";
}
$payload=json_encode([['upstream'=>'101','downstream'=>'102']]);
$sql=S::update('pgsql',$payload,'7');
checkSql(str_contains($sql,"version=7"),'PG CAS predicate');
checkSql(str_contains($sql,"version=version+1"),'PG increments version');
checkSql(str_contains(S::select(),"scope_key='global'"),'single document');
foreach (['7 OR 1=1','-1','07',''] as $bad) {
    try { S::update('pgsql',$payload,$bad); exit("FAIL: rejected version ".$bad."\n"); }
    catch (InvalidArgumentException $e) { }
}
echo "PASS: malformed versions rejected\n";
try { S::update('mysql',$payload,'7'); exit("FAIL: mysql literals allowed\n"); }
catch (LogicException $e) { echo "PASS: MySQL literal SQL disabled\n"; }
echo "DependencyCasSql: PASS (statement construction only; no database touched)\n";
