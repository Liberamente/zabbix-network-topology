<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/topology/LldpReconciledQuality.php';

use Modules\NetworkTopology\Topology\LldpReconciledQuality;

$hosts = [
    '101' => ['proxyid' => '900', 'tags' => []],
    '102' => ['proxyid' => '900', 'tags' => []],
    '103' => ['proxyid' => '900', 'tags' => []],
];

$quality = [
    '101' => [
        'matched' => 0,
        'unmatched' => [[
            'raw' => 'SYNTHETIC-DEVICE',
            'src' => 'lldp',
            'index' => '0.15.99',
            'port' => '15',
        ]],
    ],
];

$legacy = [
    'SYNTHETIC-DEVICE (from hostid=101, src=lldp)',
];

$edges = [[
    'id' => 'synthetic-cdp',
    'from' => '101',
    'to' => '102',
    'match' => 'ip',
    'src' => ['cdp'],
    'ports' => ['101' => '15'],
    'confirmed' => false,
]];

$base = [
    'reporter_hostid' => '101',
    'target_hostid' => '102',
    'switch_port' => '15',
    'lldp_index' => '0.15.99',
    'evidence' => ['lldp_portid', 'arp', 'fdb'],
    'confirmed' => false,
];

$conflicting = $base;
$conflicting['target_hostid'] = '103';

[$after, $afterLegacy] =
    LldpReconciledQuality::reconcileExisting(
        $quality,
        $legacy,
        [$base, $conflicting],
        $edges,
        $hosts
    );

if ($after !== $quality || $afterLegacy !== $legacy) {
    fwrite(
        STDERR,
        "FAIL: conflicting LLDP identity reconciled\n"
    );
    exit(1);
}

echo "PASS: conflicting LLDP identity rejected\n";
