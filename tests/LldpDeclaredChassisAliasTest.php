<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Synthetic-only regression: no customer MACs, IPs, hostnames or SNMP dumps.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/' .
        implode('/', array_map('strtolower', $parts)) . '/' . $file . '.php';
    if (is_file($path)) require $path;
});
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string {
        return substr($s, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return strlen($s); }
}

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;

$failed = 0;
function verify(string $label, $got, $want): void {
    global $failed;
    if ($got !== $want) {
        $failed++;
        echo "[FAIL] $label got=" . var_export($got, true)
            . " want=" . var_export($want, true) . "\n";
    } else {
        echo "[PASS] $label\n";
    }
}
function syntheticHosts(): array {
    return [
        '101' => ['host'=>'sw-a', 'name'=>'Switch A', 'proxyid'=>'400',
            'nt_aliases'=>[], 'interfaces'=>[]],
        '201' => ['host'=>'endpoint-a', 'name'=>'Endpoint A',
            'proxyid'=>'400', 'nt_aliases'=>['02:11:22:33:44:55'],
            'interfaces'=>[]],
        '202' => ['host'=>'endpoint-b', 'name'=>'Endpoint B',
            'proxyid'=>'400', 'nt_aliases'=>[], 'interfaces'=>[]],
    ];
}
function runLldp(array $hosts, string $name='localhost',
        string $chassis='02:11:22:33:44:55', string $source='lldp',
        string $idx='0.7.1'): array {
    $key = $source === 'lldp'
        ? 'lldpRemSysName[' . $idx . ']'
        : 'cdpCacheDeviceId[7.1]';
    $raw = [['hostid'=>'101','key_'=>$key,
        'lastvalue'=>$name, 'src'=>$source]];
    $metaIdx = $source === 'lldp' ? $idx : '7.1';
    $meta = ['101'=>[$metaIdx=>['chassis'=>$chassis]]];
    return LldpEdgeBuilder::build($hosts, $raw, [], [], [], $meta);
}

$base = syntheticHosts();
$ok = runLldp($base);
verify('unique declared chassis MAC resolves generic name', count($ok['edges']), 1);
verify('correct endpoint', (string) ($ok['edges'][0]['to'] ?? ''), '201');
verify('LLDP provenance preserved', $ok['edges'][0]['src'] ?? [], ['lldp']);
verify('declared alias match', $ok['edges'][0]['match'] ?? '', 'alias');
verify('one-sided LLDP is NOT confirmed', $ok['edges'][0]['confirmed'] ?? null, false);
verify('unmatched cleared only for verified alias', count($ok['unmatched']), 0);

$duplicate = $base;
$duplicate['202']['nt_aliases'] = ['02-11-22-33-44-55'];
$r = runLldp($duplicate);
verify('duplicate chassis declaration creates NO edge', count($r['edges']), 0);
verify('duplicate declaration remains unmatched', count($r['unmatched']), 1);

$otherProxy = $base;
$otherProxy['201']['proxyid'] = '401';
verify('cross-proxy chassis match prohibited',
    count(runLldp($otherProxy)['edges']), 0);

$noProxy = $base;
unset($noProxy['101']['proxyid']);
verify('missing proxy fails closed', count(runLldp($noProxy)['edges']), 0);

$noAlias = $base;
$noAlias['201']['nt_aliases'] = [];
verify('no declared identity, no invented edge',
    count(runLldp($noAlias)['edges']), 0);

$outOfScope = $base;
unset($outOfScope['201']);
verify('host absent from scope cannot be linked',
    count(runLldp($outOfScope)['edges']), 0);

verify('zero chassis rejected',
    count(runLldp($base, 'localhost', '00:00:00:00:00:00')['edges']), 0);

verify('CDP cannot inherit LLDP chassis matcher',
    count(runLldp($base, 'localhost', '02:11:22:33:44:55', 'cdp')['edges']), 0);

$nameWins = runLldp($base, 'endpoint-b');
verify('already matched real name keeps precedence',
    (string) ($nameWins['edges'][0]['to'] ?? ''), '202');

$zero = runLldp($base, 'localhost', '02:11:22:33:44:55', 'lldp', '0.0.1');
verify('LLDP zero local port does not create physical edge',
    count($zero['edges']), 0);

// Two distinct devices can announce the SAME generic SysName. Their
// independently and uniquely declared chassis MACs must not be collapsed.
$twins = $base;
$twins['202']['nt_aliases'] = ['02:11:22:33:44:66'];
$twinsRaw = [
    ['hostid'=>'101', 'key_'=>'lldpRemSysName[0.7.1]',
        'lastvalue'=>'localhost', 'src'=>'lldp'],
    ['hostid'=>'101', 'key_'=>'lldpRemSysName[0.8.1]',
        'lastvalue'=>'localhost', 'src'=>'lldp'],
];
$twinsMeta = ['101'=>[
    '0.7.1'=>['chassis'=>'02:11:22:33:44:55'],
    '0.8.1'=>['chassis'=>'02:11:22:33:44:66'],
]];
$twinsResult = LldpEdgeBuilder::build($twins, $twinsRaw, [], [], [], $twinsMeta);
verify('two identical generic names remain two distinct hosts',
    count($twinsResult['edges']), 2);
$destinations = array_map(
    static fn(array $e): string => (string) ($e['to'] ?? ''),
    $twinsResult['edges']
);
sort($destinations);
verify('both explicit identities resolved independently',
    $destinations, ['201','202']);

if ($failed) {
    echo "LLDP_DECLARED_CHASSIS_TEST=FAIL $failed\n";
    exit(1);
}
echo "LLDP_DECLARED_CHASSIS_TEST=PASS\n";
