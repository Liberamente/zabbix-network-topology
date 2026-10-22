<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/'
        . implode('/', array_map('strtolower', $parts))
        . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return strlen($s); }
    function mb_substr(string $s, int $start, ?int $length = null): string {
        return substr($s, $start, $length);
    }
}

use Modules\NetworkTopology\Topology\LldpPortIdFeed;

$failures = 0;
$now = 1792354000;

function feedCheck(string $label, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) ++$failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ')
        . $label
        . ($ok ? '' : ' got=' . var_export($got, true)
            . ' want=' . var_export($want, true))
        . "\n";
}

function feedItem(string $hostid, string $key, string $value): array {
    global $now;
    return [
        'hostid' => $hostid,
        'key_' => $key,
        'lastvalue' => $value,
        'lastclock' => $now - 30,
        'status' => '0',
        'state' => '0',
    ];
}

if (!class_exists(LldpPortIdFeed::class)) {
    fwrite(STDERR, "[EXPECTED RED] LldpPortIdFeed missing\n");
    exit(1);
}

$hosts = [
    '101' => [
        'host'=>'synthetic-switch', 'name'=>'Synthetic Switch',
        'proxyid'=>'100', 'interfaces'=>[], 'tags'=>[], 'inventory'=>[],
    ],
    '102' => [
        'host'=>'synthetic-phone', 'name'=>'Synthetic Phone',
        'proxyid'=>'100',
        'interfaces'=>[['ip'=>'192.0.2.102']],
        'tags'=>[], 'inventory'=>[],
    ],
    '103' => [
        'host'=>'synthetic-router', 'name'=>'Synthetic Router',
        'proxyid'=>'100', 'interfaces'=>[], 'tags'=>[], 'inventory'=>[],
    ],
];

$lldp = [
    feedItem('101', 'lldpRemSysName[0.501.1]', 'OLD-PHONE'),
    feedItem('101', 'lldpRemPortId[0.501.1]', '02 00 00 00 01 02'),
];

$fdb = [
    feedItem('101', 'nt.fdb.dot1q',
        '.1.3.6.1.2.1.17.7.1.2.2.1.2.10.2.0.0.0.1.2 = INTEGER: 5'),
    feedItem('101', 'nt.fdb.baseport',
        '.1.3.6.1.2.1.17.1.4.1.2.5 = INTEGER: 17'),
    feedItem('101', 'nt.fdb.ifname',
        '.1.3.6.1.2.1.31.1.1.1.1.17 = STRING: "Gi1/0/5"'),
    feedItem('103', 'nt.arp',
        '.1.3.6.1.2.1.4.22.1.2.1.192.0.2.102 = Hex-STRING: 02 00 00 00 01 02'),
];

$local = [
    feedItem('101', 'nt.lldp.local.portid',
        '.1.0.8802.1.1.2.1.3.7.1.3.501 = STRING: "Gi1/0/5"'),
    feedItem('101', 'nt.lldp.local.portdesc',
        '.1.0.8802.1.1.2.1.3.7.1.4.501 = STRING: "Gi1/0/5"'),
];

function runFeed(
    array $hosts, array $lldp, array $fdb,
    array $local, array $edges = []
): array {
    global $now;
    return LldpPortIdFeed::append(
        $hosts, $lldp, $fdb, $local, $edges, $now
    );
}

$result = runFeed($hosts, $lldp, $fdb, $local);

feedCheck('One reconciled association', count($result), 1);
feedCheck('Inferred match', $result[0]['match'] ?? null, 'lldp_portid');
feedCheck('Not physically confirmed',
    $result[0]['confirmed'] ?? null, false);
feedCheck('Switch and endpoint',
    [$result[0]['from'] ?? null, $result[0]['to'] ?? null],
    ['101', '102']);

$stale = $fdb;
$stale[1]['lastclock'] = $now - 901;
feedCheck('Stale bridge mapping rejected',
    count(runFeed($hosts, $lldp, $stale, $local)), 0);

$disabled = $fdb;
$disabled[2]['status'] = '1';
feedCheck('Disabled interface mapping rejected',
    count(runFeed($hosts, $lldp, $disabled, $local)), 0);

$missingLocal = [];
feedCheck('Missing LLDP local mapping rejected',
    count(runFeed($hosts, $lldp, $fdb, $missingLocal)), 0);

$otherProxy = $hosts;
$otherProxy['102']['proxyid'] = '200';
feedCheck('Cross-proxy association rejected',
    count(runFeed($otherProxy, $lldp, $fdb, $local)), 0);

$manual = $hosts;
$manual['102']['tags'] = [
    ['tag'=>'nt:uplink', 'value'=>'synthetic-switch:Gi1/0/5'],
];
feedCheck('Manual uplink preserved',
    count(runFeed($manual, $lldp, $fdb, $local)), 0);

$existing = [[
    'id'=>'existing_lldp',
    'from'=>'101', 'to'=>'102',
    'ports'=>['101'=>'Gi1/0/5'],
    'match'=>'lldp', 'src'=>['lldp'],
]];
feedCheck('Existing LLDP edge unchanged',
    runFeed($hosts, $lldp, $fdb, $local, $existing),
    $existing);

$wifiExisting = [[
    'id'=>'existing_wifi',
    'from'=>'101', 'to'=>'102',
    'match'=>'wifi', 'src'=>['wifi'],
]];
feedCheck('Existing Wi-Fi association preserved',
    runFeed($hosts, $lldp, $fdb, $local, $wifiExisting),
    $wifiExisting);

$conflicting = $fdb;
$conflicting[] = feedItem('101', 'nt.fdb.dot1q',
    '.1.3.6.1.2.1.17.7.1.2.2.1.2.10.2.0.0.0.1.2 = INTEGER: 6');
feedCheck('Conflicting duplicate FDB walk rejected',
    count(runFeed($hosts, $lldp, $conflicting, $local)), 0);

echo $failures
    ? "FAIL: $failures checks\n"
    : "PASS: LLDP Port-ID feed integration\n";
exit($failures ? 1 : 0);
