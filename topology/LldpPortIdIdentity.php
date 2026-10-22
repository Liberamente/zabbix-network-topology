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

    /**
     * Map LLDP local port numbers to actual IF-MIB interface names.
     *
     * Uses explicit LLDP Port-ID/PortDesc values and ifName walks.
     * Never assumes LLDP local port number equals ifIndex.
     *
     * @return array<string, array<string, string>>
     */
    public static function localPortMap(
        array $hosts, array $walkItems, int $now
    ): array {
        $keys = [
            'nt.lldp.local.portid',
            'nt.lldp.local.portdesc',
            'nt.fdb.ifname',
        ];

        $walks = [];
        $conflicts = [];

        foreach ($walkItems as $item) {
            $hid = (string) ($item['hostid'] ?? '');
            $key = (string) ($item['key_'] ?? '');

            if (!isset($hosts[$hid])
                    || !in_array($key, $keys, true)) {
                continue;
            }

            $ttl = $key === 'nt.fdb.ifname'
                ? self::DATA_TTL : self::LLDP_TTL;

            if (!self::validItem($item, $now, $ttl)) {
                continue;
            }

            $value = (string) ($item['lastvalue'] ?? '');

            if (isset($walks[$hid][$key])
                    && $walks[$hid][$key] !== $value) {
                $conflicts[$hid] = true;
            }

            $walks[$hid][$key] = $value;
        }

        $result = [];

        foreach ($walks as $hid => $data) {
            if (isset($conflicts[$hid])
                    || !isset($data['nt.lldp.local.portid'])
                    || !isset($data['nt.fdb.ifname'])) {
                continue;
            }

            $ids = self::portWalkValues(
                $data['nt.lldp.local.portid'],
                '1.0.8802.1.1.2.1.3.7.1.3.'
            );

            $descs = self::portWalkValues(
                $data['nt.lldp.local.portdesc'] ?? '',
                '1.0.8802.1.1.2.1.3.7.1.4.'
            );

            $interfaces = self::portWalkValues(
                $data['nt.fdb.ifname'],
                '1.3.6.1.2.1.31.1.1.1.1.'
            );

            // Interface name must identify exactly one ifIndex.
            $byName = [];

            foreach ($interfaces as $ifIndex => $values) {
                if (count($values) !== 1) continue;

                $label = (string) array_key_first($values);
                $normalized = strtolower(trim($label));

                $byName[$normalized][(string) $ifIndex] = $label;
            }

            $candidates = [];

            foreach ($ids as $lldpPort => $idValues) {
                if (count($idValues) !== 1) continue;

                if (isset($descs[$lldpPort])
                        && count($descs[$lldpPort]) !== 1) {
                    continue;
                }

                $fromId = self::uniqueInterfaceName(
                    $idValues, $byName
                );

                $fromDesc = isset($descs[$lldpPort])
                    ? self::uniqueInterfaceName(
                        $descs[$lldpPort], $byName
                    )
                    : null;

                // Two valid but different interface names:
                // ambiguous physical port, therefore reject.
                if ($fromId !== null && $fromDesc !== null
                        && strcasecmp($fromId, $fromDesc) !== 0) {
                    continue;
                }

                $name = $fromId ?? $fromDesc;
                if ($name !== null) {
                    $candidates[(string) $lldpPort] = $name;
                }
            }

            // Two LLDP local ports must never claim the
            // same physical IF-MIB interface.
            $claims = [];

            foreach ($candidates as $lldpPort => $name) {
                $claims[strtolower($name)][] = $lldpPort;
            }

            foreach ($candidates as $lldpPort => $name) {
                if (count($claims[strtolower($name)]) === 1) {
                    $result[(string) $hid][(string) $lldpPort] = $name;
                }
            }
        }

        return $result;
    }

    /**
     * Parse an exact numeric SNMP walk column.
     * Keep all values for each index to detect conflicting rows.
     */
    private static function portWalkValues(
        string $walk, string $prefix
    ): array {
        $result = [];

        foreach (FdbParser::lines($walk) as [$oid, $value]) {
            $oid = ltrim($oid, '.');

            if (!str_starts_with($oid, $prefix)) {
                continue;
            }

            $index = substr($oid, strlen($prefix));
            $value = trim((string) $value);

            if ($index === '' || !ctype_digit($index)
                    || $value === '') {
                continue;
            }

            $result[$index][$value] = true;
        }

        return $result;
    }

    /**
     * Resolve a label only if a single IF-MIB interface owns it.
     */
    private static function uniqueInterfaceName(
        array $values, array $byName
    ): ?string {
        if (count($values) !== 1) return null;

        $label = strtolower(trim(
            (string) array_key_first($values)
        ));

        $matches = $byName[$label] ?? [];

        if (count($matches) !== 1) return null;

        return (string) reset($matches);
    }

    /**
     * Append conservatively reconciled LLDP endpoint edges.
     *
     * Existing edges always win. A candidate is not proof of a
     * reciprocal or independently confirmed physical connection.
     *
     * This method is pure: no Zabbix API, database or writes.
     */
    public static function appendEdges(
        array $hosts, array $candidates, array $edges
    ): array {
        if (!$hosts || !$candidates) {
            return $edges;
        }

        $alreadyLinked = [];
        $occupiedPorts = [];
        $existingIds = [];

        foreach ($edges as $edge) {
            if (!is_array($edge)) continue;

            foreach (['from', 'to'] as $side) {
                $hid = (string) ($edge[$side] ?? '');
                if ($hid !== '') {
                    $alreadyLinked[$hid] = true;
                }
            }

            $id = (string) ($edge['id'] ?? '');
            if ($id !== '') {
                $existingIds[$id] = true;
            }

            foreach (($edge['ports'] ?? []) as $hid => $port) {
                $normalized = strtolower(trim((string) $port));
                if ($normalized !== '') {
                    $occupiedPorts[(string) $hid][$normalized] = true;
                }
            }
        }

        $byTarget = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;

            $reporter = (string)
                ($candidate['reporter_hostid'] ?? '');
            $target = (string)
                ($candidate['target_hostid'] ?? '');
            $port = trim((string)
                ($candidate['switch_port'] ?? ''));

            if ($reporter === '' || $target === ''
                    || $reporter === $target || $port === ''
                    || !isset($hosts[$reporter], $hosts[$target])
                    || isset($alreadyLinked[$target])) {
                continue;
            }

            // Require exactly the evidence provided by the
            // reconciler, never an arbitrary FDB-only candidate.
            if (($candidate['evidence'] ?? null)
                    !== ['lldp_portid', 'arp', 'fdb']
                    || ($candidate['confirmed'] ?? null) !== false) {
                continue;
            }

            // FDB learning on a logical LAG/trunk is not
            // independent evidence for a physical access port.
            if (preg_match(
                '/^(?:Trk\d+|LAG\d+|Po\d+|Port[- ]?channel\d+|'
                . 'Bond\d+|ae\d+|Eth[- ]?Trunk\d+|Bridge\d+)$/i',
                $port
            )) {
                continue;
            }

            $proxy = (string)
                ($hosts[$reporter]['proxyid'] ?? '0');

            if ($proxy === '0'
                    || (string) ($hosts[$target]['proxyid'] ?? '0')
                        !== $proxy) {
                continue;
            }

            // Never replace an administrator-defined uplink.
            $manual = false;
            foreach (($hosts[$target]['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:uplink'
                        && trim((string) ($tag['value'] ?? '')) !== '') {
                    $manual = true;
                    break;
                }
            }

            if ($manual) continue;

            // Do not reuse an existing physical switch port.
            $normalizedPort = strtolower($port);
            if (isset($occupiedPorts[$reporter][$normalizedPort])) {
                continue;
            }

            $shared = ($candidate['fdb_shared_port'] ?? false) === true;

            // Identical observations collapse; different reporters,
            // ports or shared-port evidence remain conflicting claims.
            $fingerprint = $reporter . '|' . $normalizedPort
                . '|' . ($shared ? 'shared' : 'single');

            $byTarget[$target][$fingerprint] = [
                'reporter' => $reporter,
                'target' => $target,
                'port' => $port,
                'shared' => $shared,
            ];
        }

        $added = 0;

        foreach ($byTarget as $target => $claims) {
            // More than one distinct claim: no arbitrary winner.
            if (count($claims) !== 1
                    || isset($alreadyLinked[$target])) {
                continue;
            }

            $claim = reset($claims);
            $reporter = $claim['reporter'];
            $port = $claim['port'];
            $normalizedPort = strtolower($port);

            if (isset($occupiedPorts[$reporter][$normalizedPort])) {
                continue;
            }

            $id = 'lldp_portid_' . $reporter . '_' . $target;

            if (isset($existingIds[$id])) {
                continue;
            }

            $edges[] = [
                'id' => $id,
                'from' => $reporter,
                'to' => $target,
                'iface' => 'lldpRemPortId',
                'src' => ['lldp'],
                'reporters' => [$reporter],
                'match' => 'lldp_portid',
                'port_match' => 'lldp_local_fdb',
                'confirmed' => false,
                'confidence' => $claim['shared'] ? 70 : 78,
                'evidence' => ['lldp_portid', 'arp', 'fdb'],
                'ports' => [$reporter => $port],
                'port_idx' => [],
                // Shared switch traffic must not be attributed
                // to an individual endpoint.
                'port_metrics' => [],
                'fdb_shared_port' => $claim['shared'],
            ];

            $alreadyLinked[$target] = true;
            $occupiedPorts[$reporter][$normalizedPort] = true;
            $existingIds[$id] = true;

            // Same conservative bound as AutoFdbEdges.
            if (++$added >= 500) {
                break;
            }
        }

        return $edges;
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
