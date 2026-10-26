<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/DependencyScopeMerge.php';
use Modules\NetworkTopology\Topology\DependencyScopeMerge;
function dep(string $a, string $b): array {
    return ['upstream'=>$a,'downstream'=>$b,'type'=>'connectivity','source'=>'manual-confirmed'];
}
$scope = ['101'=>true,'102'=>true,'103'=>true];
$other = dep('201','202');
$cross = dep('102','201');
$own = dep('101','102');
$updated = dep('102','103');
$got = DependencyScopeMerge::replace([$own,$other,$cross],[$updated],$scope);
if ($got !== [$other,$cross,$updated]) { fwrite(STDERR, "FAIL: scoped replace\n"); exit(1); }
if (DependencyScopeMerge::replace([$own,$other,$cross],[],$scope) !== [$other,$cross]) {
    fwrite(STDERR, "FAIL: scoped delete\n"); exit(1);
}
echo "DependencyScopeMerge: PASS (scoped replacement, cross-scope retention, scoped deletion)\n";
