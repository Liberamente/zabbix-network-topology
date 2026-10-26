<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/DependencyResolver.php';
require_once dirname(__DIR__) . '/topology/DependencyWriteValidator.php';
use Modules\NetworkTopology\Topology\DependencyWriteValidator as V;
$hosts = ['101'=>['proxyid'=>'9'], '102'=>['proxyid'=>'9'],
          '103'=>['proxyid'=>'9'], '104'=>['proxyid'=>'10']];
$scope = ['101'=>true,'102'=>true,'103'=>true];
$a = ['upstream'=>'101','downstream'=>'102','type'=>'connectivity','source'=>'manual-confirmed'];
$b = ['upstream'=>'102','downstream'=>'103','type'=>'connectivity','source'=>'manual-confirmed'];
$check = function (string $name, array $rows, array $visible, bool $want) use ($hosts): void {
    $got=V::validate($rows,$hosts,$visible);
    if ($got !== $want) { fwrite(STDERR, "FAIL: ".$name."\n"); exit(1); }
    echo "PASS: ".$name."\n";
};
$check('valid chain',[$a,$b],$scope,true);
$check('empty reset',[],$scope,true);
$check('duplicate',[$a,$a],$scope,false);
$check('cycle',[$a,['upstream'=>'102','downstream'=>'101','type'=>'connectivity','source'=>'manual-confirmed']],$scope,false);
$check('unscoped host',[$b],['101'=>true,'102'=>true],false);
$check('cross proxy',[['upstream'=>'101','downstream'=>'104','type'=>'connectivity','source'=>'manual-confirmed']],$scope+['104'=>true],false);
$check('unmonitored host',[['upstream'=>'101','downstream'=>'999','type'=>'connectivity','source'=>'manual-confirmed']],$scope+['999'=>true],false);
$check('fdb cannot confirm',[array_replace($a,['source'=>'fdb'])],$scope,false);
$check('extra field',[array_replace($a,['client'=>'x'])],$scope,false);
$check('noncanonical ID',[array_replace($a,['upstream'=>'0101'])],$scope,false);
