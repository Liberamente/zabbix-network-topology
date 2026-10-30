<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Read-only private replay for inferred Ethernet pass-through associations.
 *
 * Live mode:
 *   ZABBIX_API_TOKEN=... php tools/pass-through-replay.php \
 *     --graph /private/graph.json --group "example" \
 *     --api-url https://zabbix.example/api_jsonrpc.php
 *
 * Offline mode, for synthetic fixtures or privately acquired snapshots:
 *   php tools/pass-through-replay.php --snapshot /private/snapshot.json
 *
 * No Zabbix mutations, filesystem writes or individual host/port output.
 * Never commit graph, snapshot, tokens or customer data to public Git.
 */
require_once __DIR__ . '/../topology/FdbResolver.php';
require_once __DIR__ . '/../topology/FdbParser.php';
require_once __DIR__ . '/../topology/HostMetadata.php';
require_once __DIR__ . '/../topology/LldpEdgeBuilder.php';
require_once __DIR__ . '/../topology/AutoFdbEdges.php';
require_once __DIR__ . '/../topology/PassThroughEvidence.php';

use Modules\NetworkTopology\Topology\PassThroughEvidence;

function failReplay(string $message): never {
    fwrite(STDERR, "STOP: " . $message . "\n");
    exit(2);
}

function readPrivateJson(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        failReplay('private JSON file not found/readable');
    }
    $actual = realpath($path);
    $root = realpath(dirname(__DIR__));
    if ($actual === false || $root === false
            || $actual === $root || str_starts_with($actual, $root . '/')) {
        failReplay('input must be stored outside the repository');
    }
    if ((fileperms($actual) & 0077) !== 0) {
        failReplay('private input permissions must be 0600 or stricter');
    }
    $contents = file_get_contents($actual);
    if (!is_string($contents) || strlen($contents) > 16 * 1024 * 1024) {
        failReplay('input missing or larger than the 16 MiB safety limit');
    }
    try {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        failReplay('input is not valid JSON');
    }
    if (!is_array($data)) {
        failReplay('JSON document must be an object');
    }
    return $data;
}

function apiRead(string $url, string $token, string $method, array $params): array {
    static $seq = 0;
    $request = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => ++$seq,
    ], JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json-rpc\r\n"
                . "Authorization: Bearer " . $token . "\r\n",
            'content' => $request,
            'timeout' => 25,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    // Do not echo token, URL, error payload or inventory under any failure.
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        failReplay('Zabbix API transport failed; verify URL, TLS and access');
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        failReplay('Zabbix API returned invalid JSON');
    }
    if (!is_array($data) || isset($data['error'])
            || !array_key_exists('result', $data) || !is_array($data['result'])) {
        failReplay('Zabbix API request rejected: ' . $method);
    }
    return $data['result'];
}

function graphInfo(array $graph): array {
    $observed = (int) ($graph['observed_at'] ?? 0);
    if ($observed < 1 || abs(time() - $observed) > 600) {
        failReplay('graph export expired; recapture browser graph (10 minute window)');
    }
    if (!isset($graph['nodes'], $graph['edges'])
            || !is_array($graph['nodes']) || !is_array($graph['edges'])
            || !$graph['nodes']) {
        failReplay('graph must contain nodes and edges arrays');
    }
    $nodes = [];
    foreach ($graph['nodes'] as $node) {
        if (!is_array($node) || !isset($node['id'])
                || !preg_match('/^[0-9]+$/D', (string) $node['id'])) {
            failReplay('graph contains an invalid host id');
        }
        $nodes[(string) $node['id']] = true;
    }
    if (count($nodes) !== count($graph['nodes'])) {
        failReplay('duplicate graph nodes');
    }
    foreach ($graph['edges'] as $edge) {
        if (!is_array($edge)
                || !isset($nodes[(string) ($edge['from'] ?? '')],
                            $nodes[(string) ($edge['to'] ?? '')])) {
            failReplay('graph edge refers to a non-visible endpoint');
        }
    }
    return $nodes;
}

function fingerprint(array $ids): string {
    sort($ids, SORT_STRING);
    $hash = 2166136261;
    foreach (str_split(implode('|', $ids)) as $char) {
        $hash = (($hash ^ ord($char)) * 16777619) & 0xffffffff;
    }
    return sprintf('%08x', $hash);
}

function replay(array $hosts, array $walk, array $caps, array $cdp,
                array $edges, array $manual, int $now): array {
    if (!$hosts || !$walk || !$edges || !$caps || !$cdp) {
        failReplay('required replay sources are absent');
    }
    $nodes = array_fill_keys(array_map('strval', array_keys($hosts)), true);
    foreach ($edges as $edge) {
        if (!is_array($edge)
                || !isset($nodes[(string) ($edge['from'] ?? '')],
                            $nodes[(string) ($edge['to'] ?? '')])) {
            failReplay('replay edge has a non-visible endpoint');
        }
    }
    foreach ([$walk, $caps, $cdp] as $source) {
        foreach ($source as $item) {
            if (!is_array($item) || !isset($nodes[(string) ($item['hostid'] ?? '')])) {
                failReplay('source item refers to non-visible host');
            }
        }
    }
    $diagnostics = [];
    $new = PassThroughEvidence::propose(
        $hosts, $walk, $caps, $cdp, $edges, $manual, $now, $diagnostics
    );
    $linked = [];
    foreach ($edges as $edge) {
        $linked[(string) $edge['from']] = true;
        $linked[(string) $edge['to']] = true;
    }
    $isolatedBefore = 0;
    foreach ($hosts as $hid => $_) {
        if (!isset($linked[(string) $hid])) $isolatedBefore++;
    }
    $pairs = [];
    foreach ($new as $edge) {
        $a = (string) $edge['from'];
        $b = (string) $edge['to'];
        if (!isset($nodes[$a], $nodes[$b]) || isset($linked[$b])
                || ($edge['confirmed'] ?? null) !== false
                || ($edge['match'] ?? '') !== 'pass_through_inferred'
                || !empty($edge['port_metrics']) || !empty($edge['ports'])
                || isset($pairs[$b])) {
            failReplay('candidate violates isolation or evidence invariants');
        }
        $pairs[$b] = true;
    }
    return [
        'hosts' => count($hosts),
        'original_edges' => count($edges),
        'isolated_before' => $isolatedBefore,
        'new_inferred_candidates' => count($new),
        'diagnostics' => $diagnostics, // Aggregate counts, no host, port or MAC IDs.
        'edges_theoretical_after' => count($edges) + count($new),
        'isolated_theoretical_after' => $isolatedBefore - count($new),
        'confidence' => 'inferred_only_not_physical',
        'zabbix_mutations' => 0,
        'module_deployed' => false,
    ];
}

function main(array $args): void {
    $opts = getopt('', ['graph:', 'group:', 'api-url:', 'snapshot:']);
    if (!is_array($opts)) failReplay('invalid arguments');
    $offline = isset($opts['snapshot']);
    if ($offline) {
        if (count($opts) !== 1) failReplay('snapshot mode accepts no other options');
        $snap = readPrivateJson((string) $opts['snapshot']);
        $keys = ['hosts', 'walk_items', 'cap_items', 'cdp_items', 'edges', 'manual_uplinks'];
        foreach ($keys as $key) {
            if (!isset($snap[$key]) || !is_array($snap[$key])) {
                failReplay('snapshot missing key: ' . $key);
            }
        }
        $graph = ['observed_at' => $snap['observed_at'] ?? 0,
                  'nodes' => array_map(
                      static fn($id): array => ['id' => (string) $id],
                      array_keys($snap['hosts'])
                  ),
                  'edges' => $snap['edges']];
        graphInfo($graph);
        $result = replay($snap['hosts'], $snap['walk_items'], $snap['cap_items'],
                         $snap['cdp_items'], $snap['edges'], $snap['manual_uplinks'],
                         time());
    } else {
        if (!isset($opts['graph'], $opts['group'], $opts['api-url'])
                || count($opts) !== 3) {
            failReplay('use --graph PRIVATE_PATH --group GROUP --api-url HTTPS_API');
        }
        $url = (string) $opts['api-url'];
        $urlParts = parse_url($url);
        if (!is_array($urlParts) || empty($urlParts['host'])
                || !str_ends_with((string) ($urlParts['path'] ?? ''),
                                  '/api_jsonrpc.php')
                || isset($urlParts['user']) || isset($urlParts['pass'])
                || isset($urlParts['query']) || isset($urlParts['fragment'])) {
            failReplay('API URL must be an api_jsonrpc.php URL without credentials');
        }
        $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $host = strtolower((string) $urlParts['host']);
        if ($scheme !== 'https'
                && !($scheme === 'http'
                    && in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            failReplay('HTTPS required, except explicit loopback HTTP');
        }
        $token = (string) getenv('ZABBIX_API_TOKEN');
        if (strlen($token) < 12 || str_contains($token, "\n")
                || str_contains($token, "\r")) {
            failReplay('ZABBIX_API_TOKEN environment variable missing or malformed');
        }
        $graph = readPrivateJson((string) $opts['graph']);
        $visible = graphInfo($graph);
        $group = trim((string) $opts['group']);
        if ($group === '' || strlen($group) > 255) {
            failReplay('select exactly one valid group');
        }
        $groups = apiRead($url, $token, 'hostgroup.get', [
            'output' => ['groupid', 'name'], 'filter' => ['name' => [$group]]
        ]);
        if (count($groups) !== 1 || ($groups[0]['name'] ?? null) !== $group) {
            failReplay('group name is not unique or not visible');
        }
        $hostsResult = apiRead($url, $token, 'host.get', [
            'groupids' => [(string) $groups[0]['groupid']],
            'monitored_hosts' => true,
            'output' => ['hostid', 'proxyid', 'host', 'name'],
            'selectInterfaces' => ['ip'],
            'selectTags' => ['tag', 'value'],
            'selectInventory' => ['macaddress_a', 'macaddress_b'],
        ]);
        $hosts = [];
        foreach ($hostsResult as $hostRow) {
            $hosts[(string) $hostRow['hostid']] = $hostRow;
        }
        $actual = array_fill_keys(array_map('strval', array_keys($hosts)), true);
        if (count($actual) !== count($visible)
                || array_diff_key($actual, $visible)
                || array_diff_key($visible, $actual)) {
            failReplay('graph and Zabbix API hosts differ; refresh and re-export');
        }
        $hostids = array_keys($visible);
        $walk = apiRead($url, $token, 'item.get', [
            'hostids' => $hostids,
            'monitored' => true,
            'output' => ['hostid', 'key_', 'lastclock', 'lastvalue', 'status', 'state'],
            'filter' => ['key_' => [
                'nt.fdb.dot1d', 'nt.fdb.dot1q', 'nt.fdb.baseport',
                'nt.fdb.ifname', 'nt.arp',
            ]],
        ]);
        $switches = [];
        foreach ($walk as $item) {
            if (str_starts_with((string) ($item['key_'] ?? ''), 'nt.fdb.')) {
                $switches[(string) $item['hostid']] = true;
            }
        }
        if (!$switches) failReplay('no visible FDB switches');
        $itemOpts = [
            'hostids' => array_keys($switches),
            'monitored' => true,
            'output' => ['hostid', 'key_', 'lastclock', 'lastvalue', 'status', 'state'],
        ];
        $caps = apiRead($url, $token, 'item.get',
            $itemOpts + ['search' => ['key_' => 'lldpRemSysCapEnabled']]);
        $cdp = apiRead($url, $token, 'item.get',
            $itemOpts + ['search' => ['key_' => 'cdpCacheDeviceId']]);
        $manual = [];
        foreach ($hosts as $hid => $hostRow) {
            foreach (($hostRow['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:uplink') {
                    $manual[(string) $hid] = true;
                }
            }
        }
        $result = replay($hosts, $walk, $caps, $cdp, $graph['edges'],
                         $manual, time());
        $result['graph_age_seconds'] = time() - (int) $graph['observed_at'];
        $result['host_fingerprint'] = fingerprint(array_keys($hosts));
        $result['arp_items'] = count(array_filter($walk,
            static fn(array $x): bool => ($x['key_'] ?? '') === 'nt.arp'));
        $result['fdb_switches'] = count($switches);
        $result['cdp_items'] = count($cdp);
        $result['lldp_cap_items'] = count($caps);
    }
    echo "=== PASS-THROUGH PRIVATE REPLAY (READ-ONLY) ===\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR) . "\n";
}

main($argv);
