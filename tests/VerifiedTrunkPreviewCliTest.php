<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * End-to-end, private-file smoke tests of the offline adapter command.
 * Fixtures contain ONLY synthetic switch names, port IDs and RFC 5737 IPs.
 */
$errors = 0;
$checks = 0;
function verifyCli(string $label, bool $ok): void {
    global $errors, $checks;
    $checks++;
    if (!$ok) {
        $errors++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

function runCli(array $args): array {
    $cmd = array_merge([PHP_BINARY,
        dirname(__DIR__) . '/tools/nt-verified-trunk-preview.php'], $args);
    $process = proc_open($cmd, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('PHP CLI process unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$dir = sys_get_temp_dir() . '/nt-verified-trunks-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('cannot create isolated CLI test directory');
}
$fixture = __DIR__ . '/fixtures/verified-trunks-synthetic.json';
$jsonFile = $dir . '/preview.json';
$dotFile = $dir . '/preview.dot';
$args = [
    '--input', $fixture,
    '--output', $jsonFile,
    '--dot-output', $dotFile,
    '--hostids', '1001,1002,1003,1004,1005',
    '--group-id', '900',
    '--expect-links', '4',
];
try {
    [$status, $out, $err] = runCli($args);
    verifyCli('successful offline render', $status === 0);
    verifyCli('private JSON and DOT exist', is_file($jsonFile) && is_file($dotFile));
    $payload = is_file($jsonFile)
        ? json_decode((string) file_get_contents($jsonFile), true) : [];
    $dot = is_file($dotFile) ? (string) file_get_contents($dotFile) : '';
    verifyCli('five synthetic node IDs', count($payload['nodes'] ?? []) === 5);
    verifyCli('four logical trunk edges', count($payload['edges'] ?? []) === 4);
    verifyCli('eight nested member pairs', array_sum(array_column(
        $payload['edges'] ?? [], 'nt_member_count')) === 8);
    verifyCli('NOT publishable', ($payload['publication_allowed'] ?? null) === false
        && ($payload['preview_only'] ?? null) === true);
    verifyCli('DOT includes exactly four logical arcs', substr_count($dot, ' -- ') === 4);
    verifyCli('DOT contains complete member details',
        substr_count($dot, '2 physical ports') === 4);
    verifyCli('unverified endpoint IP never included',
        strpos((string) json_encode($payload), '198.51.100.99') === false
        && strpos($dot, '198.51.100.99') === false);
    verifyCli('both outputs owner-only',
        (fileperms($jsonFile) & 0777) === 0600
        && (fileperms($dotFile) & 0777) === 0600);
    $before = hash_file('sha256', $jsonFile);
    [$repeatStatus] = runCli($args);
    verifyCli('exclusive create refuses overwrite', $repeatStatus !== 0
        && hash_file('sha256', $jsonFile) === $before);

    $wrongGroup = $args;
    $wrongGroup[array_search('900', $wrongGroup, true)] = '901';
    $wrongGroup[array_search($jsonFile, $wrongGroup, true)] = $dir . '/wrong-group.json';
    $wrongGroup[array_search($dotFile, $wrongGroup, true)] = $dir . '/wrong-group.dot';
    [$wrongStatus] = runCli($wrongGroup);
    verifyCli('wrong group fails with no outputs', $wrongStatus !== 0
        && !file_exists($dir . '/wrong-group.json')
        && !file_exists($dir . '/wrong-group.dot'));

    $wrongCount = $args;
    $wrongCount[array_search('4', $wrongCount, true)] = '5';
    $wrongCount[array_search($jsonFile, $wrongCount, true)] = $dir . '/wrong-count.json';
    $wrongCount[array_search($dotFile, $wrongCount, true)] = $dir . '/wrong-count.dot';
    [$countStatus] = runCli($wrongCount);
    verifyCli('unexpected graph size fails with no outputs', $countStatus !== 0
        && !file_exists($dir . '/wrong-count.json'));
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}
printf("VerifiedTrunkPreviewCli: %d checks, %d failures\n", $checks, $errors);
exit($errors > 0 ? 1 : 0);
