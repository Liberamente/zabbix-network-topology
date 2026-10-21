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
    '11' => ['host' => 'sw15', 'name' => 'Synthetic switch 1', 'proxyid' => '100', 'inventory'=>[], 'interfaces'=>[]],
    '12' => ['host' => 'sw16', 'name' => 'Synthetic switch 2', 'proxyid' => '100', 'inventory'=>[], 'interfaces'=>[]],
    '13' => ['host' => 'storage', 'name' => 'NAS', 'proxyid' => '100', 'inventory'=>[],
        'interfaces'=>[['ip'=>'192.0.2.8']],
        'tags' => [['tag'=>'nt:lldp','value'=>'02:10:20:30:40:50']]],
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
    $mac='02:10:20:30:40:50';
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
t('NAS: one inferred edge', count($got), 1);
t('NAS: switch is .16', (string)($got[0]['from'] ?? ''), '12');
t('NAS: target NAS', (string)($got[0]['to'] ?? ''), '13');
t('NAS: port GE3', $got[0]['ports']['12'] ?? null, 'GigabitEthernet3');
t('NAS: source FDB', $got[0]['src'] ?? null, ['fdb']);
t('NAS: unconfirmed inference', $got[0]['confirmed'] ?? null, false);
t('NAS: from original VLAN', $got[0]['vlan'] ?? null, 1);

// Synthetic switch behavior: the neighbor capabilities are 14 00 (no Bridge
// bit), but the physical switch-to-switch LLDP edge identifies GE24 as uplink.
$syntheticCaps = [
    row('11','lldpRemSysCapEnabled[0.24.10]','14 00'),
    row('12','lldpRemSysCapEnabled[0.24.20]','14 00'),
];
$switchLink = [['id'=>'lldp_switches','from'=>'11','to'=>'12','src'=>['lldp'],
    'ports'=>['11'=>'GigabitEthernet24','12'=>'GigabitEthernet24'],
    'port_idx'=>['11'=>'24','12'=>'24']]];
$syntheticEdges=runCase($hosts,sourceRows(),$syntheticCaps,$switchLink);
t('Synthetic switch caps 14 00: edge added',count($syntheticEdges),2);
t('Synthetic switch caps: NAS attached to switch .16',
    (string)($syntheticEdges[1]['from'] ?? ''),'12');
t('Synthetic switch caps: NAS on GE3',
    $syntheticEdges[1]['ports']['12'] ?? null,'GigabitEthernet3');
// Regression: real Synthetic switch LLDP reports numeric port labels ("24"),
// while FDB resolves the same ifIndex to "GigabitEthernet24".
$switchLinkNumeric = $switchLink;
$switchLinkNumeric[0]['ports'] = ['11'=>'24', '12'=>'24'];

$syntheticNumeric = runCase($hosts, sourceRows(), $syntheticCaps, $switchLinkNumeric);

t('Synthetic switch numeric LLDP: NAS edge created',
    count($syntheticNumeric), 2);
t('Synthetic switch numeric LLDP: NAS on GE3',
    $syntheticNumeric[1]['ports']['12'] ?? null, 'GigabitEthernet3');

t('Synthetic switch caps without LLDP switch edge: no guess',
    count(runCase($hosts,sourceRows(),$syntheticCaps)),0);

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
    '.1.3.6.1.2.1.17.4.3.1.2.2.16.32.48.64.80 = INTEGER: 4');
$second[]=row('12','nt.fdb.baseport',
    '.1.3.6.1.2.1.17.1.4.1.2.3 = INTEGER: 3' . "\n" .
    '.1.3.6.1.2.1.17.1.4.1.2.4 = INTEGER: 4', $now-1);
$second[]=row('12','nt.fdb.ifname',
    '.1.3.6.1.2.1.31.1.1.1.1.3 = STRING: "GigabitEthernet3"' . "\n" .
    '.1.3.6.1.2.1.31.1.1.1.1.4 = STRING: "GigabitEthernet4"', $now-1);
t('Same MAC on two access ports: ambiguous', count(runCase($hosts,$second,$caps)),0);

$duplicate=$hosts;
$duplicate['14']=['host'=>'storage2','name'=>'Duplicate','proxyid'=>'100',
    'inventory'=>['macaddress_a'=>'02:10:20:30:40:50'], 'interfaces'=>[]];
t('Duplicate MAC identities: no edge',count(runCase($duplicate,sourceRows(),$caps)),0);
$alien=$hosts;
$alien['13']['proxyid']='200';
t('Different proxy: no cross-client edge',count(runCase($alien,sourceRows(),$caps)),0);
$inventory=$hosts;
$inventory['13']['tags']=[];
$inventory['13']['inventory']=['macaddress_a'=>'02:10:20:30:40:50'];
t('Inventory MAC without tag',count(runCase($inventory,sourceRows(),$caps)),1);
$badname=$hosts;
$badname['13']['tags']=[['tag'=>'nt:lldp','value'=>'Synthetic switch B']];
t('LLDP name not parsed as MAC',count(runCase($badname,sourceRows(),$caps)),0);

// ARP fallback, still constrained to same proxy and unique IP.
$arp=$hosts;
$arp['13']['tags']=[];
$arp['14']=['host'=>'router','name'=>'Router', 'proxyid'=>'100','interfaces'=>[]];
$rows=sourceRows();
$rows[]=row('14','nt.arp',
    '.1.3.6.1.2.1.4.22.1.2.1.192.0.2.8 = Hex-STRING: 02 10 20 30 40 50');
t('ARP auto MAC discovery (same proxy)',count(runCase($arp,$rows,$caps)),1);
$arp['15']=['host'=>'dupip','name'=>'Duplicate IP', 'proxyid'=>'100',
    'interfaces'=>[['ip'=>'192.0.2.8']]];
t('Ambiguous IP: no ARP inference',count(runCase($arp,$rows,$caps)),0);

// Never synthesize a new edge when a switch does not classify its port.
$partialCaps=[$caps[0]];
t('Unclassified reporting switch: no edge',count(runCase($hosts,sourceRows(),$partialCaps)),0);

// HPE/Aruba aggregated trunks have a logical FDB ifName such as Trk1;
// the existing LLDP inter-switch edges know physical member 45/46.
// A learned MAC on Trk1 must never turn into a false access cable.
$lagHosts = $hosts;
$lagHosts['14'] = ['host'=>'extra','name'=>'Extra device','proxyid'=>'100',
    'inventory'=>['macaddress_a'=>'02:10:20:30:40:55'], 'interfaces'=>[]];
$lagRows = sourceRows();
$lagRows[] = row('12','nt.fdb.dot1q',
    fdb('02:10:20:30:40:50', 3) . fdb('02:10:20:30:40:55', 532), $now-1);
$lagRows[] = row('12','nt.fdb.baseport',
    '.1.3.6.1.2.1.17.1.4.1.2.3 = INTEGER: 3' . "\n" .
    '.1.3.6.1.2.1.17.1.4.1.2.532 = INTEGER: 532', $now-1);
$lagRows[] = row('12','nt.fdb.ifname',
    '.1.3.6.1.2.1.31.1.1.1.1.3 = STRING: "GigabitEthernet3"' . "\n" .
    '.1.3.6.1.2.1.31.1.1.1.1.532 = STRING: "Trk1"', $now-1);
$lagResult = runCase($lagHosts,$lagRows,$caps);
t('Logical HPE Trk1 FDB is NOT an access link',count($lagResult),1);
t('Only physical access port survives aggregate filtering',
    $lagResult[0]['ports']['12'] ?? null, 'GigabitEthernet3');

// If two distinct monitored MACs share one switch access port, show two
// INFERRED associations with an explicit shared-port warning. Do not guess
// whether an IP phone, unmanaged switch or wireless AP lies between them.
$sharedHosts = $hosts;
$sharedHosts['14'] = ['host'=>'extra','name'=>'Additional endpoint','proxyid'=>'100',
    'inventory'=>['macaddress_a'=>'02:10:20:30:40:51'], 'interfaces'=>[]];
$sharedRows=sourceRows();
$sharedRows[]=row('12','nt.fdb.dot1q',
    fdb('02:10:20:30:40:50',3) . fdb('02:10:20:30:40:51',3), $now-1);
$sharedResult=runCase($sharedHosts,$sharedRows,$caps);
t('Two MACs on shared physical port both appear as inferred',count($sharedResult),2);
t('Shared port annotated for both inferred edges',
    count(array_filter($sharedResult, static fn($e) => $e['fdb_shared_port'] ?? false)),2);

$withMetrics = runCase($hosts,sourceRows(),$caps,[],[],[
    'port_names'=>['12'=>['3'=>'GigabitEthernet3']],
    'port_traffic'=>['12'=>['3'=>['in'=>2400,'out'=>1800]]],
]);
t('Measured switch port counters do not become individual MAC traffic',
    $withMetrics[0]['port_metrics'] ?? 'missing', []);

// A host with an existing parent edge is not genuinely disconnected;
// normal controller attaches FDB only after parent/containment edges.
$withParent = [['id'=>'hosts_1','from'=>'12','to'=>'13','_type'=>'hosts']];
t('Containment edge prevents redundant FDB link',
    count(runCase($hosts,sourceRows(),$caps,$withParent)),1);

// The other MAC need not belong to a monitored Zabbix host. It may be
// a phone, WLAN station or unmanaged bridge: the port is still shared.
$unmonitored = sourceRows();
$unmonitored[] = row('12', 'nt.fdb.dot1q',
    fdb('02:10:20:30:40:50', 3) . fdb('02:10:20:30:40:58', 3), $now-1);
$onlyKnown = runCase($hosts, $unmonitored, $caps);
t('One known endpoint remains mapped with unknown second MAC',
    count($onlyKnown), 1);
t('Unmonitored MAC on the same port still marks shared inference',
    $onlyKnown[0]['fdb_shared_port'] ?? null, true);

if ($failed) {
    fwrite(STDERR,"FAILURES: $failed\n");
    exit(1);
}
echo "All Auto FDB edge tests passed.\n";
