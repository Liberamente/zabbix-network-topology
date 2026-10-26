<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/ManualDependencies.php';
use Modules\NetworkTopology\Topology\ManualDependencies;

$good = ['upstream'=>'1001','downstream'=>'1002','type'=>'connectivity',
    'source'=>'manual-confirmed'];
$reverse = ['upstream'=>'1002','downstream'=>'1001','type'=>'connectivity',
    'source'=>'manual-confirmed'];
$rows = [$good, $good, $reverse,
    ['upstream'=>'1001','downstream'=>'1001','type'=>'connectivity','source'=>'manual-confirmed'],
    ['upstream'=>'ghost_a','downstream'=>'1002','type'=>'connectivity','source'=>'manual-confirmed'],
    ['upstream'=>'1002','downstream'=>'1003','type'=>'connectivity','source'=>'fdb'],
    ['upstream'=>'1002','downstream'=>'1003','type'=>'power','source'=>'manual-confirmed']];
$got = ManualDependencies::sanitize($rows);
if (count($got) !== 2 || $got[0] !== $good || $got[1] !== $reverse) {
    fwrite(STDERR, "ManualDependencies: FAIL\n"); exit(1);
}
if (ManualDependencies::sanitize([]) !== []) exit(1);
echo "ManualDependencies: PASS (direction, duplicate, scope shape, evidence, type)\n";
