<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require __DIR__ . '/../topology/PortNeighborMap.php';
use Modules\NetworkTopology\Topology\PortNeighborMap;
function check($a,$b): void {
    if ($a !== $b) { fwrite(STDERR,var_export([$a,$b],true)); exit(1); }
}
$ports = [
    ['ifindex'=>'17','fields'=>['if_name'=>['value'=>'Gi1/0/5']]],
    ['ifindex'=>'501','fields'=>['if_name'=>['value'=>'Gi1/0/7']]]
];
$hosts = ['1'=>[], '2'=>[], '3'=>[]];
$edge = ['id'=>'e1','from'=>'1','to'=>'2','src'=>['lldp'],'reporters'=>['1'],
    'ports'=>['1'=>'Gi1/0/5'],'port_idx'=>['1'=>'501'],'confirmed'=>true,'confidence'=>100];
$map = PortNeighborMap::attach($ports,'1',$hosts,[$edge],['501'=>'Gi1/0/5']);
check($map[0]['neighbors'][0]['hostid'],'2');
check($map[0]['neighbors'][0]['edge_confirmed'],true);
check($map[1]['neighbors'],[]);
$none = PortNeighborMap::attach($ports,'1',$hosts,[$edge]);
check($none[0]['neighbors'],[]);
$wrong = PortNeighborMap::attach($ports,'1',$hosts,[$edge],['501'=>'Gi1/0/7']);
check($wrong[1]['neighbors'],[]);
$fdb = array_replace($edge,['id'=>'e2','to'=>'3','src'=>['fdb'],'confirmed'=>false,'confidence'=>60]);
$map = PortNeighborMap::attach($ports,'1',$hosts,[$edge,$fdb],['501'=>'Gi1/0/5']);
check(count($map[0]['neighbors']),2);
check($map[0]['neighbors'][1]['edge_confirmed'],false);
check($map[0]['neighbors'][1]['confidence'],60);
foreach ([
    array_replace($edge,['to'=>'999']),
    array_replace($edge,['stale'=>true]),
    array_replace($edge,['match'=>'pass_through_inferred']),
    array_replace($edge,['src'=>['wifi']])
] as $bad) {
    $map = PortNeighborMap::attach($ports,'1',$hosts,[$bad],['501'=>'Gi1/0/5']);
    check($map[0]['neighbors'],[]);
}
check(PortNeighborMap::attach($ports,'999',$hosts,[$edge]),[]);
echo "Port neighbor scope and mapping regressions PASS\n";
