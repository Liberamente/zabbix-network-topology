<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once __DIR__ . '/../topology/FdbResolver.php';
require_once __DIR__ . '/../topology/FdbParser.php';
require_once __DIR__ . '/../topology/HostMetadata.php';
require_once __DIR__ . '/../topology/LldpEdgeBuilder.php';
require_once __DIR__ . '/../topology/AutoFdbEdges.php';
require_once __DIR__ . '/../topology/PassThroughEvidence.php';

use Modules\NetworkTopology\Topology\PassThroughEvidence;

$now = 2000000000;
function h(string $name, string $ip, string $proxy='100'): array {
    return ['host'=>$name,'name'=>$name,'proxyid'=>$proxy,
        'interfaces'=>[['ip'=>$ip]],'inventory'=>[],'tags'=>[]];
}
function item(string $hid,string $key,string $value,int $delay=20): array {
    global $now;
    return ['hostid'=>$hid,'key_'=>$key,'lastvalue'=>$value,
        'lastclock'=>$now-$delay,'status'=>'0','state'=>'0'];
}
function line(string $oid,string $val): string {
    return '.' . $oid . ' = ' . $val . "\n";
}
function macIndex(string $mac): string {
    return implode('.',array_map('hexdec',explode(':',$mac)));
}
function fixtures(): array {
    $hosts=[
      '100'=>h('switch-a','192.0.2.1'),
      '101'=>h('phone-a','192.0.2.2'),
      '102'=>h('moxa-a','192.0.2.3'),
    ];
    $sw='100';$phone='02:11:22:33:44:55';$device='02:11:22:33:44:66';
    $fdb=line('1.3.6.1.2.1.17.7.1.2.2.1.2.1.'.macIndex($phone),'INTEGER: 7')
        .line('1.3.6.1.2.1.17.7.1.2.2.1.2.1.'.macIndex($device),'INTEGER: 7');
    $walk=[
       item($sw,'nt.fdb.dot1q',$fdb),
       item($sw,'nt.fdb.baseport',line('1.3.6.1.2.1.17.1.4.1.2.7','INTEGER: 7')),
       item($sw,'nt.fdb.ifname',line('1.3.6.1.2.1.31.1.1.1.1.7','STRING: "Gi7"')),
       item($sw,'nt.arp',
          line('1.3.6.1.2.1.4.22.1.2.7.192.0.2.2','Hex-STRING: '.str_replace(':',' ',$phone))
         .line('1.3.6.1.2.1.4.22.1.2.7.192.0.2.3','Hex-STRING: '.str_replace(':',' ',$device))),
    ];
    $caps=[item($sw,'lldpRemSysCapEnabled[0.7.1]',chr(0x24).chr(0x00))];
    $cdp=[item($sw,'cdpCacheDeviceId[7.1]','01 C0 00 02 02')];
    $edges=[['id'=>'edge-phone','from'=>'100','to'=>'101',
        'src'=>['lldp'],'match'=>'alias','confirmed'=>false,
        'ports'=>['100'=>'Gi7'],'port_idx'=>['100'=>'7']]];
    return [$hosts,$walk,$caps,$cdp,$edges];
}
function verify(bool $ok,string $label):void {
    if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}
    echo "PASS: $label\n";
}
[$hosts,$walk,$caps,$cdp,$edges]=fixtures();
$before=serialize([$hosts,$walk,$caps,$cdp,$edges]);
$rows=PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$edges,[],$now);
verify(count($rows)===1,'one inferred candidate');
verify($rows[0]['from']==='101'&&$rows[0]['to']==='102',
    'real host phone to industrial endpoint');
verify($rows[0]['match']==='pass_through_inferred'&&$rows[0]['confirmed']===false,
    'never a verified physical cable');
verify($rows[0]['port_metrics']===[]&&$rows[0]['ports']===[],
    'no fabricated metrics or endpoint port');
verify(serialize([$hosts,$walk,$caps,$cdp,$edges])===$before,
    'read-only inputs and original edges unchanged');

$notPhone=$caps;$notPhone[0]['lastvalue']=chr(0x20).chr(0x00);
verify(PassThroughEvidence::propose($hosts,$walk,$notPhone,$cdp,$edges,[],$now)===[],
    'Bridge-only neighbors excluded');
$old=$cdp;$old[0]['lastclock']=$now-15000;
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$old,$edges,[],$now)===[],
    'stale CDP excluded');
$wrong=$hosts;$wrong['102']['proxyid']='200';
verify(PassThroughEvidence::propose($wrong,$walk,$caps,$cdp,$edges,[],$now)===[],
    'different proxy excluded');
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$edges,['102'=>['host'=>'other','port'=>'1'] ],$now)===[],
    'manual uplink takes precedence');
$existing=$edges;$existing[]=['from'=>'101','to'=>'102','match'=>'fdb'];
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$existing,[],$now)===[],
    'no duplicate when endpoint already linked');
$noUpstream=[];
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$noUpstream,[],$now)===[],
    'no invented switch-to-phone edge');
$wrongPort=$edges;
$wrongPort[0]['ports']['100']='Gi8';
$wrongPort[0]['port_idx']['100']='8';
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$wrongPort,[],$now)===[],
    'switch-to-phone link on another port does not corroborate');
$fdbOnly=$edges;
$fdbOnly[0]['src']=['fdb'];
$fdbOnly[0]['match']='fdb';
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$fdbOnly,[],$now)===[],
    'FDB-only upper leg does not independently identify phone');
$manualOnly=$edges;
$manualOnly[0]['src']=['lldp'];
$manualOnly[0]['_type']='manual';
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$manualOnly,[],$now)===[],
    'manual upper leg cannot masquerade as LLDP evidence');
$wifiOnly=$edges;
$wifiOnly[0]['src']=['wifi'];
$wifiOnly[0]['match']='wifi';
verify(PassThroughEvidence::propose($hosts,$walk,$caps,$cdp,$wifiOnly,[],$now)===[],
    'Wi-Fi upper leg cannot imply a copper pass-through');
$more=$walk;$more[0]['lastvalue'].=line(
    '1.3.6.1.2.1.17.7.1.2.2.1.2.1.'.macIndex('02:11:22:33:44:77'),'INTEGER: 7');
verify(PassThroughEvidence::propose($hosts,$more,$caps,$cdp,$edges,[],$now)===[],
    'three MACs on shared port: no inferred chain');
$ambig=$hosts;$ambig['103']=h('duplicate-identity','192.0.2.4');
$ambig[103]['inventory']=['macaddress_a'=>'02:11:22:33:44:66'];
// Inventory alone does not affect ARP-only owners, but a duplicated IP does.
$ambig['103']['interfaces']=[['ip'=>'192.0.2.3']];
verify(PassThroughEvidence::propose($ambig,$walk,$caps,$cdp,$edges,[],$now)===[],
    'duplicate monitored endpoint IP excluded');
echo "PassThroughEvidenceTest: PASS (synthetic; no production modifications)\n";
