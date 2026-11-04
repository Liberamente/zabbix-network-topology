<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/'
        . implode('/', array_map('strtolower', $parts))
        . '/' . $file . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;
use Modules\NetworkTopology\Topology\NodeBuilder;

function verify(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }

    echo "PASS: $message\n";
}

$hosts = [];

foreach (['ap', 'router', 'sw'] as $id) {
    $hosts[$id] = [
        'host' => "lab-$id",
        'name' => "LAB $id",
        'interfaces' => [],
        'parentTemplates' => [],
        'inventory' => [],
        'maintenance_status' => '0',
        'proxyid' => '0',
        'proxy_groupid' => '0',
        'active_available' => '1',
    ];
}

$observations = [
    [
        'hostid' => 'ap',
        'key_' => 'lldpRemSysName[0.0.41]',
        'lastvalue' => 'lab-router',
        'src' => 'lldp',
    ],
    [
        'hostid' => 'ap',
        'key_' => 'lldpRemSysName[0.0.42]',
        'lastvalue' => 'unknown-lab-node',
        'src' => 'lldp',
    ],
    [
        'hostid' => 'sw',
        'key_' => 'lldpRemSysName[0.23.1]',
        'lastvalue' => 'lab-ap',
        'src' => 'lldp',
    ],
];

$result = LldpEdgeBuilder::build($hosts, $observations);
$q = $result['quality'];

verify(
    count($result['edges']) === 1,
    'Un solo collegamento LLDP valido'
);

$edge = $result['edges'][0];
$ends = [(string) $edge['from'], (string) $edge['to']];
sort($ends);

verify(
    $ends === ['ap', 'sw'],
    'Collegamento fisico SW-AP preservato'
);

verify(
    ($q['ap']['matched'] ?? -1) === 0,
    'Porta zero non conteggiata come matched'
);

verify(
    count($q['ap']['excluded'] ?? []) === 2,
    'Due osservazioni sulla porta zero escluse'
);

verify(
    empty($q['ap']['unmatched']),
    'Nessun vicino fantasma dalla porta zero'
);

verify(
    ($q['sw']['matched'] ?? 0) === 1,
    'Porta fisica 23 conteggiata correttamente'
);

$output = NodeBuilder::build(
    $hosts,
    [
        'cpu' => [], 'memory' => [], 'traffic' => [],
        'iface' => [], 'speed' => [], 'ping' => [],
    ],
    [
        'icon_override' => [],
        'show_keys' => [],
        'links' => [],
    ],
    [
        'severity' => [], 'problems' => [],
        'problem_list' => [], 'ack_total' => [],
        'ack_acked' => [], 'last_seen' => [],
    ],
    [
        'group_names' => [],
        'proxy_names' => [],
        'pgroup_names' => [],
        'lldp_quality' => $q,
        'items_show' => [],
        'show_item_per_host' => [],
        'primary_ip_cache' => [],
    ]
);

$quality = array_column($output['lldp_quality'], null, 'id');

verify(
    count($quality['ap']['excluded'] ?? []) === 2,
    'Esclusioni trasmesse al pannello LLDP Quality'
);

echo "LLDP_LOCAL_ZERO_TEST=PASS\n";
