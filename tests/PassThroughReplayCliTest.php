<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

function checkReplay(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . "\n";
}
function walkLine(string $oid, string $value): string {
    return '.' . $oid . ' = ' . $value . "\n";
}
function macSuffix(string $address): string {
    return implode('.', array_map('hexdec', explode(':', $address)));
}
function replayItem(string $id, string $key, string $value, int $now): array {
    return [
        'hostid' => $id, 'key_' => $key,
        'lastclock' => $now - 25, 'lastvalue' => $value,
        'status' => '0', 'state' => '0'
    ];
}
function runReplayCli(string $path): array {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../tools/pass-through-replay.php')
        . ' --snapshot ' . escapeshellarg($path);
    $pipes = [];
    $proc = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('cannot run replay CLI');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
$now = time();
$phoneMac = '02:11:22:33:44:55';
$deviceMac = '02:11:22:33:44:66';
$fdb = walkLine('1.3.6.1.2.1.17.7.1.2.2.1.2.1.'
        . macSuffix($phoneMac), 'INTEGER: 7')
    . walkLine('1.3.6.1.2.1.17.7.1.2.2.1.2.1.'
        . macSuffix($deviceMac), 'INTEGER: 7');
$hosts = [
    '100' => [
        'host' => 'synthetic-switch', 'name' => 'synthetic-switch',
        'proxyid' => '10', 'tags' => [], 'inventory' => [],
        'interfaces' => [['ip' => '192.0.2.1']]
    ],
    '101' => [
        'host' => 'synthetic-phone', 'name' => 'synthetic-phone',
        'proxyid' => '10', 'tags' => [], 'inventory' => [],
        'interfaces' => [['ip' => '192.0.2.2']]
    ],
    '102' => [
        'host' => 'synthetic-industrial-device',
        'name' => 'synthetic-industrial-device', 'proxyid' => '10',
        'tags' => [], 'inventory' => [],
        'interfaces' => [['ip' => '192.0.2.3']]
    ],
];
$walk = [
    replayItem('100', 'nt.fdb.dot1q', $fdb, $now),
    replayItem('100', 'nt.fdb.baseport',
        walkLine('1.3.6.1.2.1.17.1.4.1.2.7', 'INTEGER: 7'), $now),
    replayItem('100', 'nt.fdb.ifname',
        walkLine('1.3.6.1.2.1.31.1.1.1.1.7', 'STRING: "Gi7"'), $now),
    replayItem('100', 'nt.arp',
        walkLine('1.3.6.1.2.1.4.22.1.2.7.192.0.2.2',
            'Hex-STRING: ' . str_replace(':', ' ', $phoneMac))
      . walkLine('1.3.6.1.2.1.4.22.1.2.7.192.0.2.3',
            'Hex-STRING: ' . str_replace(':', ' ', $deviceMac)), $now),
];
$cap = [replayItem('100', 'lldpRemSysCapEnabled[0.7.1]',
    chr(0x24) . chr(0x00), $now)];
$cdp = [replayItem('100', 'cdpCacheDeviceId[7.1]',
    '01 C0 00 02 02', $now)];
$edges = [[
    'from' => '100', 'to' => '101', 'id' => 'switch-to-phone',
    'src' => ['lldp'], 'match' => 'lldp_portid', 'confirmed' => false,
    'ports' => ['100' => 'Gi7'], 'port_idx' => []
]];
$snapshot = [
    'observed_at' => $now, 'hosts' => $hosts,
    'walk_items' => $walk, 'cap_items' => $cap, 'cdp_items' => $cdp,
    'edges' => $edges, 'manual_uplinks' => []
];
$path = tempnam(sys_get_temp_dir(), 'nt-pass-replay-');
if ($path === false) throw new RuntimeException('cannot make temporary fixture');
chmod($path, 0600);
try {
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
    [$status, $stdout, $stderr] = runReplayCli($path);
    checkReplay($status === 0 && $stderr === '', 'private fixture replay succeeds');
    $pos = strpos($stdout, '{');
    $stats = $pos === false ? null : json_decode(substr($stdout, $pos), true);
    checkReplay(is_array($stats)
        && ($stats['new_inferred_candidates'] ?? -1) === 1,
        'one inferred phone-to-industrial-device association');
    checkReplay(($stats['diagnostics']['identified_phone_ports'] ?? -1) === 1
        && ($stats['diagnostics']['accepted'] ?? -1) === 1
        && ($stats['diagnostics']['excluded'] ?? null) === [],
        'private replay returns anonymous accepted/rejected counters');
    checkReplay(($stats['hosts'] ?? -1) === 3
        && ($stats['original_edges'] ?? -1) === 1
        && ($stats['isolated_before'] ?? -1) === 1
        && ($stats['isolated_theoretical_after'] ?? -1) === 0,
        'graph isolation forecast from original edge snapshot');
    checkReplay(($stats['zabbix_mutations'] ?? -1) === 0
        && ($stats['module_deployed'] ?? true) === false,
        'read-only, no deployment and no changes');
    checkReplay(!str_contains($stdout, '192.0.2.')
        && !str_contains($stdout, $phoneMac)
        && !str_contains($stdout, 'synthetic-industrial-device'),
        'stdout exposes no host, address or MAC');
    chmod($path, 0644);
    [$status, $stdout, $stderr] = runReplayCli($path);
    checkReplay($status !== 0 && str_contains($stderr, '0600'),
        'reject private snapshots with permissive permissions');
    chmod($path, 0600);
    $snapshot['observed_at'] = $now - 650;
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
    [$status, $stdout, $stderr] = runReplayCli($path);
    checkReplay($status !== 0 && str_contains($stderr, 'expired'),
        'reject stale graph snapshots');
} finally {
    @chmod($path, 0600);
    @unlink($path);
}
echo "PassThroughReplayCliTest: PASS (synthetic/private fixture)\n";
