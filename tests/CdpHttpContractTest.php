<?php
declare(strict_types=1);

require_once __DIR__ . '/../topology/CdpUnmatchedEvidence.php';

use Modules\NetworkTopology\Topology\CdpUnmatchedEvidence;

function checkContract(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$controller = file_get_contents(
    __DIR__ . '/../actions/NetworkTopologyData.php'
);

checkContract(
    str_contains($controller, 'CdpUnmatchedEvidence::classify('),
    'Classifier integrated'
);

checkContract(
    str_contains($controller,
        "'cdp_unmatched_evidence' => \$cdp_unmatched_evidence"),
    'Diagnostic field stored in core'
);

checkContract(
    str_contains($controller,
        "'cdp_unmatched_evidence' => \$core['cdp_unmatched_evidence'] ?? []"),
    'Diagnostic field exposed in HTTP payload'
);

$quality = [
    '101' => [
        'unmatched' => [
            [
                'src' => 'cdp',
                'raw' => '01 AC 11 02 52',
                'port' => '14'
            ]
        ]
    ]
];

$edges = [
    [
        'from' => '101',
        'to' => '201',
        'match' => 'lldp_portid',
        'confirmed' => false,
        'ports' => ['101' => '14'],
        'fdb_shared_port' => true
    ]
];

$original = serialize([$quality, $edges]);

$core = [
    'edges' => $edges,
    'lldp_quality' => $quality,
    'lldp_unmatched' => ['original'],
    'cdp_unmatched_evidence' =>
        CdpUnmatchedEvidence::classify($quality, $edges)
];

$cached = unserialize(serialize($core));

foreach ([$core, $cached] as $payload) {
    checkContract(
        $payload['edges'] === $edges,
        'Edges preserved'
    );
    checkContract(
        $payload['lldp_quality'] === $quality,
        'Quality preserved'
    );
    checkContract(
        $payload['lldp_unmatched'] === ['original'],
        'Unmatched preserved'
    );
    checkContract(
        count($payload['cdp_unmatched_evidence']) === 1,
        'One diagnostic observation'
    );
    checkContract(
        $payload['cdp_unmatched_evidence'][0]['status']
            === 'associated_inferred',
        'Inferred association retained'
    );
    checkContract(
        $payload['cdp_unmatched_evidence'][0]['identity_confirmed']
            === false,
        'Identity never confirmed by port alone'
    );
}

checkContract(
    serialize([$quality, $edges]) === $original,
    'Inputs unchanged'
);

echo "CdpHttpContractTest: PASS (synthetic)\n";
