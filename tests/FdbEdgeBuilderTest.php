<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Self-contained module regression; no real IPs, hostnames, SNMP or API.
require_once dirname(__DIR__) . '/topology/FdbEdgeBuilder.php';
use Modules\NetworkTopology\Topology\FdbEdgeBuilder;

$failures = 0;
$checks = 0;
function checkFdb(string $name, bool $result): void {
    global $failures, $checks;
    $checks++;
    if (!$result) {
        $failures++;
        fwrite(STDERR, "FAIL: $name\n");
    }
}
function host(string $id, string $ip, string $group = '900'): array {
    return [
        'hostid' => $id,
        'host' => 'SW-OR-DEVICE-' . $id,
        'name' => 'Synthetic ' . $id,
        'interfaces' => [['ip' => $ip, 'type' => '1']],
        'hostgroups' => [['groupid' => $group, 'name' => 'Synthetic Group']],
    ];
}
function item(string $hid, string $key, string $raw, int $clk): array {
    return ['hostid' => $hid, 'key_' => $key,
            'lastvalue' => $raw, 'lastclock' => $clk];
}
function arp(string $ip, string $mac): string {
    return '.1.3.6.1.2.1.4.22.1.2.2.' . $ip
        . ' = Hex-STRING: ' . $mac;
}
function fdb(string $mac, string $port, string $vid = '2'): string {
    $bytes = explode(' ', $mac);
    return '.1.3.6.1.2.1.17.7.1.2.2.1.2.' . $vid . '.'
        . implode('.', array_map('hexdec', $bytes))
        . ' = INTEGER: ' . $port;
}
function fixture(): array {
    $now = 1800000000;
    $hosts = [
        '1001' => host('1001', '192.0.2.1'),
        '1002' => host('1002', '192.0.2.10'),
        '1003' => host('1003', '192.0.2.11'),
        '1004' => host('1004', '192.0.2.12'),
        '1005' => host('1005', '192.0.2.13'), // LLDP is already a better edge
        '1006' => host('1006', '198.51.100.14', '901'), // another tenant
        '1007' => host('1007', '192.0.2.254'), // ARP router
    ];
    $m1 = '02 11 22 33 44 51';
    $m2 = '02 11 22 33 44 52';
    $m3 = '02 11 22 33 44 53';
    $m4 = '02 11 22 33 44 54';
    $m5 = '02 11 22 33 44 55';
    $arps = implode("\n", [
        arp('192.0.2.10', $m1),
        arp('192.0.2.11', $m2),
        arp('192.0.2.12', $m3),
        arp('192.0.2.13', $m4),
        arp('198.51.100.14', $m5),
    ]);
    $bridge = implode("\n", [
        '.1.3.6.1.2.1.17.1.4.1.2.1 = INTEGER: 1',
        '.1.3.6.1.2.1.17.1.4.1.2.2 = INTEGER: 2',
        '.1.3.6.1.2.1.17.1.4.1.2.532 = INTEGER: 532',
    ]);
    $ifname = implode("\n", [
        '.1.3.6.1.2.1.31.1.1.1.1.1 = STRING: "1"',
        '.1.3.6.1.2.1.31.1.1.1.1.2 = STRING: "2"',
        '.1.3.6.1.2.1.31.1.1.1.1.532 = STRING: "Trk1"',
    ]);
    $fdb = implode("\n", [
        fdb($m1, '1'), fdb($m2, '1'), fdb($m3, '2'),
        fdb($m4, '2'), fdb($m5, '532'),
    ]);
    $items = [
        item('1007', 'nt.arp', $arps, $now-160),
        item('1001', 'nt.fdb.baseport', $bridge, $now-170),
        item('1001', 'nt.fdb.ifname', $ifname, $now-172),
        item('1001', 'nt.fdb.dot1q', $fdb, $now-180),
    ];
    $lldp = [[
        'id' => 'e0', 'from' => '1001', 'to' => '1005',
        'src' => ['lldp'], 'ports' => ['1001' => '3', '1005' => '1'],
        'confirmed' => true,
    ]];
    return [$hosts, $lldp, $items, $now];
}

[$hosts, $original, $items, $now] = fixture();
$out = FdbEdgeBuilder::augment($hosts, $original, $items, ['900'], $now);
$extra = array_values(array_filter($out, fn($e) => $e['fdb_learned'] ?? false));
checkFdb('three inferred from clean ARP+FDB access-port data', count($extra) === 3);
checkFdb('original LLDP link untouched', $out[0] === $original[0]);
checkFdb('all inferred edges unconfirmed', count(array_filter($extra,
    static fn($e) => $e['confirmed'] === false)) === 3);
checkFdb('no host imported from tenant 901', !in_array('1006',
    array_column($extra, 'to'), true));
checkFdb('no duplicate of existing LLDP endpoint', !in_array('1005',
    array_column($extra, 'to'), true));
checkFdb('two hosts share one learned access port', count(array_filter($extra,
    static fn($e) => $e['fdb_shared_port'] === true)) === 2);
checkFdb('single learned access port marked not shared', count(array_filter($extra,
    static fn($e) => $e['fdb_shared_port'] === false)) === 1);
checkFdb('physical bridge port preserved, never Trk1',
    count(array_filter($extra, static fn($e) =>
        $e['ports']['1001'] === '1' || $e['ports']['1001'] === '2')) === 3);
checkFdb('distinct learned endpoints', count(array_unique(
    array_column($extra, 'to'))) === 3);
checkFdb('no inferred link without explicit requested group',
    FdbEdgeBuilder::augment($hosts, $original, $items, [], $now) === $original);

$old = $items;
$old[0]['lastclock'] -= 1000;
checkFdb('stale ARP cannot create any inferred connection',
    FdbEdgeBuilder::augment($hosts, $original, $old, ['900'], $now) === $original);
$future = $items;
$future[3]['lastclock'] += 1000;
checkFdb('future FDB cannot create any inferred connection',
    FdbEdgeBuilder::augment($hosts, $original, $future, ['900'], $now) === $original);
$skew = $items;
$skew[0]['lastclock'] = $now - 800;
$skew[3]['lastclock'] = $now - 10;
checkFdb('inconsistent ARP/FDB sample clocks excluded',
    FdbEdgeBuilder::augment($hosts, $original, $skew, ['900'], $now) === $original);
$without = array_values(array_filter($items, fn($v) => $v['key_'] !== 'nt.arp'));
checkFdb('no ARP, no inferred links',
    FdbEdgeBuilder::augment($hosts, $original, $without, ['900'], $now) === $original);
$without = array_values(array_filter($items, fn($v) => $v['key_'] !== 'nt.fdb.baseport'));
checkFdb('no bridge-port mapping, no guesses',
    FdbEdgeBuilder::augment($hosts, $original, $without, ['900'], $now) === $original);
$without = array_values(array_filter($items, fn($v) => $v['key_'] !== 'nt.fdb.ifname'));
checkFdb('no ifName, no blind bridge-port assumption',
    FdbEdgeBuilder::augment($hosts, $original, $without, ['900'], $now) === $original);
$unknown = $items;
$unknown[2]['lastvalue'] = str_replace('STRING: "1"', 'STRING: "Trk9"',
    $unknown[2]['lastvalue']);
checkFdb('static trunk ports excluded',
    count(FdbEdgeBuilder::augment($hosts, $original, $unknown, ['900'], $now)) === 2);
$dup = $items;
$dup[3]['lastvalue'] .= "\n" . fdb('02 11 22 33 44 51', '2');
checkFdb('same MAC on two access ports is ambiguous, no edge for it',
    count(FdbEdgeBuilder::augment($hosts, $original, $dup, ['900'], $now)) === 3);
$h = $hosts; $h['1003']['interfaces'][0]['ip'] = '192.0.2.10';
checkFdb('duplicate IP in visible group cannot be mapped',
    count(FdbEdgeBuilder::augment($h, $original, $items, ['900'], $now)) === 2);
$other = $hosts;
$other['1007']['hostgroups'] = [['groupid' => '901']];
checkFdb('ARP router outside selected group excluded',
    FdbEdgeBuilder::augment($other, $original, $items, ['900'], $now) === $original);
$other = $hosts;
$other['1001']['hostgroups'] = [['groupid' => '901']];
checkFdb('FDB switch outside selected group excluded',
    FdbEdgeBuilder::augment($other, $original, $items, ['900'], $now) === $original);
$conflict = $items;
$conflict[0]['lastvalue'] .= "\n" . arp('192.0.2.10', '02 11 22 33 44 66');
checkFdb('two ARP MACs for same IP are ambiguous',
    count(FdbEdgeBuilder::augment($hosts, $original, $conflict, ['900'], $now)) === 3);

printf("FdbEdgeBuilder: %d checks, %d failures\n", $checks, $failures);
exit($failures > 0 ? 1 : 0);
