<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../actions/NetworkTopologyData.php');
$engine = file_get_contents(__DIR__ . '/../topology/PassThroughEvidence.php');
if (!is_string($controller) || !is_string($engine)) {
    throw new RuntimeException('Cannot inspect production contract source');
}
function guard(bool $ok, string $name): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    echo "PASS: $name\n";
}

guard(str_contains($controller, 'private const AUTO_PASS_THROUGH_EDGES = false;'),
    'feature switch defaults OFF');
guard(str_contains($controller, 'use Modules\NetworkTopology\Topology\PassThroughEvidence;'),
    'controller imports evidence class');
guard(str_contains($controller, 'PassThroughEvidence::propose('),
    'controller invokes proposal method');
guard(str_contains($controller, 'if (self::AUTO_PASS_THROUGH_EDGES && $fdb_switches && $caps)'),
    'proposal guarded by explicit enablement and sufficient source');
guard(str_contains($controller, 'count($groupids) === 1')
    && str_contains($controller, '&& !$host_mode'),
    'single-group and non-host-mode scope preserved');
guard(str_contains($controller, "'hostids'   => array_keys(\$fdb_switches)"),
    'CDP item search limited to visible FDB switch hosts');
guard(str_contains($controller, 'is_array($cdp_items) && $cdp_items'),
    'failed or empty CDP API responses do not add arcs');
guard(str_contains($controller, '$edges = array_merge($edges, $proposed);'),
    'append only proposed edges, preserve original graph');
guard(str_contains($engine, "'match' => 'pass_through_inferred'")
    && str_contains($engine, "'confirmed' => false")
    && str_contains($engine, "'port_metrics' => []"),
    'inference cannot claim confirmed cable or link traffic');
guard(!str_contains($engine, 'API::') && !str_contains($engine, 'file_put_contents('),
    'proposal engine is offline, read-only and independent of API');

echo "PassThroughHttpContractTest: PASS (synthetic, flag OFF)\n";
