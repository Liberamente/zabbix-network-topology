<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Synthetic CDP diagnostic regression; no real device data or DB.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = substr($class, strlen($prefix));
    $parts = explode('\\', $rel);
    $name = array_pop($parts);
    $path = dirname(__DIR__) . '/' . strtolower(implode('/', $parts)) . '/' . $name . '.php';
    if (is_file($path)) require_once $path;
});
use Modules\NetworkTopology\Topology\LldpEdgeBuilder;
function verify(bool $ok, string $msg): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
$hosts = [
  '100' => ['host'=>'switch-lab', 'name'=>'Switch Lab', 'interfaces'=>[]],
  '101' => ['host'=>'endpoint-a', 'name'=>'Endpoint A', 'interfaces'=>[]],
  '102' => ['host'=>'endpoint-b', 'name'=>'Endpoint B', 'interfaces'=>[]],
];
$raw = [];
foreach (['1.10','1.11','1.12'] as $index) {
  $raw[]=['hostid'=>'100','key_'=>"cdpCacheDeviceId[$index]",'lastvalue'=>'01','src'=>'cdp'];
}
$result=LldpEdgeBuilder::build($hosts,$raw);
verify(count($result['edges']) === 0, 'repeated generic CDP ID cannot invent a physical host link');
verify(count($result['unmatched']) === 3, 'each unmatched observation is retained');
foreach ($result['unmatched'] as $entry) {
  verify(str_starts_with($entry,'01 '), 'unmatched raw identifier remains available for audit');
}
$raw[0]['lastvalue']='endpoint-a';
$matched=LldpEdgeBuilder::build($hosts,$raw);
verify(count($matched['edges']) === 1, 'known exact CDP identity resolves without suppressing independent observations');
verify((string)$matched['edges'][0]['from'] === '100' || (string)$matched['edges'][0]['to'] === '100', 'reporter endpoint preserved');
verify(count($matched['unmatched']) === 2, 'other generic IDs remain unmatched');
// Five-byte CDP identifiers are opaque, not Ethernet MACs.
// Never shorten them to "01" and never resolve them to a host named "01".
$hostsOpaque = [
    '100' => ['host'=>'switch-lab', 'name'=>'Switch Lab', 'interfaces'=>[]],
    '101' => ['host'=>'01', 'name'=>'Short Token Host', 'interfaces'=>[]],
    '102' => ['host'=>'endpoint-a', 'name'=>'Endpoint A', 'interfaces'=>[]],
];
$opaqueRaw = [[
    'hostid'=>'100',
    'key_'=>'cdpCacheDeviceId[7.1]',
    'lastvalue'=>'01 02 03 04 05',
    'src'=>'cdp',
]];
$opaque = LldpEdgeBuilder::build($hostsOpaque, $opaqueRaw);
verify(count($opaque['edges']) === 0, 'five-byte hex cannot become an edge to host 01');
verify(count($opaque['unmatched']) === 1, 'five-byte hex remains unmatched');
verify(str_contains($opaque['unmatched'][0], '01 02 03 04 05'), 'five-byte identifier retained intact');
$macRaw = $opaqueRaw;
$macRaw[0]['lastvalue'] = '02 11 22 33 44 55';
$mac = LldpEdgeBuilder::build($hostsOpaque, $macRaw);
verify(count($mac['edges']) === 0, 'unknown six-byte MAC does not invent a host edge');
verify(count($mac['unmatched']) === 1, 'unknown six-byte MAC retained as unresolved');
echo "CdpGenericNeighborRegression: PASS (synthetic, no production writes)\n";
