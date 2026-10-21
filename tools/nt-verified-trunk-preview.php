#!/usr/bin/env php
<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Offline-only adapter preview for an operator-controlled zabbix-tools report.
 *
 * No API client, shell command or production Zabbix module hook is involved.
 * The report is private; all generated outputs must remain private as well.
 */
require_once dirname(__DIR__) . '/topology/VerifiedTrunkAdapter.php';

use Modules\NetworkTopology\Topology\VerifiedTrunkAdapter;

function quoteDot(string $value): string {
    $s = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($s === false) {
        throw new RuntimeException('cannot encode DOT label');
    }
    return $s;
}

function asDot(array $payload): string {
    $lines = [
        'strict graph "VERIFIED STATIC TRUNK PREVIEW" {',
        '  graph [rankdir=LR, splines=curved, bgcolor="white",',
        '         label="OFFLINE SNAPSHOT - NOT PUBLISHED - LIVE STATUS UNKNOWN",',
        '         labelloc="t", fontname="DejaVu Sans", fontsize=13];',
        '  node [shape=box, style="rounded,filled", fillcolor="#e9f1f7",',
        '        color="#35759c", fontname="DejaVu Sans"];',
        '  edge [color="#287ba9", penwidth=2.5, fontname="DejaVu Sans"];',
    ];
    foreach ($payload['nodes'] as $node) {
        $lines[] = '  ' . quoteDot((string) $node['id'])
            . ' [label=' . quoteDot((string) $node['label']) . '];';
    }
    foreach ($payload['edges'] as $edge) {
        $from = (string) $edge['from'];
        $to = (string) $edge['to'];
        $members = [];
        foreach ($edge['nt_members'] as $member) {
            $members[] = (string) $member['ports'][$from]
                . ' <-> ' . (string) $member['ports'][$to];
        }
        $label = (string) $edge['ports'][$from] . ' - '
               . (string) $edge['ports'][$to]
               . ' (' . count($members) . ' physical ports)';
        $tip = 'Verified static membership: ' . implode('; ', $members)
             . ' (offline only; no traffic/health claim)';
        $lines[] = '  ' . quoteDot($from) . ' -- ' . quoteDot($to)
            . ' [id=' . quoteDot((string) $edge['id'])
            . ', label=' . quoteDot($label)
            . ', tooltip=' . quoteDot($tip) . '];';
    }
    $lines[] = '}';
    return implode("\n", $lines) . "\n";
}

function privateOutput(string $file, string $contents): void {
    if (!is_dir(dirname($file))) {
        throw new RuntimeException('output parent directory missing');
    }
    $handle = @fopen($file, 'x');
    if ($handle === false) {
        throw new RuntimeException('output path exists or cannot be opened');
    }
    $complete = false;
    try {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('failed writing private output');
            }
            $offset += $written;
        }
        if (!@chmod($file, 0600)) {
            throw new RuntimeException('cannot restrict output permissions');
        }
        $complete = true;
    } finally {
        fclose($handle);
        if (!$complete) {
            @unlink($file);
        }
    }
}

function main(array $argv): int {
    $usage = "Usage:\n"
        . "  php tools/nt-verified-trunk-preview.php --input REPORT.json "
        . "--output PREVIEW.json --group-id GROUPID "
        . "--hostids ID1,ID2,... [--dot-output PREVIEW.dot] "
        . "[--expect-links N]\n";
    // Require explicit named arguments and never infer tenant scope from
    // filenames or from the untrusted report body.
    $opt = getopt('', [
        'input:', 'output:', 'group-id:', 'hostids:',
        'dot-output:', 'expect-links:',
    ]);
    foreach (['input','output','group-id','hostids'] as $needed) {
        if (!isset($opt[$needed]) || !is_string($opt[$needed])
                || $opt[$needed] === '') {
            fwrite(STDERR, $usage);
            return 2;
        }
    }
    $in = $opt['input'];
    $out = $opt['output'];
    $dot = $opt['dot-output'] ?? null;
    if (!is_file($in) || filesize($in) === false || filesize($in) > 8 * 1024 * 1024) {
        throw new RuntimeException('input report missing or exceeds 8 MiB');
    }
    if ($in === $out || ($dot !== null && ($dot === $out || $dot === $in))) {
        throw new RuntimeException('input, JSON output and DOT output must differ');
    }
    $text = @file_get_contents($in);
    if ($text === false) {
        throw new RuntimeException('unable to read input report');
    }
    $report = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($report)) {
        throw new InvalidArgumentException('input must be a JSON object');
    }
    $hostids = array_map('trim', explode(',', $opt['hostids']));
    $payload = VerifiedTrunkAdapter::preview(
        $report, $hostids, $opt['group-id']);
    if (isset($opt['expect-links'])) {
        $expected = filter_var($opt['expect-links'], FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 1
                || $payload['summary']['logical_links'] !== $expected) {
            throw new RuntimeException('unexpected verified logical link count');
        }
    }
    umask(0077);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    // All checks happen before the first output file is written.
    $graph = $dot !== null ? asDot($payload) : null;
    if ($dot !== null && (file_exists($dot) || !is_dir(dirname($dot)))) {
        throw new RuntimeException('DOT output exists or its parent directory is missing');
    }
    privateOutput($out, $json);
    if ($dot !== null) {
        privateOutput($dot, $graph);
    }
    echo json_encode([
        'preview_only' => true, 'published' => false,
        'output' => $out, 'dot_output' => $dot,
        'summary' => $payload['summary'],
        'live_status_available' => false,
    ], JSON_THROW_ON_ERROR) . "\n";
    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $ex) {
    // Do not echo private paths, JSON fragments or host identities on errors.
    fwrite(STDERR, "Preview rejected: " . get_class($ex)
        . " (see offline guide and input evidence checks)\n");
    exit(1);
}
