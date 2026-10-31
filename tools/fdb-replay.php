<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

// Offline only. Input stays private; stdout contains aggregate counters only.
if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Usage: php tools/fdb-replay.php /private/snapshot.json\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'Modules\\NetworkTopology\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $file = array_pop($parts);
    $path = dirname(__DIR__) . '/' . implode('/', array_map('strtolower', $parts)) . '/' . $file . '.php';
    if (is_file($path)) require $path;
});

try {
    $raw = file_get_contents($argv[1]);
    if ($raw === false) throw new RuntimeException('Input cannot be read');
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    foreach (['hosts', 'walk_items', 'cap_items', 'edges', 'manual_uplinks', 'metrics'] as $key) {
        if (!is_array($input[$key] ?? null)) throw new RuntimeException('Missing array: ' . $key);
    }
    if (!is_int($input['now'] ?? null)) throw new RuntimeException('now must be the snapshot Unix timestamp');
    $diagnostics = [];
    $edges = \Modules\NetworkTopology\Topology\AutoFdbEdges::append(
        $input['hosts'], $input['walk_items'], $input['cap_items'],
        $input['edges'], $input['manual_uplinks'], $input['metrics'],
        $input['now'], $diagnostics
    );
    echo json_encode(['input_edges' => count($input['edges']),
        'output_edges' => count($edges), 'diagnostics' => $diagnostics],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Invalid snapshot or replay failed: " . $e->getMessage() . "\n");
    exit(1);
}
