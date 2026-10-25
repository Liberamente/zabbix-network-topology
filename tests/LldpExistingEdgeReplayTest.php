<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/topology/LldpReconciledQuality.php';
require_once dirname(__DIR__)
    . '/topology/LldpPortIdIdentity.php';

use Modules\NetworkTopology\Topology\LldpReconciledQuality;
use Modules\NetworkTopology\Topology\LldpPortIdIdentity;

function checkReplay(bool $ok, string $description): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $description\n");
        exit(1);
    }
    echo "PASS: $description\n";
}

$hosts = [
    '101' => ['proxyid' => '900', 'tags' => []],
    '102' => ['proxyid' => '900', 'tags' => []],
    '103' => ['proxyid' => '900', 'tags' => []],
    '104' => ['proxyid' => '900', 'tags' => []],
];

$edges = [
    [
        'id' => 'cdp-endpoint',
        'from' => '101',
        'to' => '102',
        'src' => ['cdp'],
        'match' => 'ip',
        'ports' => ['101' => '15', '102' => 'eth0'],
        'confirmed' => false,
        'confidence' => 60,
    ],
    [
        'id' => 'trunk-a',
        'from' => '101',
        'to' => '103',
        'src' => ['cdp', 'lldp'],
        'match' => 'alias',
        'ports' => ['101' => '47', '103' => '21'],
        'confirmed' => true,
    ],
    [
        'id' => 'trunk-b',
        'from' => '101',
        'to' => '103',
        'src' => ['cdp', 'lldp'],
        'match' => 'alias',
        'ports' => ['101' => '48', '103' => '22'],
        'confirmed' => true,
    ],
];

$candidates = [[
    'reporter_hostid' => '101',
    'target_hostid' => '102',
    'switch_port' => '15',
    'lldp_index' => '0.15.99',
    'fdb_shared_port' => false,
    'confirmed' => false,
    'evidence' => ['lldp_portid', 'arp', 'fdb'],
]];

$quality = [
    '101' => [
        'matched' => 2,
        'unmatched' => [
            [
                'raw' => 'SYNTHETIC-PHONE',
                'src' => 'lldp',
                'index' => '0.15.99',
                'port' => '15',
            ],
            [
                'raw' => 'OTHER-DEVICE',
                'src' => 'lldp',
                'index' => '0.16.100',
                'port' => '16',
            ],
        ],
    ],
];

$unmatched = [
    'SYNTHETIC-PHONE (from hostid=101, src=lldp)',
    'OTHER-DEVICE (from hostid=101, src=lldp)',
];

$before = $edges;

$afterEdges = LldpPortIdIdentity::appendEdges(
    $hosts, $candidates, $edges
);

checkReplay(
    $afterEdges === $before,
    'No duplicate edge created'
);

[$afterQuality, $afterUnmatched] =
    LldpReconciledQuality::reconcileExisting(
        $quality, $unmatched, $candidates, $afterEdges, $hosts
    );

checkReplay(
    $afterEdges[0] === $before[0],
    'Existing CDP edge unchanged'
);

checkReplay(
    count($afterEdges) === 3,
    'Parallel trunk edges preserved'
);

checkReplay(
    count($afterQuality['101']['unmatched']) === 1
        && $afterQuality['101']['unmatched'][0]['raw']
            === 'OTHER-DEVICE',
    'Matching Ghost removed, unrelated Ghost preserved'
);

checkReplay(
    $afterQuality['101']['matched'] === 3,
    'Matched counter updated'
);

checkReplay(
    count($afterUnmatched) === 1,
    'Legacy unmatched reconciled'
);

[$againQuality, $againUnmatched] =
    LldpReconciledQuality::reconcileExisting(
        $afterQuality, $afterUnmatched,
        $candidates, $afterEdges, $hosts
    );

checkReplay(
    $againQuality === $afterQuality
        && $againUnmatched === $afterUnmatched,
    'Idempotent replay'
);

echo "PASS: existing-edge replay completed\n";
