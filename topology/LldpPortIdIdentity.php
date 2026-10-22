<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only LLDP Port-ID identity reconciliation.
 *
 * LLDP observation + unique scoped ARP identity + same-port FDB
 * produce a candidate, never a confirmed physical cable.
 *
 * Callers must supply already visibility-scoped hosts and items.
 * No SNMP, Zabbix API, database access or persistent writes.
 */
final class LldpPortIdIdentity {
    private const LLDP_TTL = 14400;
    private const DATA_TTL = 900;
    private const CLOCK_SKEW = 300;

    public static function candidates(
        array $hosts,
        array $lldpItems,
        array $arpItems,
        array $fdbHits,
        array $portMap,
        int $now
    ): array {
        if (!$hosts || !$lldpItems || !$arpItems
                || !$fdbHits || !$portMap) {
            return [];
        }

        $freshArp = [];
        foreach ($arpItems as $item) {
            $rid = (string) ($item['hostid'] ?? '');
            if (isset($hosts[$rid])
                    && ($item['key_'] ?? '') === 'nt.arp'
                    && self::validItem($item, $now, self::DATA_TTL)) {
                $freshArp[] = $item;
            }
        }
        if (!$freshArp) return [];

        // Resolve identities with the existing shared ARP parser.
        // Keep separate ARP-only and all-source ownership to avoid
        // claiming ARP provenance for a MAC known only from a tag.
        $arpOnlyHosts = [];
        foreach ($hosts as $id => $host) {
            $host['tags'] = [];
            $host['inventory'] = [];
            $arpOnlyHosts[$id] = $host;
        }

        $arpOwners = self::invert(
            AutoFdbEdges::macsByHost($arpOnlyHosts, $freshArp, $now)
        );
        $allOwners = self::invert(
            AutoFdbEdges::macsByHost($hosts, $freshArp, $now)
        );

        // Existing technical names, visible names and explicit
        // LLDP aliases must not be contradicted by the new match.
        $nameOwners = [];
        foreach ($hosts as $id => $host) {
            $names = [
                $host['host'] ?? '',
                $host['name'] ?? '',
            ];
            foreach (($host['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:lldp') {
                    $names[] = $tag['value'] ?? '';
                }
            }
            foreach ($names as $name) {
                $key = strtolower(trim((string) $name));
                if ($key !== '') {
                    $nameOwners[$key][(string) $id] = true;
                }
            }
        }

        // Pair LLDP SysName and Port-ID by reporter and exact
        // TimeMark.LocalPort.RemIndex, never by row order.
        $observations = [];
        foreach ($lldpItems as $item) {
            $rid = (string) ($item['hostid'] ?? '');
            if (!isset($hosts[$rid])
                    || !self::validItem($item, $now, self::LLDP_TTL)) {
                continue;
            }

            if (!preg_match(
                '/^(lldpRemSysName|lldpRemPortId)\[(\d+\.\d+\.\d+)\]$/',
                (string) ($item['key_'] ?? ''), $match
            )) {
                continue;
            }

            $id = $rid . '|' . $match[2];
            $field = $match[1] === 'lldpRemPortId'
                ? 'port_id' : 'sys_name';
            $value = (string) ($item['lastvalue'] ?? '');

            if (isset($observations[$id][$field])
                    && $observations[$id][$field]['value'] !== $value) {
                $observations[$id]['conflict'] = true;
            }

            $observations[$id]['reporter'] = $rid;
            $observations[$id]['index'] = $match[2];
            $observations[$id][$field] = [
                'value' => $value,
                'clock' => (int) $item['lastclock'],
            ];
        }

        // Normalized FDB: switch -> MAC -> distinct ports.
        // Occupancy counts distinct MACs, not duplicate VLAN rows.
        $fdb = [];
        $occupancy = [];
        foreach ($fdbHits as $hit) {
            $sid = (string) ($hit['hostid'] ?? '');
            $port = trim((string) ($hit['port'] ?? ''));
            $mac = self::mac((string) ($hit['mac'] ?? ''));
            $ts = (int) ($hit['ts'] ?? 0);

            if (!isset($hosts[$sid]) || $port === '' || $mac === null
                    || !self::fresh($ts, $now, self::DATA_TTL)) {
                continue;
            }

            $fdb[$sid][$mac][$port] = true;
            $occupancy[$sid][$port][$mac] = true;
        }

        $byTarget = [];

        foreach ($observations as $observation) {
            if (!empty($observation['conflict'])
                    || !isset($observation['port_id'])) {
                continue;
            }

            $rid = $observation['reporter'];
            $mac = self::mac($observation['port_id']['value']);
            if ($mac === null) continue;

            $owners = $allOwners[$mac] ?? [];
            $arpMatches = $arpOwners[$mac] ?? [];

            // Exactly one owner, with independent ARP provenance.
            if (count($owners) !== 1 || count($arpMatches) !== 1) {
                continue;
            }

            $target = (string) array_key_first($owners);
            if (!isset($arpMatches[$target]) || $rid === $target) {
                continue;
            }

            $proxy = (string) ($hosts[$rid]['proxyid'] ?? '0');
            if ($proxy === '0'
                    || (string) ($hosts[$target]['proxyid'] ?? '0')
                        !== $proxy) {
                continue;
            }

            // Manual uplinks take precedence over automatic identity
            // reconciliation. Never override an administrator's link.
            $hasManualUplink = false;
            foreach (($hosts[$target]['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:uplink'
                        && trim((string) ($tag['value'] ?? '')) !== '') {
                    $hasManualUplink = true;
                    break;
                }
            }
            if ($hasManualUplink) continue;

            // A real SysName matching a different scoped host
            // takes precedence over any MAC-based inference.
            if (isset($observation['sys_name'])) {
                $nameRow = $observation['sys_name'];

                if (abs($nameRow['clock']
                        - $observation['port_id']['clock'])
                        > self::CLOCK_SKEW) {
                    continue;
                }

                $name = strtolower(trim($nameRow['value']));
                $matches = $nameOwners[$name] ?? [];

                // An already recognized name or explicit alias belongs
                // to the existing LLDP resolver, not this fallback.
                // Conflicting or ambiguous names are also rejected.
                if ($matches) {
                    continue;
                }
            }

            $index = explode('.', $observation['index']);
            $localPort = $index[1] ?? '';

            // This mapping must be explicit and unique.
            // LLDP local port number is not assumed to be ifIndex.
            $switchPort = trim((string)
                ($portMap[$rid][$localPort] ?? ''));

            if ($switchPort === '') continue;

            // Reject ambiguous local LLDP port mappings.
            // Two LLDP port numbers identifying the same interface
            // cannot establish a unique physical port identity.
            $mappingCount = 0;
            foreach (($portMap[$rid] ?? []) as $mappedPort) {
                if (trim((string) $mappedPort) === $switchPort) {
                    ++$mappingCount;
                }
            }
            if ($mappingCount !== 1) continue;

            $ports = $fdb[$rid][$mac] ?? [];

            // Reject missing or conflicting FDB ports.
            if (count($ports) !== 1
                    || !isset($ports[$switchPort])) {
                continue;
            }

            $byTarget[$target][] = [
                'reporter_hostid' => $rid,
                'target_hostid' => $target,
                'switch_port' => $switchPort,
                'fdb_shared_port' =>
                    count($occupancy[$rid][$switchPort] ?? []) > 1,
                'confirmed' => false,
                'evidence' => ['lldp_portid', 'arp', 'fdb'],
            ];
        }

        // Multiple LLDP observations for the same target are
        // not silently resolved by choosing an arbitrary winner.
        $result = [];
        foreach ($byTarget as $candidates) {
            if (count($candidates) === 1) {
                $result[] = $candidates[0];
            }
        }

        return $result;
    }

    private static function invert(array $byHost): array {
        $owners = [];
        foreach ($byHost as $id => $macs) {
            foreach ($macs as $mac => $_) {
                $owners[$mac][(string) $id] = true;
            }
        }
        return $owners;
    }

    private static function validItem(
        array $item, int $now, int $ttl
    ): bool {
        return (string) ($item['status'] ?? '') === '0'
            && (string) ($item['state'] ?? '') === '0'
            && self::fresh(
                (int) ($item['lastclock'] ?? 0), $now, $ttl
            );
    }

    private static function fresh(
        int $clock, int $now, int $ttl
    ): bool {
        return $clock > 0
            && $clock <= $now + 60
            && $now - $clock <= $ttl;
    }

    private static function mac(string $raw): ?string {
        $raw = trim($raw);

        if (!preg_match(
            '/^(?:[0-9a-f]{12}|[0-9a-f]{4}(?:\.[0-9a-f]{4}){2}|'
            . '[0-9a-f]{2}(?:[:\s-][0-9a-f]{2}){5})$/iD',
            $raw
        )) {
            return null;
        }

        $mac = FdbResolver::normalizeMac($raw);
        if ($mac === null
                || $mac === '00:00:00:00:00:00'
                || $mac === 'ff:ff:ff:ff:ff:ff'
                || (hexdec(substr($mac, 0, 2)) & 1) !== 0) {
            return null;
        }

        return $mac;
    }
}
