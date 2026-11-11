<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Synthetic-only regression for two live-proven SOA code corrections.
// Run: php tests/SoaLiveCorrectionsTest.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/'
        . implode('/', array_map('strtolower', $parts))
        . '/' . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;
use Modules\NetworkTopology\Topology\AutoFdbEdges;

$failed = 0;

function verify(string $label, bool $condition): void {
    global $failed;
    if (!$condition) {
        $failed++;
    }
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
}

/** Tests the isolated matching branch, not the controller visibility query. */
function resolveAlias(array $hosts, string $chassis): array {
    $rows = [[
        'rid' => '100',
        'rhid' => null,
        'ambiguous' => null,
        'item' => [
            'src' => 'lldp',
            'key_' => 'lldpRemSysName[0.1.123]'
        ],
        'raw' => 'unknown-neighbor'
    ]];

    $meta = [
        '100' => [
            '0.1.123' => ['chassis' => $chassis]
        ]
    ];

    $method = new ReflectionMethod(
        LldpEdgeBuilder::class,
        'resolveByDeclaredChassisAlias'
    );
    $method->setAccessible(true);
    $method->invokeArgs(null, [&$rows, $meta, $hosts]);
    return $rows[0];
}

$reporter = [
    'host' => 'synthetic-switch',
    'name' => 'Synthetic Switch',
    'proxyid' => '10'
];

$target = [
    'host' => 'synthetic-neighbor',
    'name' => 'Synthetic Neighbor',
    'proxyid' => '10',
    'nt_aliases' => ['02:11:22:33:44:55']
];

$hosts = ['100' => $reporter, '200' => $target];

verify('LLDP alias univoco',
    resolveAlias($hosts, '02:11:22:33:44:55')['rhid'] === '200');

verify('LLDP alias di altro proxy escluso',
    resolveAlias([
        '100' => $reporter,
        '200' => array_replace($target, ['proxyid' => '20'])
    ], '02:11:22:33:44:55')['rhid'] === null);

verify('LLDP alias ambiguo escluso',
    resolveAlias($hosts + [
        '300' => array_replace($target, [
            'host' => 'synthetic-other',
            'proxyid' => '10'
        ])
    ], '02:11:22:33:44:55')['rhid'] === null);

verify('LLDP vicino fuori ambito escluso',
    resolveAlias(['100' => $reporter],
        '02:11:22:33:44:55')['rhid'] === null);

verify('LLDP identificatore non MAC escluso',
    resolveAlias($hosts, 'opaque-neighbor-01')['rhid'] === null);

$aggregate = new ReflectionMethod(
    AutoFdbEdges::class,
    'isAggregatePort'
);
$aggregate->setAccessible(true);

foreach ([
    'Bridge-Aggregation1',
    'Bridge-Aggregation 12',
    'Bridge Aggregation2',
    // SOA field evidence: MikroTik IF-MIB ifType 161 (ieee8023adLag).
    // The model must not confuse those named logical LAG interfaces with
    // physical Ethernet access ports when producing inferred FDB links.
    'client-bond3',
    'client-bond4',
    'client-bond5'
] as $port) {
    verify("FDB aggregazione $port",
        $aggregate->invoke(null, $port) === true);
}

foreach (['GE19', 'swp00', 'ether1', 'client-bondX'] as $port) {
    verify("FDB non aggregata per solo nome $port",
        $aggregate->invoke(null, $port) === false);
}

echo $failed === 0
    ? "SOA_CORRECTIONS_TEST=PASS\n"
    : "SOA_CORRECTIONS_TEST=FAIL ($failed)\n";

exit($failed === 0 ? 0 : 1);
