<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require_once __DIR__ . '/../topology/FdbResolver.php';
require_once __DIR__ . '/../topology/FdbParser.php';
require_once __DIR__ . '/../topology/HostMetadata.php';
require_once __DIR__ . '/../topology/LldpEdgeBuilder.php';
require_once __DIR__ . '/../topology/AutoFdbEdges.php';
require_once __DIR__ . '/../topology/HpeStaticTrunkEvidence.php';
require_once __DIR__ . '/../topology/PassThroughEvidence.php';

use Modules\NetworkTopology\Topology\PassThroughEvidence;

const PMT_NOW = 2000000000;
const PMT_PHONE_MAC = '02:11:22:33:44:55';
const PMT_ENDPOINT_MAC = '02:11:22:33:44:66';

function pmtCheck(bool $pass, string $label): void {
    if (!$pass) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
function pmtHost(string $name, string $ip, string $proxy = '500'): array {
    return ['host' => $name, 'name' => $name, 'proxyid' => $proxy,
        'interfaces' => [['ip' => $ip]], 'tags' => [], 'inventory' => []];
}
function pmtItem(string $hostid, string $key, string $value): array {
    return ['hostid' => $hostid, 'key_' => $key, 'lastvalue' => $value,
        'lastclock' => PMT_NOW - 30, 'status' => '0', 'state' => '0'];
}
function pmtLine(string $oid, string $value): string {
    return '.' . $oid . ' = ' . $value . "\n";
}
function pmtMacOid(string $mac): string {
    return implode('.', array_map('hexdec', explode(':', $mac)));
}
function pmtFdb(string $mac, int $bridgePort): string {
    return pmtLine('1.3.6.1.2.1.17.7.1.2.2.1.2.1.' . pmtMacOid($mac),
        'INTEGER: ' . $bridgePort);
}
function pmtArp(string $ip, string $mac): string {
    return pmtLine('1.3.6.1.2.1.4.22.1.2.1.' . $ip,
        'Hex-STRING: ' . str_replace(':', ' ', $mac));
}
function pmtName(int $index, string $label): string {
    return pmtLine('1.3.6.1.2.1.31.1.1.1.1.' . $index, 'STRING: "' . $label . '"');
}
function pmtBase(int $bridgePort, int $ifIndex): string {
    return pmtLine('1.3.6.1.2.1.17.1.4.1.2.' . $bridgePort, 'INTEGER: ' . $ifIndex);
}
function pmtGroup(int $port, int $number): string {
    return pmtLine('1.3.6.1.4.1.11.2.14.11.5.1.7.1.3.1.1.8.' . $port,
        'INTEGER: ' . $number);
}
function pmtType(int $port, int $number): string {
    return pmtLine('1.3.6.1.4.1.11.2.14.11.5.1.7.1.3.1.1.13.' . $port,
        'INTEGER: ' . $number);
}
function pmtFixture(): array {
    // Synthetic network: two static-trunk members; one VoIP/endpoint
    // pair learned at the access port and at the remote logical Trk1.
    $hosts = [
        '100' => pmtHost('switch-a', '192.0.2.1'),
        '200' => pmtHost('switch-b', '192.0.2.4'),
        '101' => pmtHost('phone-a', '192.0.2.2'),
        '102' => pmtHost('endpoint-a', '192.0.2.3'),
    ];
    $walk = [
        pmtItem('100', 'nt.fdb.dot1q',
            pmtFdb(PMT_PHONE_MAC, 7) . pmtFdb(PMT_ENDPOINT_MAC, 7)),
        pmtItem('100', 'nt.fdb.baseport',
            pmtBase(7, 7) . pmtBase(23, 23) . pmtBase(24, 24)),
        pmtItem('100', 'nt.fdb.ifname',
            pmtName(7, 'Gi7') . pmtName(23, 'Gi23') .
            pmtName(24, 'Gi24') . pmtName(60, 'Trk1')),
        pmtItem('200', 'nt.fdb.dot1q',
            pmtFdb(PMT_PHONE_MAC, 60) . pmtFdb(PMT_ENDPOINT_MAC, 60)),
        pmtItem('200', 'nt.fdb.baseport',
            pmtBase(60, 60) . pmtBase(47, 47) . pmtBase(48, 48)),
        pmtItem('200', 'nt.fdb.ifname',
            pmtName(47, 'Gi47') . pmtName(48, 'Gi48') . pmtName(60, 'Trk1')),
        pmtItem('100', 'nt.arp',
            pmtArp('192.0.2.2', PMT_PHONE_MAC) .
            pmtArp('192.0.2.3', PMT_ENDPOINT_MAC)),
    ];
    $caps = [pmtItem('100', 'lldpRemSysCapEnabled[0.7.1]', chr(0x24) . chr(0))];
    $cdp = [pmtItem('100', 'cdpCacheDeviceId[7.1]', '02 11 22 33 44 55')];
    $chassis = [pmtItem('100', 'lldpRemChassisId[0.7.1]', '02 11 22 33 44 55')];
    $trunks = [
        pmtItem('100', 'nt.hpe.trunk.group', pmtGroup(23, 2) . pmtGroup(24, 2)),
        pmtItem('100', 'nt.hpe.trunk.type', pmtType(23, 1) . pmtType(24, 1)),
        pmtItem('200', 'nt.hpe.trunk.group', pmtGroup(47, 2) . pmtGroup(48, 2)),
        pmtItem('200', 'nt.hpe.trunk.type', pmtType(47, 1) . pmtType(48, 1)),
    ];
    $edges = [
        ['from' => '100', 'to' => '101', 'src' => ['lldp'],
            'reporters' => ['100'], 'confirmed' => false,
            'ports' => ['100' => 'Gi7'], 'port_idx' => ['100' => '7']],
        ['from' => '100', 'to' => '200', 'src' => ['lldp', 'cdp'],
            'reporters' => ['100', '200'], 'confirmed' => true,
            'ports' => ['100' => 'Gi23', '200' => 'Gi47'],
            'port_idx' => ['100' => '23', '200' => '47']],
        ['from' => '100', 'to' => '200', 'src' => ['lldp', 'cdp'],
            'reporters' => ['100', '200'], 'confirmed' => true,
            'ports' => ['100' => 'Gi24', '200' => 'Gi48'],
            'port_idx' => ['100' => '24', '200' => '48']],
    ];
    return [$hosts, $walk, $caps, $cdp, $edges, $chassis, $trunks];
}
function pmtRun(array $f, ?array &$diags = null): array {
    return PassThroughEvidence::propose(
        $f[0], $f[1], $f[2], $f[3], $f[4], [],
        PMT_NOW, $diags, $f[5], $f[6]
    );
}
function pmtWithoutTrunkCopies(array $f): array {
    // Remove the remote FDB evidence, while retaining the switch topology.
    $f[1][3]['lastvalue'] = '';
    return $f;
}
$f = pmtFixture();
$before = serialize($f);
$diags = [];
$rows = pmtRun($f, $diags);
if (count($rows) !== 1) {
    $trunksDebug = \Modules\NetworkTopology\Topology\HpeStaticTrunkEvidence::verifiedPlaces(
        $f[6],
        ['100' => ['7' => 'Gi7','23' => 'Gi23','24' => 'Gi24','60' => 'Trk1'],
         '200' => ['47' => 'Gi47','48' => 'Gi48','60' => 'Trk1']],
        ['100|gi23'=>'200|gi47','200|gi47'=>'100|gi23',
         '100|gi24'=>'200|gi48','200|gi48'=>'100|gi24'],
        PMT_NOW
    );
    echo 'DIAGNOSTIC_MAC_TRUNK=' . json_encode([
        'proposal_count' => count($rows),
        'diagnostics' => $diags,
        'verified_aggregates' => array_keys($trunksDebug),
    ]) . "\n";
}
pmtCheck(count($rows) === 1, 'unique MAC6 + LLDP chassis + full static trunk accepted');
pmtCheck($rows[0]['from'] === '101' && $rows[0]['to'] === '102',
    'real phone and endpoint host IDs on the SAME scoped proxy');
pmtCheck($rows[0]['match'] === 'pass_through_inferred'
    && $rows[0]['confirmed'] === false
    && $rows[0]['ports'] === [] && $rows[0]['port_idx'] === []
    && $rows[0]['port_metrics'] === [],
    'inferred, never physical cable or invented port traffic');
pmtCheck(in_array('cdp_mac6_lldp_chassis_arp', $rows[0]['evidence'], true)
    && $diags['verified_uplink_copies_ignored'] === 2,
    'CDP/LLDP/ARP provenance and two proven logical transit FDB copies');
pmtCheck(serialize($f) === $before, 'no input arrays mutated');

$bad = $f;
$bad[5][0]['lastvalue'] = '02 11 22 33 44 99';
pmtCheck(pmtRun($bad) === [], 'wrong LLDP chassis MAC fails closed');
$bad = $f;
$bad[5][0]['key_'] = 'lldpRemChassisId[0.8.1]';
pmtCheck(pmtRun($bad) === [], 'different LLDP SNMPINDEX fails closed');
$bad = $f;
$bad[5][0]['lastclock'] -= 15000;
pmtCheck(pmtRun($bad) === [], 'stale chassis evidence fails closed');
$bad = $f;
$bad[3][0]['lastvalue'] = '01 02 03 04 05';
pmtCheck(pmtRun($bad) === [], 'opaque five-byte CDP identifier is not a MAC');
$bad = $f;
$bad[3][0]['lastvalue'] = 'MysteriousID';
pmtCheck(pmtRun($bad) === [], 'arbitrary CDP text is not guessed as a MAC');
$bad = $f;
$bad[6] = [];
pmtCheck(pmtRun($bad) === [], 'unverified logical Trk is not ignored');
$bad = $f;
$bad[6][3]['lastvalue'] = pmtType(47, 5) . pmtType(48, 1);
pmtCheck(pmtRun($bad) === [], 'nonstatic member rejects the logical trunk');
$bad = $f;
$bad[6][2]['lastvalue'] = pmtGroup(47, 2);
pmtCheck(pmtRun($bad) === [], 'missing second member rejects logical trunk');
$bad = $f;
$bad[6][0]['lastclock'] -= 1000;
pmtCheck(pmtRun($bad) === [], 'stale HPE membership rejects logical trunk');
$bad = $f;
$bad[4][1]['confirmed'] = false;
pmtCheck(pmtRun($bad) === [], 'one-sided physical trunk member cannot discount FDB');
$bad = $f;
$bad[4][2]['reporters'] = ['100'];
pmtCheck(pmtRun($bad) === [], 'forged confirmed member without two reporters rejected');
$bad = $f;
$bad[4][1]['port_idx']['200'] = '999';
pmtCheck(pmtRun($bad) === [], 'unmapped remote ifIndex rejects trunk evidence');
$bad = $f;
$bad[0]['200']['proxyid'] = '501';
pmtCheck(pmtRun($bad) === [], 'cross-proxy LLDP trunk is not valid transit proof');
$bad = $f;
$bad[1][3]['lastvalue'] .= pmtFdb(PMT_ENDPOINT_MAC, 31);
pmtCheck(pmtRun($bad) === [], 'second unverified remote access port fails closed');
$bad = $f;
$bad[1][0]['lastvalue'] .= pmtFdb('02:11:22:33:44:77', 7);
pmtCheck(pmtRun($bad) === [], 'three MACs on phone port cannot define a pair');
$bad = $f;
$bad[0]['103'] = pmtHost('duplicate-phone-ip', '192.0.2.2');
pmtCheck(pmtRun($bad) === [], 'duplicate ARP phone identity fails closed');
$bad = $f;
$bad[0]['102']['proxyid'] = '501';
pmtCheck(pmtRun($bad) === [], 'endpoint on another proxy fails closed');
$bad = $f;
$bad[4][0]['ports']['100'] = 'Gi8';
$bad[4][0]['port_idx']['100'] = '8';
pmtCheck(pmtRun($bad) === [], 'missing exact upper LLDP/CDP port blocks candidate');
$bad = $f;
$bad[4][0]['src'] = ['fdb'];
pmtCheck(pmtRun($bad) === [], 'FDB-only switch-phone edge cannot corroborate phone');
$bad = $f;
$bad[6][2]['lastvalue'] .= pmtGroup(33, 3) . pmtGroup(34, 3);
$bad[6][3]['lastvalue'] .= pmtType(33, 1) . pmtType(34, 1);
$bad[1][5]['lastvalue'] .= pmtName(33, 'Gi33') . pmtName(34, 'Gi34') . pmtName(61, 'Trk2');
pmtCheck(pmtRun($bad) === [], 'extra unverified trunk forbids all aggregate discounts');

$without = pmtWithoutTrunkCopies($f);
$without[6] = [];
pmtCheck(count(pmtRun($without)) === 1,
    'standalone phone-port inference needs no extra HPE trunk evidence');

$legacy = $f;
$legacy[3][0]['lastvalue'] = '01 C0 00 02 02';
$legacy[5] = [];
$prior = pmtRun($legacy);
pmtCheck(count($prior) === 1
    && in_array('cdp_01_ipv4', $prior[0]['evidence'], true),
    'legacy IP Device ID remains supported without a chassis sample');

echo "PassThroughMacTrunkTest: PASS (synthetic; no real network data)\n";
