<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once __DIR__ . '/../topology/PassThroughGroupScope.php';

use Modules\NetworkTopology\Topology\PassThroughGroupScope as Gate;

function checkGroupScope(bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
function scopeHost(string $proxy, array $tags = []): array {
    return ['proxyid' => $proxy, 'tags' => $tags];
}
function scopeTag(string $groupId): array {
    return ['tag' => Gate::OPT_IN_TAG, 'value' => $groupId];
}
$hosts = [
    '11' => scopeHost('100', [scopeTag('600')]),   // authorized FDB switch
    '12' => scopeHost('100'),                       // another FDB switch
    '13' => scopeHost('100'),                       // phone
    '14' => scopeHost('100'),                       // downstream device
    '21' => scopeHost('200'),                       // unrelated FDB switch
    '22' => scopeHost('200'),                       // unrelated endpoint
    '31' => scopeHost('0'),                         // server-monitored host
];
$switches = ['11', '12', '21'];
$call = static function (array $h, array $sw, array $gr, bool $hostMode = false): array {
    return Gate::eligible($h, $sw, $gr, $hostMode);
};

$off = $hosts;
$off['11']['tags'] = [];
checkGroupScope($call($off, $switches, ['600'])['hosts'] === [],
    'OFF without a host tag');
$tagOnlyEndpoint = $off;
$tagOnlyEndpoint['14']['tags'] = [scopeTag('600')];
checkGroupScope($call($tagOnlyEndpoint, $switches, ['600'])['hosts'] === [],
    'A tag on a non-FDB endpoint never enables a group');
$approved = $call($hosts, $switches, ['600']);
checkGroupScope(array_map('strval', array_keys($approved['hosts'])) === ['11', '12', '13', '14'],
    'One tagged switch authorizes all visible hosts on same proxy');
checkGroupScope($approved['switch_ids'] === ['11', '12'],
    'All same-proxy FDB switches become eligible without per-switch tags');
checkGroupScope($approved['proxy_ids'] === [100] || $approved['proxy_ids'] === ['100'],
    'Scope explicitly identifies only approved proxy');
checkGroupScope(!isset($approved['hosts']['21'], $approved['hosts']['22'])
        && !isset($approved['hosts']['31']),
    'Other proxies and zero-proxy hosts excluded');
checkGroupScope($call($hosts, $switches, ['601'])['hosts'] === [],
    'Same tagged switch cannot authorize unrelated group');
checkGroupScope($call($hosts, $switches, ['600', '601'])['hosts'] === [],
    'Multi-group view remains disabled');
checkGroupScope($call($hosts, $switches, ['600'], true)['hosts'] === [],
    'Host/hops view remains disabled');
checkGroupScope($call($hosts, $switches, ['0'])['hosts'] === [],
    'Group ID zero cannot be enabled');
checkGroupScope($call($hosts, $switches, ['600 '])['hosts'] === [],
    'Group ID must match exactly, no whitespace');
$badValues = ['1', 'true', '600 ', '600,601', '0600', '*', ''];
foreach ($badValues as $value) {
    $copy = $hosts;
    $copy['11']['tags'] = [scopeTag($value)];
    checkGroupScope($call($copy, $switches, ['600'])['hosts'] === [],
        'Reject malformed opt-in tag value: ' . ($value === '' ? '<empty>' : $value));
}
$zeroProxyTag = $off;
$zeroProxyTag['31']['tags'] = [scopeTag('600')];
checkGroupScope($call($zeroProxyTag, ['31'], ['600'])['hosts'] === [],
    'An unproxied switch cannot enable the inference');
$hiddenSwitchTag = $off;
$hiddenSwitchTag['11']['tags'] = [scopeTag('600')];
checkGroupScope($call($hiddenSwitchTag, ['12', '21'], ['600'])['hosts'] === [],
    'Tag from a switch not in available FDB sources cannot enable group');

$twoProxies = $hosts;
$twoProxies['21']['tags'] = [scopeTag('600')];
$result = $call($twoProxies, $switches, ['600']);
checkGroupScope(count($result['hosts']) === 6
    && count($result['switch_ids']) === 3,
    'A second tagged FDB switch safely enables a second proxy');
$onlySecond = $hosts;
$onlySecond['11']['tags'] = [];
$onlySecond['21']['tags'] = [scopeTag('600')];
$result = $call($onlySecond, $switches, ['600']);
checkGroupScope(array_map('strval', array_keys($result['hosts'])) === ['21', '22'],
    'Opt-in on other proxy does not leak into first proxy');

echo "PassThroughGroupScopeTest: PASS (synthetic; group and proxy isolation)\n";
