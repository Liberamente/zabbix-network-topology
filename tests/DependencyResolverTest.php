<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/DependencyResolver.php';
use Modules\NetworkTopology\Topology\DependencyResolver;
$hosts = [
    '101' => ['proxyid'=>'500'],
    '102' => ['proxyid'=>'500'],
    '103' => ['proxyid'=>'500'],
    '104' => ['proxyid'=>'600']
];
$valid = static function ($a, $b): array {
    return ['upstream'=>$a,'downstream'=>$b,'type'=>'connectivity',
            'source'=>'manual-confirmed'];
};
$rows = [
    $valid('101','102'), $valid('102','103'), $valid('101','102'),
    $valid('103','101'), $valid('101','104'), $valid('101','999'),
    $valid('102','102'),
    ['upstream'=>'101','downstream'=>'103','type'=>'connectivity','source'=>'fdb'],
    ['upstream'=>'101','downstream'=>'103','type'=>'power','source'=>'manual-confirmed'],
];
$got = DependencyResolver::resolve($hosts, $rows);
if (count($got) !== 2) {
    fwrite(STDERR, 'Expected two accepted dependencies, got ' . count($got) . "\n");
    exit(1);
}
if ($got[0]['upstream'] !== '101' || $got[0]['downstream'] !== '102'
    || $got[1]['upstream'] !== '102' || $got[1]['downstream'] !== '103') {
    fwrite(STDERR, "Unexpected dependency order\n");
    exit(1);
}
foreach ($got as $d) {
    if ($d['source'] !== 'manual-confirmed' || $d['confirmed'] !== true) exit(1);
}
if (DependencyResolver::resolve([], $rows) !== []) exit(1);
echo "DependencyResolver: PASS (synthetic cascade, duplicate, cycle, scope, proxy, evidence, type)\n";
