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

    /**
     * Reconcile a validated LLDP observation against one existing
     * inferred CDP edge, without modifying or adding graph edges.
     *
     * Candidates must originate from the scoped LLDP Port-ID feed.
     */
    public static function reconcileExisting(
        array $quality,
        array $unmatched,
        array $candidates,
        array $edges,
        array $hosts
    ): array {
        $claims = [];
        $identityCounts = [];

        foreach ($candidates as $candidate) {
            $reporter = (string) ($candidate['reporter_hostid'] ?? '');
            $target = (string) ($candidate['target_hostid'] ?? '');
            $index = (string) ($candidate['lldp_index'] ?? '');
            $port = strtolower(trim(
                (string) ($candidate['switch_port'] ?? '')
            ));

            if ($reporter === '' || $target === ''
                    || $reporter === $target || $port === ''
                    || !isset($hosts[$reporter], $hosts[$target])
                    || ($candidate['evidence'] ?? null)
                        !== ['lldp_portid', 'arp', 'fdb']
                    || ($candidate['confirmed'] ?? null) !== false
                    || !preg_match('/^\d+\.\d+\.\d+$/D', $index)
                    || preg_match(
                        '/^(?:Trk\d+|LAG\d+|Po\d+|Port[- ]?channel\d+|'
                        . 'Bond\d+|ae\d+|Eth[- ]?Trunk\d+|Bridge\d+)$/i',
                        $port
                    )) {
                continue;
            }

            $proxy = (string) ($hosts[$reporter]['proxyid'] ?? '0');
            if ($proxy === '0'
                    || $proxy !== (string)
                        ($hosts[$target]['proxyid'] ?? '0')) {
                continue;
            }

            foreach (($hosts[$target]['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:uplink'
                        && trim((string) ($tag['value'] ?? '')) !== '') {
                    continue 2;
                }
            }

            $identityKey = $reporter . '|' . $index;
            $identityCounts[$identityKey] =
                ($identityCounts[$identityKey] ?? 0) + 1;

            $claims[$target][] = [
                'identity_key' => $identityKey,
                'reporter' => $reporter,
                'target' => $target,
                'index' => $index,
                'port' => $port,
            ];
        }

        foreach ($claims as $target => $targetClaims) {
            $target = (string) $target;

            // Two observations, even identical ones, are ambiguous.
            if (count($targetClaims) !== 1) {
                continue;
            }

            $claim = $targetClaims[0];

            // A single LLDP observation must not identify
            // multiple endpoints or duplicate claims.
            if (($identityCounts[$claim['identity_key']] ?? 0) !== 1) {
                continue;
            }

            $incident = [];
            $portUsers = [];

            foreach ($edges as $edge) {
                if (!is_array($edge)) continue;

                $from = (string) ($edge['from'] ?? '');
                $to = (string) ($edge['to'] ?? '');

                if ($from === $target || $to === $target) {
                    $incident[] = $edge;
                }

                $usedPort = strtolower(trim((string)
                    ($edge['ports'][$claim['reporter']] ?? '')
                ));

                if ($usedPort === $claim['port']) {
                    $portUsers[] = $edge;
                }
            }

            // The endpoint and access port must identify the same,
            // single existing edge. Never suppress shared topology.
            if (count($incident) !== 1 || count($portUsers) !== 1
                    || $incident[0] !== $portUsers[0]) {
                continue;
            }

            $edge = $incident[0];

            if ((string) ($edge['from'] ?? '') !== $claim['reporter']
                    || (string) ($edge['to'] ?? '') !== $target
                    || !in_array('cdp', $edge['src'] ?? [], true)
                    || ($edge['match'] ?? '') !== 'ip'
                    || ($edge['confirmed'] ?? null) !== false) {
                continue;
            }

            // Reuse the exact-index, unique-observation guard.
            [$quality, $unmatched] = self::apply(
                $quality,
                $unmatched,
                [[
                    'from' => $claim['reporter'],
                    'to' => $target,
                    'match' => 'lldp_portid',
                    'lldp_index' => $claim['index'],
                    'confirmed' => false,
                    'evidence' => ['lldp_portid', 'arp', 'fdb'],
                ]]
            );
        }

        return [$quality, $unmatched];
    }
}
