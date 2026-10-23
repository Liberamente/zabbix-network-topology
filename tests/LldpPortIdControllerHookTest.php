<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Static wiring/feature-flag regression.
// Does not execute Zabbix API calls or require a running frontend.

$source = file_get_contents(
    dirname(__DIR__) . '/actions/NetworkTopologyData.php'
);

if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read controller\n");
    exit(1);
}

$failed = 0;

function hookCheck(string $label, bool $condition): void {
    global $failed;
    if (!$condition) ++$failed;
    echo ($condition ? '[PASS] ' : '[FAIL] ')
        . $label . "\n";
}

$flag = 'private const AUTO_LLDP_PORTID_EDGES = false;';
$guard = 'if (self::AUTO_LLDP_PORTID_EDGES && $fdb_switches) {';

hookCheck('Flag OFF by default',
    substr_count($source, $flag) === 1);

hookCheck('Exactly one guarded hook',
    substr_count($source, $guard) === 1);

$pos = strpos($source, $guard);
$end = $pos === false
    ? false
    : strpos($source, '                $caps = [];', $pos);

$block = ($pos !== false && $end !== false)
    ? substr($source, $pos, $end - $pos)
    : '';

hookCheck('Two API reads inside guard',
    substr_count($block, 'API::Item()->get(') === 2);

hookCheck('Reads scoped to selected switch hostids',
    substr_count($block, "'hostids'") === 2
    && substr_count($block, '=> $scopeSwitchIds') === 2);

hookCheck('LLDP source includes Port-ID and SysName',
    strpos($block, 'lldpRemPortId[') !== false
    && strpos($block, 'lldpRemSysName[') !== false
    && strpos($block, "'searchByAny' => true") !== false);

hookCheck('Explicit local port walks included',
    strpos($block, 'nt.lldp.local.portid') !== false
    && strpos($block, 'nt.lldp.local.portdesc') !== false);

hookCheck('Feed adapter called only inside guard',
    substr_count($source, 'LldpPortIdFeed::append(') === 1
    && strpos($block, 'LldpPortIdFeed::append(') !== false);

$wifi = strpos($source, 'AutoWifiEdges::append(');
$fdb = strpos($source, 'AutoFdbEdges::append(');

hookCheck('Order: Wi-Fi, Port-ID, FDB',
    $wifi !== false
    && $pos !== false
    && $fdb !== false
    && $wifi < $pos
    && $pos < $fdb);

hookCheck('Existing access-link flag preserved',
    strpos($source,
        'private const AUTO_DISCOVER_ACCESS_LINKS = true;'
    ) !== false);

echo $failed
    ? "FAIL: $failed controller checks\n"
    : "PASS: LLDP Port-ID controller wiring\n";

exit($failed ? 1 : 0);
