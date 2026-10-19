<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/' . implode('/', array_map('strtolower', $parts)) . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

use Modules\NetworkTopology\Topology\AutoWifiEdges;

$now = 1792406000;
$failed = 0;
function expect(string $name, $actual, $want): void {
    global $failed;
    if ($actual !== $want) {
        $failed++;
        echo '[FAIL] ' . $name . ': ' . var_export($actual, true)
            . ' want ' . var_export($want, true) . "\n";
    } else {
        echo '[PASS] ' . $name . "\n";
    }
}
function item(string $hid, string $key, string $value, int $offset = -30): array {
    global $now;
    return ['hostid'=>$hid, 'key_'=>$key, 'lastclock'=>$now + $offset,
        'lastvalue'=>$value, 'state'=>'0', 'status'=>'0'];
}
function wifi(string $mac, int $radio=3, int $signal=-60): string {
    $octets = array_map('hexdec', explode(':', $mac));
    return '.1.3.6.1.4.1.14988.1.1.1.2.1.3.' . implode('.', $octets)
        . '.' . $radio . ' = INTEGER: ' . $signal . "\n";
}
function arp(string $ip, string $mac): string {
    return '.1.3.6.1.2.1.4.22.1.2.9.' . $ip
        . ' = Hex-STRING: ' . implode(' ', explode(':', strtoupper($mac))) . "\n";
}
function runWifi(array $hosts, array $wifiRows, array $arpRows, array $edges=[], array $manual=[]): array {
    global $now;
    return AutoWifiEdges::append($hosts,$wifiRows,$arpRows,$edges,$manual,$now);
}
$mac='02:be:2c:88:25:79'; // actual locally-administered Wi-Fi MAC format
$hosts=[
    '20'=>['host'=>'ap1','name'=>'AP1','proxyid'=>'100','interfaces'=>[['ip'=>'192.168.2.4']]],
    '21'=>['host'=>'client','name'=>'Client','proxyid'=>'100','interfaces'=>[['ip'=>'192.168.2.147']], 'tags'=>[], 'inventory'=>[]],
    '22'=>['host'=>'router','name'=>'Router','proxyid'=>'100','interfaces'=>[['ip'=>'192.168.2.1']]],
    '23'=>['host'=>'switch','name'=>'Switch','proxyid'=>'100','interfaces'=>[]],
];
$w=[item('20','nt.wifi.clients',wifi($mac,3,-67))];
$a=[item('22','nt.arp',arp('192.168.2.147',$mac))];
$existing=[['id'=>'lldp1','from'=>'23','to'=>'20','src'=>['lldp']]];
$out=runWifi($hosts,$w,$a,$existing);
expect('LLDP AP-to-switch retained; Wi-Fi client added',count($out),2);
expect('Wi-Fi AP hostid',(string)($out[1]['from']??''),'20');
expect('Wi-Fi client hostid',(string)($out[1]['to']??''),'21');
expect('Wi-Fi src as array',$out[1]['src']??null,['wifi']);
expect('Wi-Fi type',$out[1]['iface']??null,'nt:wifi');
expect('Radio index',$out[1]['port_idx']['20']??null,'3');
expect('RSSI',$out[1]['signal_dbm']??null,-67);
expect('Not a confirmed wired cable',$out[1]['confirmed']??null,false);

expect('Existing cable wins',count(runWifi($hosts,$w,$a,[['from'=>'23','to'=>'21']])),1);
expect('Manual uplink wins',count(runWifi($hosts,$w,$a,[],['21'=>['host'=>'switch','port'=>'1']])),0);

$twice=$hosts;
$twice['24']=['host'=>'other','name'=>'Other','proxyid'=>'100',
    'interfaces'=>[['ip'=>'192.168.2.199']],'tags'=>[['tag'=>'nt:lldp','value'=>$mac]]];
expect('Duplicate MAC owner rejected',count(runWifi($twice,$w,$a)),0);
$cross=$hosts; $cross['21']['proxyid']='200';
expect('Different proxy rejected',count(runWifi($cross,$w,$a)),0);
$twowifi=$hosts;
$twowifi['25']=['host'=>'ap2','name'=>'AP2','proxyid'=>'100','interfaces'=>[]];
$roaming=[...$w,item('25','nt.wifi.clients',wifi($mac,8))];
expect('Simultaneous AP reports rejected',count(runWifi($twowifi,$roaming,$a)),0);
expect('Same AP multiple radios rejected',count(runWifi($hosts,[item('20','nt.wifi.clients',wifi($mac,3).wifi($mac,9))],$a)),0);
expect('Stale Wi-Fi ignored',count(runWifi($hosts,[item('20','nt.wifi.clients',wifi($mac),-190)],$a)),0);
expect('Stale ARP ignored',count(runWifi($hosts,$w,[item('22','nt.arp',arp('192.168.2.147',$mac),-901)])),0);
expect('Unsupported Wi-Fi ignored',count(runWifi($hosts,[array_replace($w[0],['state'=>'1'])],$a)),0);
expect('Unsupported ARP ignored',count(runWifi($hosts,$w,[array_replace($a[0],['state'=>'1'])])),0);
expect('Other OID ignored',count(runWifi($hosts,[item('20','nt.wifi.clients',str_replace('.1.3.6.1.4.1.14988.1.1.1.2.1.3', '.1.3.6.1.4.1.14988.1.1.1.2.1.99',wifi($mac)))],$a)),0);
expect('Malformed client OID ignored',count(runWifi($hosts,[item('20','nt.wifi.clients','.1.3.6.1.4.1.14988.1.1.1.2.1.3.999.1.2.3.4.5.3 = INTEGER: -42')],$a)),0);
$explicit=$hosts;
$explicit['21']['tags']=[['tag'=>'nt:lldp','value'=>$mac]];
expect('Identity from tag without ARP',count(runWifi($explicit,$w,[])),1);
$ambiguousIP=$hosts;
$ambiguousIP['24']=['host'=>'dupip','name'=>'Duplicate IP','proxyid'=>'100',
    'interfaces'=>[['ip'=>'192.168.2.147']]];
expect('Duplicate host IP rejected',count(runWifi($ambiguousIP,$w,$a)),0);
$badWifi=$w;
$badWifi[0]['lastvalue'] = wifi('01:00:5e:00:00:01');
expect('Multicast MAC ignored',count(runWifi($hosts,$badWifi,$a)),0);
expect('Unknown device not invented',count(runWifi($hosts,[item('20','nt.wifi.clients',wifi('02:aa:bb:cc:dd:ee'))],$a)),0);

if ($failed) exit(1);
echo "All Auto Wi-Fi edge tests passed.\n";
