<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/topology/LldpReconciledQuality.php';

use Modules\NetworkTopology\Topology\LldpReconciledQuality;

function checkQuality(bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

$quality = [
    '101' => [
        'matched' => 0,
        'unmatched' => [
            [
                'raw' => 'SYNTHETIC-PHONE',
                'src' => 'lldp',
                'index' => '0.15.99',
                'port' => '15',
            ],
            [
                'raw' => 'UNKNOWN-DEVICE',
                'src' => 'lldp',
                'index' => '0.16.100',
                'port' => '16',
            ],
        ],
    ],
];

$legacy = [
    'SYNTHETIC-PHONE (from hostid=101, src=lldp)',
    'UNKNOWN-DEVICE (from hostid=101, src=lldp)',
];

$edge = [
    'from' => '101',
    'to' => '102',
    'match' => 'lldp_portid',
    'lldp_index' => '0.15.99',
    'confirmed' => false,
    'evidence' => ['lldp_portid', 'arp', 'fdb'],
];

[$result, $remaining] = LldpReconciledQuality::apply(
    $quality, $legacy, [$edge]
);

checkQuality(
    count($result['101']['unmatched']) === 1,
    'Only one unmatched removed'
);
checkQuality(
    $result['101']['unmatched'][0]['raw'] === 'UNKNOWN-DEVICE',
    'Unrelated Ghost preserved'
);
checkQuality(
    $result['101']['matched'] === 1,
    'Matched counter updated'
);
checkQuality(
    count($remaining) === 1
        && str_contains($remaining[0], 'UNKNOWN-DEVICE'),
    'Legacy unmatched updated'
);

[$again, $againLegacy] = LldpReconciledQuality::apply(
    $result, $remaining, [$edge]
);
checkQuality(
    $again === $result && $againLegacy === $remaining,
    'Idempotent reconciliation'
);

$untraceable = $edge;
unset($untraceable['lldp_index']);

[$unchanged, $unchangedLegacy] = LldpReconciledQuality::apply(
    $quality, $legacy, [$untraceable]
);
checkQuality(
    $unchanged === $quality && $unchangedLegacy === $legacy,
    'Untraceable edge cannot remove Ghost'
);

$ambiguous = $quality;
$ambiguous['101']['unmatched'][] =
    $ambiguous['101']['unmatched'][0];

[$safe] = LldpReconciledQuality::apply(
    $ambiguous, $legacy, [$edge]
);
checkQuality(
    $safe === $ambiguous,
    'Duplicate LLDP identity rejected'
);

echo "PASS: LLDP reconciled quality\n";
