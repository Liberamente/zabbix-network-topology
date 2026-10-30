<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only CDP diagnostic enrichment. Never claims device identity merely
 * because the reporter port is shared with an inferred LLDP/FDB edge.
 *
 * Inputs are the already scoped controller quality and edge arrays.
 * Returns an independent diagnostic list; does not alter unmatched or edges.
 */
final class CdpUnmatchedEvidence
{
    public static function classify(array $quality, array $edges): array
    {
        $byPort = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $kind = (string) ($edge['match'] ?? '');
            if (!in_array($kind, ['lldp_portid', 'fdb'], true)
                    || ($edge['confirmed'] ?? null) !== false) {
                continue;
            }
            foreach (($edge['ports'] ?? []) as $reporter => $port) {
                $port = strtolower(trim((string) $port));
                $reporter = (string) $reporter;
                if ($port === '' || $reporter === '') {
                    continue;
                }
                $from = (string) ($edge['from'] ?? '');
                $to = (string) ($edge['to'] ?? '');
                if ($from !== $reporter && $to !== $reporter) {
                    continue;
                }
                $target = $from === $reporter ? $to : $from;
                if ($target === '' || $target === $reporter) {
                    continue;
                }
                $byPort[$reporter . '|' . $port][] = [
                    'target' => $target,
                    'kind' => $kind,
                    'shared' => ($edge['fdb_shared_port'] ?? false) === true,
                ];
            }
        }

        $result = [];
        foreach ($quality as $reporter => $record) {
            $reporter = (string) ($record['id'] ?? $reporter);
            foreach (($record['unmatched'] ?? []) as $observation) {
                if (($observation['src'] ?? '') !== 'cdp') {
                    continue;
                }
                $port = strtolower(trim((string) ($observation['port'] ?? '')));
                $candidates = $port !== ''
                    ? ($byPort[$reporter . '|' . $port] ?? [])
                    : [];
                $targets = [];
                $kinds = [];
                $shared = false;
                foreach ($candidates as $candidate) {
                    $targets[$candidate['target']] = true;
                    $kinds[$candidate['kind']] = true;
                    $shared = $shared || $candidate['shared'];
                }
                $count = count($targets);
                $result[] = [
                    'reporter' => $reporter,
                    'raw' => (string) ($observation['raw'] ?? ''),
                    'port' => (string) ($observation['port'] ?? ''),
                    'status' => $count === 0 ? 'unmatched'
                        : ($count > 1 ? 'ambiguous' : 'associated_inferred'),
                    'candidate_count' => $count,
                    'shared_port' => $shared,
                    'evidence' => array_keys($kinds),
                    // Identity is not proven, including when count is one.
                    'identity_confirmed' => false,
                ];
            }
        }
        return $result;
    }
}
