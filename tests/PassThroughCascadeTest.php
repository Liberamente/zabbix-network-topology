<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require_once __DIR__ . '/../topology/FdbResolver.php';
require_once __DIR__ . '/../topology/FdbParser.php';
require_once __DIR__ . '/../topology/HostMetadata.php';
require_once __DIR__ . '/../topology/LldpEdgeBuilder.php';
require_once __DIR__ . '/../topology/AutoFdbEdges.php';
require_once __DIR__ . '/../topology/PassThroughEvidence.php';

use Modules\NetworkTopology\Topology\PassThroughEvidence as Cascade;

const CASCADE_NOW = 2000000000;
const PHONE_MAC = '02:11:22:33:44:55';
const DOWNSTREAM_MAC = '02:11:22:33:44:66';
const THIRD_MAC = '02:11:22:33:44:77';

function cascadeCheck(bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
function cascadeHost(string $name, string $ip, string $proxy='500'): array {
    return ['host'=>$name,'name'=>$name,'proxyid'=>$proxy,
        'interfaces'=>[['ip'=>$ip]],'inventory'=>[],'tags'=>[]];
}
function cascadeItem(string $hid, string $key, string $val): array {
    return ['hostid'=>$hid,'key_'=>$key,'lastvalue'=>$val,
        'lastclock'=>CASCADE_NOW-20,'status'=>'0','state'=>'0'];
}
function cascadeWalk(string $oid, string $value): string {
    return '.' . $oid . ' = ' . $value . "\n";
}
function cascadeMacIndex(string $mac): string {
    return implode('.', array_map('hexdec', explode(':', $mac)));
}
function cascadeFdbRow(string $mac, int $port): string {
    return cascadeWalk('1.3.6.1.2.1.17.7.1.2.2.1.2.1.'.cascadeMacIndex($mac),
        'INTEGER: '.$port);
}
function cascadeArpRow(string $ip, string $mac): string {
    return cascadeWalk('1.3.6.1.2.1.4.22.1.2.1.'.$ip,
        'Hex-STRING: '.str_replace(':',' ',$mac));
}
function cascadeFixture(): array {
    $hosts = [
        '100'=>cascadeHost('switch-a', '192.0.2.1'),
        '200'=>cascadeHost('switch-b', '192.0.2.4'),
        '101'=>cascadeHost('phone-a', '192.0.2.2'),
        '102'=>cascadeHost('synthetic-moxa', '192.0.2.3'),
    ];
    $switchA = cascadeFdbRow(PHONE_MAC,7).cascadeFdbRow(DOWNSTREAM_MAC,7);
    $switchB = cascadeFdbRow(PHONE_MAC,48).cascadeFdbRow(DOWNSTREAM_MAC,48);
    $walk = [
        cascadeItem('100','nt.fdb.dot1q',$switchA),
        cascadeItem('100','nt.fdb.baseport',
            cascadeWalk('1.3.6.1.2.1.17.1.4.1.2.7','INTEGER: 7')
           .cascadeWalk('1.3.6.1.2.1.17.1.4.1.2.48','INTEGER: 48')),
        cascadeItem('100','nt.fdb.ifname',
            cascadeWalk('1.3.6.1.2.1.31.1.1.1.1.7','STRING: "Gi7"')
           .cascadeWalk('1.3.6.1.2.1.31.1.1.1.1.48','STRING: "Gi48"')),
        cascadeItem('200','nt.fdb.dot1q',$switchB),
        cascadeItem('200','nt.fdb.baseport',
            cascadeWalk('1.3.6.1.2.1.17.1.4.1.2.48','INTEGER: 48')),
        cascadeItem('200','nt.fdb.ifname',
            cascadeWalk('1.3.6.1.2.1.31.1.1.1.1.48','STRING: "Gi48"')),
        cascadeItem('100','nt.arp',
            cascadeArpRow('192.0.2.2',PHONE_MAC)
           .cascadeArpRow('192.0.2.3',DOWNSTREAM_MAC)),
    ];
    $caps = [cascadeItem('100','lldpRemSysCapEnabled[0.7.1]',
        chr(0x24).chr(0x00))];  // Bridge + Telephone, no vendor-specific label
    $cdp = [cascadeItem('100','cdpCacheDeviceId[7.1]','01 C0 00 02 02')];
    $edges = [
        ['id'=>'physical-phone','from'=>'100','to'=>'101',
         'src'=>['lldp'],'match'=>'alias','confirmed'=>false,
         'reporters'=>['100'],'ports'=>['100'=>'Gi7'],
         'port_idx'=>['100'=>'7']],
        ['id'=>'physical-switch','from'=>'100','to'=>'200',
         'src'=>['lldp'],'match'=>'exact','confirmed'=>true,
         'reporters'=>['100','200'],
         'ports'=>['100'=>'Gi48','200'=>'Gi48'],
         'port_idx'=>['100'=>'48','200'=>'48']],
    ];
    return [$hosts,$walk,$caps,$cdp,$edges];
}
function cascadePropose(array $input, ?array &$diagnostic = null): array {
    return Cascade::propose(
        $input[0], $input[1], $input[2], $input[3],
        $input[4], [], CASCADE_NOW, $diagnostic
    );
}

$fixture = cascadeFixture();
$before = serialize($fixture);
$diag = [];
$rows = cascadePropose($fixture, $diag);
cascadeCheck(count($rows) === 1, 'cascaded switch copies on confirmed uplink accepted');
cascadeCheck($rows[0]['from'] === '101' && $rows[0]['to'] === '102',
    'phone to downstream industrial device, not phone to second switch');
cascadeCheck(($diag['verified_uplink_copies_ignored'] ?? -1) === 2
    && ($diag['accepted'] ?? 0) === 1,
    'only two confirmed transit FDB copies are disregarded');
cascadeCheck($rows[0]['confirmed'] === false && $rows[0]['ports'] === []
    && $rows[0]['port_idx'] === [] && $rows[0]['port_metrics'] === [],
    'still an inferred association without physical ports or per-edge traffic');
cascadeCheck(serialize($fixture) === $before,
    'input arrays and physical LLDP edges unchanged');

$pc = $fixture;
$pc[0]['102']['name'] = 'synthetic-pc';
cascadeCheck(count(cascadePropose($pc)) === 1,
    'same universal rule works for PC and MOXA, without manufacturer match');

$dhcp = $fixture;
$dhcp[0]['102']['interfaces'] = [['ip'=>'192.0.2.42']];
$dhcp[1][6]['lastvalue'] = cascadeArpRow('192.0.2.2',PHONE_MAC)
    .cascadeArpRow('192.0.2.42',DOWNSTREAM_MAC);
$renumbered = cascadePropose($dhcp);
cascadeCheck(count($renumbered) === 1 && $renumbered[0]['to'] === '102',
    'renumbered IP retains the SAME downstream hostid if Zabbix was reconciled');

$dup = $dhcp;
$dup[0]['103'] = cascadeHost('unexpected-duplicate','192.0.2.42');
cascadeCheck(cascadePropose($dup) === [],
    'duplicate monitored IP fails closed despite stable endpoint MAC');

$oneSided = $fixture;
$oneSided[4][1]['confirmed'] = false;
cascadeCheck(cascadePropose($oneSided) === [],
    'one-sided LLDP switch edge cannot authorize ignoring a second location');

$fakeConfirmed = $fixture;
$fakeConfirmed[4][1]['reporters'] = ['100']; // forged confirmed flag
cascadeCheck(cascadePropose($fakeConfirmed) === [],
    'confirmed flag is insufficient without both actual LLDP reporters');

$fdbUpper = $fixture;
$fdbUpper[4][1]['src'] = ['fdb']; // forged confirmed flag and reporters
cascadeCheck(cascadePropose($fdbUpper) === [],
    'FDB-only inter-switch candidate cannot be treated as a verified uplink');

$unmapped = $fixture;
$unmapped[4][1]['port_idx']['200'] = '999';  // never guess the port
cascadeCheck(cascadePropose($unmapped) === [],
    'unmapped remote ifIndex blocks discounting a transit location');

$otherProxy = $fixture;
$otherProxy[0]['200']['proxyid'] = '501';
cascadeCheck(cascadePropose($otherProxy) === [],
    'cross-proxy switch uplink cannot authorize FDB transit');

$unknownSwitch = $fixture;
$unknownSwitch[4] = [$unknownSwitch[4][0]]; // no infrastructure confirmation
cascadeCheck(cascadePropose($unknownSwitch) === [],
    'unknown additional switch FDB appearance remains ambiguous');

$otherAccess = $fixture;
$otherAccess[1][3]['lastvalue'] = cascadeFdbRow(PHONE_MAC,3)
    .cascadeFdbRow(DOWNSTREAM_MAC,3);
cascadeCheck(cascadePropose($otherAccess) === [],
    'MAC learned on unverified second access port remains ambiguous');

$localFlap = $fixture;
$localFlap[1][0]['lastvalue'] .= cascadeFdbRow(DOWNSTREAM_MAC,48);
cascadeCheck(cascadePropose($localFlap) === [],
    'same-switch MAC learned on access and uplink is NOT discounted');

$threeMacs = $fixture;
$threeMacs[1][0]['lastvalue'] .= cascadeFdbRow(THIRD_MAC,7);
cascadeCheck(cascadePropose($threeMacs) === [],
    'phone plus two downstream MACs: no invented PC-to-MOXA cable');

$noUpperLeg = $fixture;
$noUpperLeg[4] = [$noUpperLeg[4][1]];
cascadeCheck(cascadePropose($noUpperLeg) === [],
    'verified inter-switch uplink cannot invent switch-to-phone cable');

echo "PassThroughCascadeTest: PASS (synthetic, no runtime/Zabbix writes)\n";
