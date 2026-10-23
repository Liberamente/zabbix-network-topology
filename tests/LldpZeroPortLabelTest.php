<?php
// Synthetic regression: LLDP local port 0 is not a physical port name.
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/' . implode('/', array_map('strtolower', $parts)) . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

use Modules\NetworkTopology\Topology\LldpEdgeBuilder;

$method = new ReflectionMethod(LldpEdgeBuilder::class, 'portContext');
$cases = [
    ['lldpRemSysName[0.0.42]', [], 'LLDP index 0', '0'],
    ['lldpRemSysName[0.0.42]', ['0' => 'bridge0'], 'bridge0', '0'],
    ['lldpRemSysName[0.23.42]', [], '23', '23'],
    ['lldpRemSysName[0.23.42]', ['23' => 'GE23'], 'GE23', '23'],
    ['cdpCacheDeviceId[0.42]', [], '0', '0'],
];
foreach ($cases as $n => [$key, $names, $expectedLabel, $expectedIndex]) {
    $row = ['key_' => $key];
    [$index, $label, $portIdx] = $method->invoke(null, $row, 'reporter', [], ['reporter' => $names]);
    if ($label !== $expectedLabel || $portIdx !== $expectedIndex) {
        fwrite(STDERR, 'FAIL case ' . $n . ': ' . var_export([$index, $label, $portIdx], true) . "\n");
        exit(1);
    }
}
// An unavailable remote Port-ID stays unknown, not invented.
[$index, $label, $portIdx, $remote] = $method->invoke(
    null, ['key_' => 'lldpRemSysName[0.0.42]'], 'reporter',
    ['reporter' => ['0.0.42' => ['desc' => '', 'id' => '']]], []
);
if ($remote !== '') {
    fwrite(STDERR, "FAIL: empty remote port must remain empty\n");
    exit(1);
}
echo "OK: 6 LLDP/CDP port label regression cases\n";
