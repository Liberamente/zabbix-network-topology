<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);
/**
 * Standalone adversarial model for module.config read/merge/write.
 * It documents a real lost-update schedule, not a Zabbix integration test.
 */
function replaceKey(array $snapshot, string $key, array $data): array {
    $snapshot[$key] = $data;
    return $snapshot;
}
function revision(array $data): string {
    return hash('sha256', json_encode($data));
}
function check(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL: ".$name."\n"); exit(1); }
    echo "PASS: ".$name."\n";
}
$initial = ['manual_dependencies'=>[['upstream'=>'1','downstream'=>'2']],
            'manual_links'=>[['s'=>'1','t'=>'2']]];
// Both workers receive the same fresh snapshot before either updates DB.
$aRead = $initial;
$bRead = $initial;
$expected = revision($initial['manual_dependencies']);
check($expected === revision($aRead['manual_dependencies'])
    && $expected === revision($bRead['manual_dependencies']),
    'two workers accept same dependency revision');
$aWrite = replaceKey($aRead, 'manual_dependencies',
    [['upstream'=>'1','downstream'=>'3']]);
$bWrite = replaceKey($bRead, 'manual_dependencies',
    [['upstream'=>'1','downstream'=>'4']]);
$database = $aWrite;
$database = $bWrite; // last writer wins
check($database['manual_dependencies'] === $bWrite['manual_dependencies']
    && $database['manual_dependencies'] !== $aWrite['manual_dependencies'],
    'last writer silently overwrites first without atomic CAS');
// Even distinct config keys are vulnerable to read-modify-write races.
$aWrite = replaceKey($aRead, 'manual_dependencies',
    [['upstream'=>'1','downstream'=>'3']]);
$bWrite = replaceKey($bRead, 'manual_links',
    [['s'=>'1','t'=>'3']]);
$database = $aWrite;
$database = $bWrite;
check($database['manual_dependencies'] === $initial['manual_dependencies'],
    'uncoordinated other-key writer can undo dependency update');
echo "Contention model: PASS (documented failure schedules; NOT a fix)\n";
