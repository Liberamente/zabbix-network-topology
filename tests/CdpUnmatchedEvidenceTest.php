<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require_once __DIR__ . '/../topology/CdpUnmatchedEvidence.php';

use Modules\NetworkTopology\Topology\CdpUnmatchedEvidence;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$quality = [
    '101' => [
        'unmatched' => [
            ['src' => 'cdp', 'raw' => '01 AC 11 02 52', 'port' => '14'],
            ['src' => 'cdp', 'raw' => '01 AC 11 02 53', 'port' => '15'],
            ['src' => 'cdp', 'raw' => '01 AC 11 02 54', 'port' => '16'],
            ['src' => 'cdp', 'raw' => '01 AC 11 02 55', 'port' => '17'],
            ['src' => 'lldp', 'raw' => 'other', 'port' => '14'],
        ],
    ],
];
$edges = [
    ['from' => '101', 'to' => '201', 'match' => 'lldp_portid',
     'confirmed' => false, 'ports' => ['101' => '14'],
     'fdb_shared_port' => true],
    ['from' => '101', 'to' => '202', 'match' => 'lldp_portid',
     'confirmed' => false, 'ports' => ['101' => '15'],
     'fdb_shared_port' => false],
    ['from' => '101', 'to' => '203', 'match' => 'lldp_portid',
     'confirmed' => false, 'ports' => ['101' => '16']],
    ['from' => '101', 'to' => '204', 'match' => 'fdb',
     'confirmed' => false, 'ports' => ['101' => '16']],
    // Verified physical edges must never be repurposed as identity evidence.
    ['from' => '101', 'to' => '205', 'match' => 'alias',
     'confirmed' => true, 'ports' => ['101' => '17']],
];
$before = serialize([$quality, $edges]);
$result = CdpUnmatchedEvidence::classify($quality, $edges);
check(count($result) === 4, 'Only CDP unmatched observations are returned');
check($result[0]['status'] === 'associated_inferred', 'Shared candidate classified');
check($result[0]['shared_port'] === true, 'Shared port flag retained');
check($result[0]['candidate_count'] === 1, 'Unique candidate counted');
check($result[1]['status'] === 'associated_inferred', 'Unshared candidate classified');
check($result[1]['shared_port'] === false, 'Unshared port retained');
check($result[2]['status'] === 'ambiguous', 'Two targets are ambiguous');
check($result[2]['candidate_count'] === 2, 'Ambiguous targets counted');
check($result[3]['status'] === 'unmatched', 'Verified edge alone not a CDP identity match');
check(!array_filter($result, static fn(array $r): bool => $r['identity_confirmed']),
      'Never confirm identity by port coincidence');
check(serialize([$quality, $edges]) === $before, 'Inputs remain unchanged');
echo "CdpUnmatchedEvidenceTest: PASS (synthetic, read-only)\n";
