<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require __DIR__ . '/../topology/PortIdPilotScope.php';
use Modules\NetworkTopology\Topology\PortIdPilotScope as Gate;

function checkScope(string $name, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    echo "PASS: $name\n";
}
checkScope('Default OFF', Gate::mode('', '', '', ['600'], false), null);
checkScope('Global explicit ON', Gate::mode('1', '', '', ['600'], false), 'all');
checkScope('Global disabled for multi-group', Gate::mode('1', '', '', ['600','601'], false), null);
checkScope('Global disabled in host mode', Gate::mode('1', '', '', ['600'], true), null);
checkScope('Only exact 1 enables global', Gate::mode('true', '', '', ['600'], false), null);
checkScope('Scoped opt-in', Gate::mode('', '600', '15326', ['600'], false), '15326');
checkScope('Scoped wrong group OFF', Gate::mode('', '600', '15326', ['601'], false), null);
checkScope('Scoped missing proxy OFF', Gate::mode('', '600', '', ['600'], false), null);
$hosts=['a'=>['proxyid'=>'15326'],'b'=>['proxyid'=>'0'],'c'=>['proxyid'=>'42']];
checkScope('Restricted proxy excludes other hosts',
    array_keys(Gate::eligibleHosts($hosts, '15326')), ['a']);
echo "PASS: Port-ID pilot scope\n";
