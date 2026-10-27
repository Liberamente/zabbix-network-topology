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
echo "CdpGenericNeighborRegression: PASS (synthetic, no production writes)\n";
