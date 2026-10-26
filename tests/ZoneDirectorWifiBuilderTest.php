<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
spl_autoload_register(static function(string $name): void {
    $prefix='Modules\\NetworkTopology\\Topology\\';
    if (str_starts_with($name,$prefix)) {
        $path=dirname(__DIR__).'/topology/'.substr($name,strlen($prefix)).'.php';
        if (is_file($path)) require_once $path;
    }
});
use Modules\NetworkTopology\Topology\AutoWifiEdges;
$now=1792406000;
function item(string $host,string $key,string $value,int $age=30): array {
    global $now;
    return ['hostid'=>$host,'key_'=>$key,'lastclock'=>$now-$age,
        'lastvalue'=>$value,'status'=>'0','state'=>'0'];
}
function check(string $label,bool $ok): void {
    if (!$ok) { echo "FAIL: $label\n"; exit(1); }
    echo "PASS: $label\n";
}
$ap='6.2.170.187.204.221.16';
$client='6.2.170.187.204.221.1';
$rootAp='1.3.6.1.4.1.25053.1.2.2.1.1.2.1.1';
$rootCl='1.3.6.1.4.1.25053.1.2.2.1.1.3.1.1';
$rows=[
item('10','nt.wifi.zd.ap.1',$rootAp.'.1.'.$ap.' = Hex-STRING: 02 AA BB CC DD 10'),
item('10','nt.wifi.zd.ap.3',$rootAp.'.3.'.$ap.' = INTEGER: 1'),
item('10','nt.wifi.zd.ap.10',$rootAp.'.10.'.$ap.' = IpAddress: 192.0.2.4'),
item('10','nt.wifi.zd.client.2',$rootCl.'.2.'.$client.' = Hex-STRING: 02 AA BB CC DD 10'),
item('10','nt.wifi.zd.client.8',$rootCl.'.8.'.$client.' = IpAddress: 192.0.2.147'),
];
$hosts=[
'10'=>['host'=>'controller','proxyid'=>'100','interfaces'=>[['ip'=>'192.0.2.40']]],
'20'=>['host'=>'ap','proxyid'=>'100','interfaces'=>[['ip'=>'192.0.2.4']]],
'21'=>['host'=>'client','proxyid'=>'100','interfaces'=>[['ip'=>'192.0.2.147']]],
'22'=>['host'=>'router','proxyid'=>'100','interfaces'=>[['ip'=>'192.0.2.1']]],
];
$arp=[item('22','nt.arp','.1.3.6.1.2.1.4.22.1.2.9.192.0.2.147 = Hex-STRING: 02 AA BB CC DD 01')];
$run=static fn(array $h,array $r,array $a,array $e=[],array $m=[]) =>
    AutoWifiEdges::appendController($h,$r,$a,$e,$m,$GLOBALS['now']);
$edges=$run($hosts,$rows,$arp,[['id'=>'link','from'=>'22','to'=>'20','src'=>['lldp']]]);
check('adds client with existing AP LLDP link',count($edges)===2);
check('AP resolved',$edges[1]['from']==='20');
check('client resolved',$edges[1]['to']==='21');
check('source explicit',($edges[1]['source_detail']??'')==='ruckus-zonedirector');
check('no fabricated signal',!isset($edges[1]['signal_dbm']));
check('manual connection takes precedence',count($run($hosts,$rows,$arp,[],['21'=>true]))===0);
check('existing client edge takes precedence',count($run($hosts,$rows,$arp,[['from'=>'22','to'=>'21']]))===1);
$other=$hosts;$other['21']['proxyid']='101';
check('cross proxy rejected',count($run($other,$rows,$arp))===0);
$other=$hosts;unset($other['20']);
check('AP outside visibility rejected',count($run($other,$rows,$arp))===0);
$other=$hosts;$other['23']=$hosts['21'];
check('duplicate IP rejected',count($run($other,$rows,$arp))===0);
$stale=$rows;$stale[0]['lastclock']=$now-181;
check('stale item rejected',count($run($hosts,$stale,$arp))===0);
$skew=$rows;$skew[0]['lastclock']=$now-170;
check('skewed snapshot rejected',count($run($hosts,$skew,$arp))===0);
check('missing ARP identity rejected',count($run($hosts,$rows,[]))===0);
echo "All ZoneDirector builder tests passed.\n";
