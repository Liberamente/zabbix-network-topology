<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
require_once dirname(__DIR__) . '/topology/DependencyResolver.php';
require_once dirname(__DIR__) . '/topology/DependencyReadProjection.php';

use Modules\NetworkTopology\Topology\DependencyReadProjection;

$nodes = [['id'=>'101'],['id'=>'102'],['id'=>'103'],['id'=>'ghost_x']];
$hosts = ['101'=>['proxyid'=>'500'], '102'=>['proxyid'=>'500'],
          '103'=>['proxyid'=>'600'], '999'=>['proxyid'=>'500']];
$make = static fn($a,$b,$source='manual-confirmed') =>
    ['upstream'=>$a,'downstream'=>$b,'type'=>'connectivity','source'=>$source];
$all = [$make('101','102'),$make('101','103'),$make('101','999'),
        $make('101','102','fdb'),$make('101','102')];
$got = DependencyReadProjection::project($all,$nodes,$hosts);
if (count($got) !== 1 || $got[0]['upstream'] !== '101'
        || $got[0]['downstream'] !== '102'
        || $got[0]['confirmed'] !== true) {
    fwrite(STDERR, "DependencyReadProjection: FAIL\n"); exit(1);
}
if (DependencyReadProjection::project($all,[], $hosts) !== []
        || DependencyReadProjection::project($all,$nodes,[]) !== []) {
    fwrite(STDERR, "DependencyReadProjection: FAIL closed scope\n"); exit(1);
}
echo "DependencyReadProjection: PASS (visibility, proxy, evidence, no ghost, fail closed)\n";
