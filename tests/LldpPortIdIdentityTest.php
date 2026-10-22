<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// All identities, IPs and MACs are synthetic.
// IPv4 addresses belong to RFC 5737 TEST-NET-1.

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (!str_starts_with($class, $prefix)) return;

    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $dir = implode('/', array_map('strtolower', $parts));
    $path = dirname(__DIR__) . '/' . $dir . '/' . $file . '.php';

    if (is_file($path)) require $path;
});

use Modules\NetworkTopology\Topology\LldpPortIdIdentity;

$now = 1800000000;
$phoneMac = '02:00:00:00:01:02';
$pcMac = '02:00:00:00:01:03';

$hosts = [
    '101' => [
        'host' => 'lab-switch',
        'name' => 'Laboratory Switch',
        'proxyid' => '201',
        'interfaces' => [['ip' => '192.0.2.2']],
        'tags' => [],
    ],
    '102' => [
        'host' => 'lab-phone',
        'name' => 'Renamed Phone',
        'proxyid' => '201',
        'interfaces' => [['ip' => '192.0.2.12']],
        'tags' => [],
    ],
    '103' => [
        'host' => 'lab-pc',
        'name' => 'Downstream PC',
        'proxyid' => '201',
        'interfaces' => [['ip' => '192.0.2.13']],
        'tags' => [],
    ],
    '104' => [
        'host' => 'lab-router',
        'name' => 'Router',
        'proxyid' => '201',
        'interfaces' => [['ip' => '192.0.2.1']],
        'tags' => [],
    ],
];

function ntItem(string $hostid, string $key, string $value,
                int $clock): array {
    return [
        'hostid' => $hostid,
        'key_' => $key,
        'lastvalue' => $value,
        'lastclock' => $clock,
        'status' => '0',
        'state' => '0',
    ];
}

$lldp = [
    ntItem('101', 'lldpRemSysName[0.5.1]',
           'OLD-SNMP-PHONE', $now - 30),
    ntItem('101', 'lldpRemPortId[0.5.1]',
           '02 00 00 00 01 02', $now - 30),
];

$arp = [
    ntItem('104', 'nt.arp',
        ".1.3.6.1.2.1.4.22.1.2.2.192.0.2.12 = Hex-STRING: 02 00 00 00 01 02\n"
      . ".1.3.6.1.2.1.4.22.1.2.2.192.0.2.13 = Hex-STRING: 02 00 00 00 01 03\n",
        $now - 20),
];

// Already normalized FDB entries (FdbParser contract).
$fdb = [
    ['hostid' => '101', 'mac' => $phoneMac,
     'port' => 'Gi1/0/5', 'ts' => $now - 15, 'vlan' => 10],
    ['hostid' => '101', 'mac' => $pcMac,
     'port' => 'Gi1/0/5', 'ts' => $now - 15, 'vlan' => 10],
];

// Explicit mapping: LLDP local port number -> FDB/ifName.
// Never assume LLDP local port number always equals ifIndex.
$portMap = ['101' => ['5' => 'Gi1/0/5']];

if (!class_exists(LldpPortIdIdentity::class)) {
    fwrite(STDERR,
        "[EXPECTED RED] LldpPortIdIdentity not implemented yet\n");
    exit(1);
}

$failures = 0;

function ntCheck(string $label, $actual, $expected): void {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) $failures++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
}

function candidates(array $h, array $l, array $a, array $f,
                    array $ports): array {
    global $now;
    return LldpPortIdIdentity::candidates(
        $h, $l, $a, $f, $ports, $now
    );
}

// Positive: one-sided LLDP observation, independently identified
// by ARP and corroborated by FDB on the same mapped local port.
$found = candidates($hosts, $lldp, $arp, $fdb, $portMap);

ntCheck('One LLDP identity candidate', count($found), 1);
ntCheck('Reporter hostid',
        (string) ($found[0]['reporter_hostid'] ?? ''), '101');
ntCheck('Target hostid despite different SNMP name',
        (string) ($found[0]['target_hostid'] ?? ''), '102');
ntCheck('Explicit mapped switch port',
        $found[0]['switch_port'] ?? null, 'Gi1/0/5');
ntCheck('FDB shared with a second MAC',
        $found[0]['fdb_shared_port'] ?? null, true);
ntCheck('One-sided observation not physically confirmed',
        $found[0]['confirmed'] ?? null, false);
ntCheck('Evidence provenance',
        $found[0]['evidence'] ?? null,
        ['lldp_portid', 'arp', 'fdb']);

// The downstream PC must NOT become a second LLDP neighbor.
ntCheck('Downstream PC not promoted to LLDP',
        count($found), 1);

// Missing explicit local-port mapping.
ntCheck('No port mapping: reject',
        count(candidates($hosts, $lldp, $arp, $fdb, [])), 0);

// FDB reports the phone MAC on a different port.
$wrongPort = $fdb;
$wrongPort[0]['port'] = 'Gi1/0/6';
ntCheck('Wrong FDB port: reject',
        count(candidates($hosts, $lldp, $arp,
                         $wrongPort, $portMap)), 0);

// The same MAC on two different switch ports is ambiguous.
$duplicatePort = $fdb;
$duplicatePort[] = array_replace(
    $fdb[0], ['port' => 'Gi1/0/7']
);
ntCheck('Multiple FDB ports: reject',
        count(candidates($hosts, $lldp, $arp,
                         $duplicatePort, $portMap)), 0);

// Freshness checks.
$oldLldp = $lldp;
$oldLldp[1]['lastclock'] = $now - 14401;
ntCheck('Stale LLDP Port-ID: reject',
        count(candidates($hosts, $oldLldp, $arp,
                         $fdb, $portMap)), 0);

$oldArp = $arp;
$oldArp[0]['lastclock'] = $now - 901;
ntCheck('Stale ARP identity: reject',
        count(candidates($hosts, $lldp, $oldArp,
                         $fdb, $portMap)), 0);

$oldFdb = $fdb;
$oldFdb[0]['ts'] = $now - 901;
ntCheck('Stale FDB evidence: reject',
        count(candidates($hosts, $lldp, $arp,
                         $oldFdb, $portMap)), 0);

// Proxy isolation.
$crossProxy = $hosts;
$crossProxy['102']['proxyid'] = '202';
ntCheck('Different proxy: reject',
        count(candidates($crossProxy, $lldp, $arp,
                         $fdb, $portMap)), 0);

// Another host claims the same MAC.
$duplicateOwner = $hosts;
$duplicateOwner['105'] = [
    'host' => 'lab-duplicate',
    'name' => 'Duplicate identity',
    'proxyid' => '201',
    'interfaces' => [['ip' => '192.0.2.105']],
    'tags' => [['tag' => 'nt:lldp', 'value' => $phoneMac]],
];
ntCheck('Ambiguous MAC ownership: reject',
        count(candidates($duplicateOwner, $lldp, $arp,
                         $fdb, $portMap)), 0);

// A reported LLDP name belonging to another host conflicts
// with the MAC-based identity. Never overwrite a valid name match.
$conflictingName = $lldp;
$conflictingName[0]['lastvalue'] = 'lab-pc';
ntCheck('Conflicting LLDP name: reject',
        count(candidates($hosts, $conflictingName, $arp,
                         $fdb, $portMap)), 0);

// Additional safety and regression tests.

// Ambiguous LLDP local port mapping:
// two LLDP port numbers point at the same FDB interface.
$ambiguousMap = $portMap;
$ambiguousMap['101']['9'] = 'Gi1/0/5';
ntCheck('Ambiguous LLDP local port mapping: reject',
        count(candidates($hosts, $lldp, $arp,
                         $fdb, $ambiguousMap)), 0);

// A manually declared connection takes precedence.
$manualHost = $hosts;
$manualHost['102']['tags'] = [
    ['tag' => 'nt:uplink',
     'value' => 'lab-switch:Gi1/0/8'],
];
ntCheck('Manual nt:uplink takes precedence',
        count(candidates($manualHost, $lldp, $arp,
                         $fdb, $portMap)), 0);

// Duplicate IPv4 on the same proxy: no unique ARP identity.
$duplicateIP = $hosts;
$duplicateIP['105'] = $hosts['102'];
$duplicateIP['105']['host'] = 'lab-shadow';
$duplicateIP['105']['name'] = 'Shadow Device';
ntCheck('Duplicate IPv4 on same proxy: reject',
        count(candidates($duplicateIP, $lldp, $arp,
                         $fdb, $portMap)), 0);

// The same private IPv4 on a different proxy is legitimate.
$otherProxy = $duplicateIP;
$otherProxy['105']['proxyid'] = '202';
ntCheck('Same IPv4 on different proxy: allowed',
        count(candidates($otherProxy, $lldp, $arp,
                         $fdb, $portMap)), 1);

// Unsupported LLDP item must not generate evidence.
$unsupported = $lldp;
$unsupported[1]['state'] = '1';
ntCheck('Unsupported LLDP Port-ID: reject',
        count(candidates($hosts, $unsupported, $arp,
                         $fdb, $portMap)), 0);

// Reject timestamps beyond the permitted clock skew.
$future = $lldp;
$future[1]['lastclock'] = $now + 61;
ntCheck('Future LLDP observation: reject',
        count(candidates($hosts, $future, $arp,
                         $fdb, $portMap)), 0);

// Conflicting LLDP values for the same SNMP index.
$conflictingPortId = $lldp;
$conflictingPortId[] = ntItem(
    '101', 'lldpRemPortId[0.5.1]',
    '02 00 00 00 01 04', $now - 20
);
ntCheck('Conflicting LLDP Port-ID: reject',
        count(candidates($hosts, $conflictingPortId,
                         $arp, $fdb, $portMap)), 0);

// An identity already resolved by ordinary LLDP matching
// must not generate an additional fallback candidate.
$knownName = $lldp;
$knownName[0]['lastvalue'] = 'lab-phone';

ntCheck('Known LLDP name: no fallback candidate',
        count(candidates($hosts, $knownName, $arp,
                         $fdb, $portMap)), 0);

// An explicit nt:lldp alias is also already understood by
// LldpEdgeBuilder and must not create a second candidate.
$knownAlias = $hosts;
$knownAlias['102']['tags'] = [
    ['tag' => 'nt:lldp', 'value' => 'OLD-SNMP-PHONE'],
];

ntCheck('Known LLDP alias: no fallback candidate',
        count(candidates($knownAlias, $lldp, $arp,
                         $fdb, $portMap)), 0);

// Two switches claiming the same endpoint simultaneously:
// reject both candidates rather than selecting one.
$twoSwitches = $hosts;
$twoSwitches['105'] = [
    'host' => 'lab-switch-2',
    'name' => 'Second Switch',
    'proxyid' => '201',
    'interfaces' => [['ip' => '192.0.2.5']],
    'tags' => [],
];

$twoLldp = array_merge($lldp, [
    ntItem('105', 'lldpRemSysName[0.7.1]',
           'OLD-SNMP-PHONE', $now - 30),
    ntItem('105', 'lldpRemPortId[0.7.1]',
           '02 00 00 00 01 02', $now - 30),
]);

$twoFdb = array_merge($fdb, [[
    'hostid' => '105',
    'mac' => $phoneMac,
    'port' => 'Gi1/0/7',
    'ts' => $now - 15,
    'vlan' => 10,
]]);

$twoPortMaps = $portMap;
$twoPortMaps['105'] = ['7' => 'Gi1/0/7'];

ntCheck('Two switches claim one endpoint: reject',
        count(candidates($twoSwitches, $twoLldp,
                         $arp, $twoFdb, $twoPortMaps)), 0);

// If the second LLDP observation expires, the first
// valid observation remains usable.
$oneStale = $twoLldp;
$oneStale[3]['lastclock'] = $now - 14401;

ntCheck('Stale second reporter: first remains valid',
        count(candidates($twoSwitches, $oneStale,
                         $arp, $twoFdb, $twoPortMaps)), 1);


// LLDP local port numbers and IF-MIB ifIndex are independent.
// Resolve only through explicit, unique port names from fresh walks.

$localIdWalk =
    '.1.0.8802.1.1.2.1.3.7.1.3.501 = STRING: "Gi1/0/5"' . "\n"
  . '.1.0.8802.1.1.2.1.3.7.1.3.502 = STRING: "unknown-port"' . "\n"
  . '.1.0.8802.1.1.2.1.3.7.1.3.503 = STRING: "id-not-mapped"' . "\n";

$localDescWalk =
    '.1.0.8802.1.1.2.1.3.7.1.4.501 = STRING: "Gi1/0/5"' . "\n"
  . '.1.0.8802.1.1.2.1.3.7.1.4.503 = STRING: "Gi1/0/7"' . "\n";

$ifNameWalk =
    '.1.3.6.1.2.1.31.1.1.1.1.17 = STRING: "Gi1/0/5"' . "\n"
  . '.1.3.6.1.2.1.31.1.1.1.1.19 = STRING: "Gi1/0/7"' . "\n";

$localItems = [
    ntItem('101', 'nt.lldp.local.portid',
           $localIdWalk, $now - 120),
    ntItem('101', 'nt.lldp.local.portdesc',
           $localDescWalk, $now - 120),
    ntItem('101', 'nt.fdb.ifname',
           $ifNameWalk, $now - 30),
];

if (!method_exists(LldpPortIdIdentity::class, 'localPortMap')) {
    fwrite(STDERR,
        "[EXPECTED RED] localPortMap not implemented yet\n");
    exit(1);
}

$localMap = LldpPortIdIdentity::localPortMap(
    $hosts, $localItems, $now
);

ntCheck('LLDP port 501 -> ifName index 17',
        $localMap['101']['501'] ?? null, 'Gi1/0/5');

ntCheck('PortDesc fallback -> ifName index 19',
        $localMap['101']['503'] ?? null, 'Gi1/0/7');

ntCheck('Unmatched port: no numerical guess',
        isset($localMap['101']['502']), false);

// Two IF-MIB interfaces claiming the same label.
$duplicateIfName = $localItems;
$duplicateIfName[2]['lastvalue'] .=
    '.1.3.6.1.2.1.31.1.1.1.1.18 = STRING: "Gi1/0/5"' . "\n";

$map = LldpPortIdIdentity::localPortMap(
    $hosts, $duplicateIfName, $now
);
ntCheck('Duplicate ifName: reject port 501',
        isset($map['101']['501']), false);

// Two LLDP local ports claiming the same Port-ID.
$duplicateLldp = $localItems;
$duplicateLldp[0]['lastvalue'] .=
    '.1.0.8802.1.1.2.1.3.7.1.3.504 = STRING: "Gi1/0/5"' . "\n";

$map = LldpPortIdIdentity::localPortMap(
    $hosts, $duplicateLldp, $now
);
ntCheck('Duplicate LLDP Port-ID: reject both',
        isset($map['101']['501'])
        || isset($map['101']['504']), false);

// Conflicting Port-ID and PortDesc.
$conflictingDesc = $localItems;
$conflictingDesc[1]['lastvalue'] = str_replace(
    '1.4.501 = STRING: "Gi1/0/5"',
    '1.4.501 = STRING: "Gi1/0/7"',
    $conflictingDesc[1]['lastvalue']
);
$map = LldpPortIdIdentity::localPortMap(
    $hosts, $conflictingDesc, $now
);
ntCheck('Conflicting port descriptions: reject',
        isset($map['101']['501']), false);

// Stale interface names cannot validate current ports.
$staleIfName = $localItems;
$staleIfName[2]['lastclock'] = $now - 901;

ntCheck('Stale ifName walk: no mapping',
        LldpPortIdIdentity::localPortMap(
            $hosts, $staleIfName, $now
        ), []);

// Only visible hosts may contribute mapping rows.
ntCheck('Invisible switch: no mapping',
        LldpPortIdIdentity::localPortMap(
            ['102' => $hosts['102']], $localItems, $now
        ), []);


// Edge integration: preserve existing topology and distinguish
// one-sided LLDP+ARP+FDB evidence from confirmed physical links.

if (!method_exists(LldpPortIdIdentity::class, 'appendEdges')) {
    fwrite(STDERR,
        "[EXPECTED RED] appendEdges not implemented yet\n");
    exit(1);
}

$resolvedEdges = LldpPortIdIdentity::appendEdges(
    $hosts, $found, []
);

ntCheck('One reconciled LLDP edge',
        count($resolvedEdges), 1);

ntCheck('Destination hostid has string type',
        is_string($resolvedEdges[0]['to'] ?? null), true);

ntCheck('Correct switch and endpoint',
        [
            (string) ($resolvedEdges[0]['from'] ?? ''),
            (string) ($resolvedEdges[0]['to'] ?? ''),
        ], ['101', '102']);

ntCheck('Distinct LLDP Port-ID match kind',
        $resolvedEdges[0]['match'] ?? null,
        'lldp_portid');

ntCheck('One-sided LLDP remains unconfirmed',
        $resolvedEdges[0]['confirmed'] ?? null,
        false);

ntCheck('Only actual switch reports LLDP',
        $resolvedEdges[0]['reporters'] ?? null,
        ['101']);

ntCheck('Preserve evidence provenance',
        $resolvedEdges[0]['evidence'] ?? null,
        ['lldp_portid', 'arp', 'fdb']);

ntCheck('Preserve shared-port warning',
        $resolvedEdges[0]['fdb_shared_port'] ?? null,
        true);

ntCheck('No per-endpoint traffic attribution',
        $resolvedEdges[0]['port_metrics'] ?? null,
        []);

// Already existing LLDP connection must remain untouched.
$existing = [[
    'id' => 'e_existing',
    'from' => '101',
    'to' => '102',
    'match' => 'exact',
    'src' => ['lldp'],
    'ports' => ['101' => 'Gi1/0/5'],
]];

ntCheck('Existing LLDP edge not duplicated',
        LldpPortIdIdentity::appendEdges(
            $hosts, $found, $existing
        ), $existing);

// A wireless association must not turn into a wired edge.
$wifiExisting = [[
    'id' => 'wifi_existing',
    'from' => '101',
    'to' => '102',
    'match' => 'wifi',
    'src' => ['wifi'],
]];

ntCheck('Existing Wi-Fi edge preserved',
        LldpPortIdIdentity::appendEdges(
            $hosts, $found, $wifiExisting
        ), $wifiExisting);

// An unrelated infrastructure edge remains unchanged.
$infrastructure = [[
    'id' => 'e_trunk',
    'from' => '101',
    'to' => '104',
    'match' => 'exact',
    'src' => ['lldp'],
]];

$combined = LldpPortIdIdentity::appendEdges(
    $hosts, $found, $infrastructure
);

ntCheck('Infrastructure link preserved',
        $combined[0] ?? null, $infrastructure[0]);

ntCheck('Endpoint added without replacing infrastructure',
        count($combined), 2);

// Respect manual uplink regardless of candidate provenance.
$manualEndpoint = $hosts;
$manualEndpoint['102']['tags'] = [
    ['tag' => 'nt:uplink', 'value' => 'lab-switch:Gi1/0/8'],
];

ntCheck('Manual uplink blocks automatic edge',
        count(LldpPortIdIdentity::appendEdges(
            $manualEndpoint, $found, []
        )), 0);

// Repeated identical candidates must not duplicate edges.
ntCheck('Duplicate candidate is idempotent',
        count(LldpPortIdIdentity::appendEdges(
            $hosts, [$found[0], $found[0]], []
        )), 1);

// Conflicting reporters must not produce arbitrary links.
$conflictingCandidate = $found[0];
$conflictingCandidate['reporter_hostid'] = '104';

ntCheck('Conflicting switch candidates rejected',
        count(LldpPortIdIdentity::appendEdges(
            $hosts,
            [$found[0], $conflictingCandidate],
            []
        )), 0);

// Never allow a forged candidate to cross proxy boundaries.
$crossProxyEdge = $hosts;
$crossProxyEdge['102']['proxyid'] = '202';

ntCheck('Cross-proxy edge rejected',
        count(LldpPortIdIdentity::appendEdges(
            $crossProxyEdge, $found, []
        )), 0);

// A target outside the visible scope is not a valid endpoint.
$hiddenTarget = $hosts;
unset($hiddenTarget['102']);

ntCheck('Invisible target rejected',
        count(LldpPortIdIdentity::appendEdges(
            $hiddenTarget, $found, []
        )), 0);

echo $failures === 0
    ? "All LLDP Port-ID identity tests passed.\n"
    : "FAILURES: {$failures}\n";

exit($failures === 0 ? 0 : 1);
