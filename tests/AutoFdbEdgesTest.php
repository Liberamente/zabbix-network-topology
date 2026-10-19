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

// CI image may not ship mbstring. All synthetic test port names are ASCII.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return strlen($s); }
    function mb_substr(string $s, int $start, ?int $length = null): string {
        return substr($s, $start, $length);
    }
}

use Modules\NetworkTopology\Topology\AutoFdbEdges;

$failed = 0;
function t(string $label, $got, $want): void {
    global $failed;
    $ok = $got === $want;
    if (!$ok) $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ' got=' . var_export($got, true)
         . ' want=' . var_export($want, true) . "\n";
}

$now = 1792354000;
$hosts = [
    '11' => ['host' => 'sw15', 'name' => 'Cudy .15', 'proxyid' => '100', 'inventory'=>[], 'interfaces'=>[]],
    '12' => ['host' => 'sw16', 'name' => 'Cudy .16', 'proxyid' => '100', 'inventory'=>[], 'interfaces'=>[]],
    '13' => ['host' => 'qnap', 'name' => 'QNAP', 'proxyid' => '100', 'inventory'=>[],
        'interfaces'=>[['ip'=>'192.168.2.8']],
        'tags' => [['tag'=>'nt:lldp','value'=>'24:5E:BE:40:F5:3F']]],
];
function row($host, $key, $value, $ts=null): array {
    global $now;
    return ['hostid'=>(string)$host, 'key_'=>$key, 'lastvalue'=>$value,
            'lastclock'=>$ts ?? $now - 30, 'status'=>'0','state'=>'0'];
}
function fdb($mac, $port, $vlan=1): string {
    $oct = array_map('hexdec', explode(':', strtolower($mac)));
    return '.1.3.6.1.2.1.17.7.1.2.2.1.2.' . $vlan . '.' . implode('.', $oct) . ' = INTEGER: ' . $port . "\n";
}
function sourceRows(): array {
    $mac='24:5e:be:40:f5:3f';
    $out=[];
    foreach (['11'=>24,'12'=>3] as $hid=>$port) {
        $out[] = row($hid, 'nt.fdb.dot1q', fdb($mac,$port));
        $out[] = row($hid, 'nt.fdb.baseport',
            '.1.3.6.1.2.1.17.1.4.1.2.' . $port . ' = INTEGER: ' . $port);
        $out[] = row($hid, 'nt.fdb.ifname',
            '.1.3.6.1.2.1.31.1.1.1.1.' . $port . ' = STRING: "GigabitEthernet' . $port . '"');
    }
    return $out;
}
$caps=[
    row('11','lldpRemSysCapEnabled[0.24.10]','20 00'),
    row('12','lldpRemSysCapEnabled[0.24.20]','20 00'),
];
function runCase($hosts,$rows,$caps,$edges=[],$manual=[],$metrics=[]): array {
    global $now;
    return AutoFdbEdges::append($hosts,$rows,$caps,$edges,$manual,$metrics,$now);
}

$got = runCase($hosts, sourceRows(), $caps);
t('QNAP: one inferred edge', count($got), 1);
t('QNAP: switch is .16', (string)($got[0]['from'] ?? ''), '12');
t('QNAP: target QNAP', (string)($got[0]['to'] ?? ''), '13');
t('QNAP: port GE3', $got[0]['ports']['12'] ?? null, 'GigabitEthernet3');
t('QNAP: source FDB', $got[0]['src'] ?? null, ['fdb']);
t('QNAP: unconfirmed inference', $got[0]['confirmed'] ?? null, false);
t('QNAP: from original VLAN', $got[0]['vlan'] ?? null, 1);

// Actual Cudy behavior: the neighbor capabilities are 14 00 (no Bridge
// bit), but the physical switch-to-switch LLDP edge identifies GE24 as uplink.
$cudyCaps = [
    row('11','lldpRemSysCapEnabled[0.24.10]','14 00'),
    row('12','lldpRemSysCapEnabled[0.24.20]','14 00'),
];
$switchLink = [['id'=>'lldp_switches','from'=>'11','to'=>'12','src'=>['lldp'],
    'ports'=>['11'=>'GigabitEthernet24','12'=>'GigabitEthernet24'],
    'port_idx'=>['11'=>'24','12'=>'24']]];
$cudyEdges=runCase($hosts,sourceRows(),$cudyCaps,$switchLink);
t('Real Cudy caps 14 00: edge added',count($cudyEdges),2);
t('Real Cudy caps: QNAP attached to switch .16',
    (string)($cudyEdges[1]['from'] ?? ''),'12');
t('Real Cudy caps: QNAP on GE3',
    $cudyEdges[1]['ports']['12'] ?? null,'GigabitEthernet3');
t('Real Cudy caps without LLDP switch edge: no guess',
    count(runCase($hosts,sourceRows(),$cudyCaps)),0);

$manual=['13'=>['host'=>'sw16','port'=>'GigabitEthernet3']];
t('Manual tag: override wins', count(runCase($hosts,sourceRows(),$caps,[], $manual)), 0);
$existing=[['id'=>'lldp1','from'=>'12','to'=>'13','src'=>['lldp'],
    'ports'=>['12'=>'GigabitEthernet3']]];
t('LLDP: no duplicate', count(runCase($hosts,sourceRows(),$caps,$existing)), 1);

$stale=sourceRows();
foreach($stale as &$r) $r['lastclock']=$now-1000;
unset($r);
t('Stale walks: no edge', count(runCase($hosts,$stale,$caps)), 0);
t('Missing capabilities: no guess', count(runCase($hosts,sourceRows(),[])),0);
$second=sourceRows();
$second[]=row('12','nt.fdb.dot1d',
    '.1.3.6.1.2.1.17.4.3.1.2.36.94.190.64.245.63 = INTEGER: 4');
$second[]=row('12','nt.fdb.baseport',
    '.1.3.6.1.2.1.17.1.4.1.2.3 = INTEGER: 3' . "\n" .
    '.1.3.6.1.2.1.17.1.4.1.2.4 = INTEGER: 4', $now-1);
$second[]=row('12','nt.fdb.ifname',
    '.1.3.6.1.2.1.31.1.1.1.1.3 = STRING: "GigabitEthernet3"' . "\n" .
    '.1.3.6.1.2.1.31.1.1.1.1.4 = STRING: "GigabitEthernet4"', $now-1);
t('Same MAC on two access ports: ambiguous', count(runCase($hosts,$second,$caps)),0);

$duplicate=$hosts;
$duplicate['14']=['host'=>'qnap2','name'=>'Duplicate','proxyid'=>'100',
    'inventory'=>['macaddress_a'=>'24:5E:BE:40:F5:3F'], 'interfaces'=>[]];
t('Duplicate MAC identities: no edge',count(runCase($duplicate,sourceRows(),$caps)),0);
$alien=$hosts;
$alien['13']['proxyid']='200';
t('Different proxy: no cross-client edge',count(runCase($alien,sourceRows(),$caps)),0);
$inventory=$hosts;
$inventory['13']['tags']=[];
$inventory['13']['inventory']=['macaddress_a'=>'24:5E:BE:40:F5:3F'];
t('Inventory MAC without tag',count(runCase($inventory,sourceRows(),$caps)),1);
$badname=$hosts;
$badname['13']['tags']=[['tag'=>'nt:lldp','value'=>'Cudy - 192.168.2.16']];
t('LLDP name not parsed as MAC',count(runCase($badname,sourceRows(),$caps)),0);

// ARP fallback, still constrained to same proxy and unique IP.
$arp=$hosts;
$arp['13']['tags']=[];
$arp['14']=['host'=>'router','name'=>'Router', 'proxyid'=>'100','interfaces'=>[]];
$rows=sourceRows();
$rows[]=row('14','nt.arp',
    '.1.3.6.1.2.1.4.22.1.2.1.192.168.2.8 = Hex-STRING: 24 5E BE 40 F5 3F');
t('ARP auto MAC discovery (same proxy)',count(runCase($arp,$rows,$caps)),1);
$arp['15']=['host'=>'dupip','name'=>'Duplicate IP', 'proxyid'=>'100',
    'interfaces'=>[['ip'=>'192.168.2.8']]];
t('Ambiguous IP: no ARP inference',count(runCase($arp,$rows,$caps)),0);

// Never synthesize a new edge when a switch does not classify its port.
$partialCaps=[$caps[0]];
t('Unclassified reporting switch: no edge',count(runCase($hosts,sourceRows(),$partialCaps)),0);

if ($failed) {
    fwrite(STDERR,"FAILURES: $failed\n");
    exit(1);
}
echo "All Auto FDB edge tests passed.\n";
