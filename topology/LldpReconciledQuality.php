<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Remove Ghost observations only for newly added, traceable
 * LLDP Port-ID edges. Never infer identity from names or ports.
 */
final class LldpReconciledQuality {
    public static function apply(
        array $quality,
        array $unmatched,
        array $addedEdges
    ): array {
        foreach ($addedEdges as $edge) {
            if (($edge['match'] ?? '') !== 'lldp_portid'
                    || ($edge['evidence'] ?? null)
                        !== ['lldp_portid', 'arp', 'fdb']
                    || ($edge['confirmed'] ?? null) !== false) {
                continue;
            }

            $reporter = (string) ($edge['from'] ?? '');
            $index = (string) ($edge['lldp_index'] ?? '');
            $target = (string) ($edge['to'] ?? '');

            if ($reporter === '' || $target === ''
                    || $reporter === $target
                    || !preg_match(
                        '/^\d+\.\d+\.\d+$/D', $index
                    )) {
                continue;
            }

            if (!isset($quality[$reporter]['unmatched'])) {
                continue;
            }

            $matches = [];
            foreach ($quality[$reporter]['unmatched'] as $key => $entry) {
                if (($entry['src'] ?? '') === 'lldp'
                        && (string) ($entry['index'] ?? '') === $index) {
                    $matches[] = $key;
                }
            }

            // Never remove ambiguous or duplicate observations.
            if (count($matches) !== 1) {
                continue;
            }

            $key = $matches[0];
            $entry = $quality[$reporter]['unmatched'][$key];
            $raw = (string) ($entry['raw'] ?? '');

            if ($raw === '') {
                continue;
            }

            unset($quality[$reporter]['unmatched'][$key]);
            $quality[$reporter]['unmatched'] = array_values(
                $quality[$reporter]['unmatched']
            );

            $quality[$reporter]['matched'] =
                (int) ($quality[$reporter]['matched'] ?? 0) + 1;

            $legacy = $raw . ' (from hostid='
                . $reporter . ', src=lldp)';

            // Remove only one corresponding legacy occurrence.
            $legacyKey = array_search($legacy, $unmatched, true);
            if ($legacyKey !== false) {
                unset($unmatched[$legacyKey]);
                $unmatched = array_values($unmatched);
            }
        }

        return [$quality, $unmatched];
    }
}
