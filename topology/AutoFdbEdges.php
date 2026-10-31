<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Conservative, opt-in FDB edges. No Zabbix API or SNMP access in this class.
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * Infer links only when a scoped host has a known MAC and exactly one fresh,
 * LLDP-classified access port. Manual/LLDP links take precedence.
 *
 * Inputs are API item rows that the controller already scoped to visible hosts;
 * no global item queries, writes, or cross-proxy matching take place here.
 */
final class AutoFdbEdges {
    private const MAX_AGE = 900;            // 5-minute FDB poll, allow 15 minutes
    private const MAX_LLDP_AGE = 14400;     // LLDP discovery can run every 3 hours
    private const MAX_NEW_EDGES = 500;      // bound payload and PHP memory
    private const KEYS = [
        'nt.fdb.dot1d' => 'dot1d',
        'nt.fdb.dot1q' => 'dot1q',
        'nt.fdb.baseport' => 'baseport',
        'nt.fdb.ifname' => 'ifname',
    ];

    /**
     * @param array $hosts          Visible Zabbix hosts keyed by hostid
     * @param array $walkItems      FDB walk item.get rows plus optional nt.arp
     * @param array $capItems       lldpRemSysCapEnabled item.get rows
     * @param array $edges          Existing LLDP/manual uplink edges
     * @param array $manualUplinks  Parsed nt:uplink hostid map
     * @param array $metrics        MetricExtractor output
     * @param array|null $diagnostics Optional aggregate decision counters;
     *        counts are observations/decisions, not unique rejected hosts.
     */
    public static function append(array $hosts, array $walkItems, array $capItems,
            array $edges, array $manualUplinks, array $metrics, int $now,
            ?array &$diagnostics = null): array {
        $diagnostics = ['added' => 0, 'transit_copies_ignored' => 0, 'rejected' => []];
        $walks = [];
        $arpRows = [];
        $ruckusApRows = [];
        foreach ($walkItems as $it) {
            $hid = (string) ($it['hostid'] ?? '');
            $key = (string) ($it['key_'] ?? '');
            $ts = (int) ($it['lastclock'] ?? 0);
            if (!isset($hosts[$hid]) || !self::fresh($ts, $now)
                    || (string) ($it['status'] ?? '0') !== '0'
                    || (string) ($it['state'] ?? '0') !== '0') {
                continue;
            }
            $value = (string) ($it['lastvalue'] ?? '');
            if ($value === '') {
                continue;
            }
            if ($key === 'nt.arp') {
                $arpRows[] = $it;
            } elseif (in_array($key, ['nt.wifi.zd.ap.1', 'nt.wifi.zd.ap.10'], true)) {
                $ruckusApRows[] = $it;
            } elseif (isset(self::KEYS[$key])) {
                $role = self::KEYS[$key];
                if (!isset($walks[$hid][$role]) || $ts > $walks[$hid][$role]['ts']) {
                    $walks[$hid][$role] = ['ts' => $ts, 'value' => $value];
                }
            }
        }

        $byHost = [];
        $ifNames = [];
        foreach ($walks as $hid => $w) {
            // No numeric-port guesses: both bridge-port translation and ifName
            // must have been collected recently.
            if (empty($w['baseport']) || empty($w['ifname'])
                    || (empty($w['dot1q']) && empty($w['dot1d']))) {
                continue;
            }
            $ifNames[(string) $hid] = FdbParser::ifNames($w['ifname']['value']);
            $byHost[$hid] = [
                'dot1d' => $w['dot1d']['value'] ?? '',
                'dot1q' => $w['dot1q']['value'] ?? '',
                'baseport' => $w['baseport']['value'],
                'ifname' => $w['ifname']['value'],
                'ts' => max($w['dot1d']['ts'] ?? 0, $w['dot1q']['ts'] ?? 0),
            ];
        }
        if (!$byHost) {
            self::reject($diagnostics, 'no_fresh_complete_fdb');
            return $edges;
        }
        $hits = FdbParser::parseHostMap($byHost);
        if (!$hits) {
            self::reject($diagnostics, 'no_parsed_fdb_hit');
            return $edges;
        }

        $caps = [];
        foreach ($capItems as $it) {
            $hid = (string) ($it['hostid'] ?? '');
            if (!isset($byHost[$hid])
                    || !self::fresh((int) ($it['lastclock'] ?? 0), $now, self::MAX_LLDP_AGE)
                    || (string) ($it['status'] ?? '0') !== '0'
                    || (string) ($it['state'] ?? '0') !== '0') {
                continue;
            }
            $caps[] = ['hostid' => $hid, 'key_' => (string) ($it['key_'] ?? ''),
                       'value' => (string) ($it['lastvalue'] ?? '')];
        }
        $lldp = LldpEdgeBuilder::uplinkPortsByCaps($caps, $ifNames);
        // Unlike interactive Finder, automatic edges NEVER use the >=5 MAC
        // count heuristic. Unknown switch uplinks must not become 'access'.
        $ports = $lldp['ports'];
        $known = $lldp['known'];

        // All current LLDP switch-to-switch edges are uplinks as well, even
        // when a neighbor's capability bits were incomplete.
        foreach ($edges as $edge) {
            $src = (string) ($edge['from'] ?? '');
            $dst = (string) ($edge['to'] ?? '');
            $sources = is_array($edge['src'] ?? null) ? $edge['src'] : [];
            if (!isset($byHost[$src], $byHost[$dst]) || $src === $dst
                    || !self::sameProxy($hosts[$src], $hosts[$dst])
                    || in_array($edge['_type'] ?? '', ['hosts', 'manual'], true)
                    || (!in_array('lldp', $sources, true)
                        && !in_array('cdp', $sources, true))) {
                continue;
            }
            foreach ([$src, $dst] as $hid) {
                // Preserve the LLDP label and also resolve the local ifIndex.
                $label = (string) ($edge['ports'][$hid] ?? '');
                if ($label !== '') {
                    $ports[$hid][$label] = true;
                }

                $idx = (string) ($edge['port_idx'][$hid] ?? '');
                if ($idx !== '' && isset($ifNames[$hid][$idx])) {
                    $canonical = $ifNames[$hid][$idx];
                    $ports[$hid][$canonical] = true;
                }
            }
        }

        $byMac = [];
        $learnedOnPort = [];
        foreach ($hits as $hit) {
            // Some switch vendors expose every aggregate as an IF-MIB
            // logical interface (e.g. HPE Trk1). LLDP knows its PHYSICAL
            // member ports, while the FDB records the logical Trk1. Never
            // mistake the unpaired aggregate label for an access port.
            if (self::isAggregatePort((string) ($hit['port'] ?? ''))) {
                self::reject($diagnostics, 'aggregate_port');
                continue;
            }
            $byMac[$hit['mac']][] = $hit;
            // Count distinct learned MACs, including devices not monitored
            // as Zabbix hosts. A single monitored client behind a phone/AP
            // can still be on a shared physical switch access port.
            $portKey = (string) $hit['hostid'] . '|' . (string) $hit['port'];
            $learnedOnPort[$portKey][(string) $hit['mac']] = true;
        }
        $identity = self::macsByHost($hosts, $arpRows, $now, $ruckusApRows);
        foreach ($hosts as $hid => $_host) {
            if (empty($identity[$hid])) self::reject($diagnostics, 'no_mac_identity');
        }
        $macOwners = [];
        foreach ($identity as $hid => $macs) {
            foreach ($macs as $mac => $_) {
                $macOwners[$mac][$hid] = true;
            }
        }

        $alreadyLinked = [];
        foreach ($edges as $edge) {
            $alreadyLinked[(string) ($edge['from'] ?? '')] = true;
            $alreadyLinked[(string) ($edge['to'] ?? '')] = true;
        }
        $new = 0;
        foreach ($identity as $hid => $macs) {
            $hid = (string) $hid;
            if (isset($manualUplinks[$hid]) || isset($alreadyLinked[$hid])) {
                self::reject($diagnostics, 'existing_or_manual_link');
                continue;  // Keep every existing LLDP and manual connection.
            }
            $candidates = [];
            $ambiguous = false;
            foreach ($macs as $mac => $_) {
                if (count($macOwners[$mac]) !== 1 || empty($byMac[$mac])) {
                    self::reject($diagnostics, count($macOwners[$mac]) !== 1
                        ? 'duplicate_mac_identity' : 'no_eligible_fdb_hit');
                    continue;
                }
                // Search within this topology only, and only through the same
                // nonzero proxy. Prevent MAC reuse across managed clients.
                $validHits = [];
                foreach ($byMac[$mac] as $hit) {
                    $sw = (string) $hit['hostid'];
                    if (!self::sameProxy($hosts[$hid], $hosts[$sw] ?? [])) {
                        self::reject($diagnostics, 'proxy_mismatch');
                        continue;
                    }
                    // Exact transit evidence is local to this port. It must
                    // neither require nor confer switch-wide LLDP coverage.
                    if (isset($ports[$sw][$hit['port']])) {
                        $diagnostics['transit_copies_ignored']++;
                        continue;
                    }
                    if (!isset($known[$sw])) {
                        self::reject($diagnostics, 'unclassified_switch');
                        // An unclassified switch may hide an actual access
                        // port; never declare the single known candidate safe.
                        $ambiguous = true;
                        break;
                    }
                    $validHits[] = $hit;
                }
                if ($ambiguous || !$validHits) {
                    continue;
                }
                $res = FdbResolver::locate($mac, $validHits, $ports, $known);
                if ($res['status'] !== 'access' || count($res['access']) !== 1) {
                    self::reject($diagnostics, $res['status']);
                    if ($res['status'] === 'ambiguous') {
                        $ambiguous = true;
                    }
                    continue;
                }
                $a = $res['access'][0];
                $candidates[(string) $a['hostid'] . '|' . $a['port']] = $a;
            }
            if ($ambiguous || count($candidates) !== 1) {
                self::reject($diagnostics, $ambiguous ? 'ambiguous_endpoint'
                    : (count($candidates) > 1 ? 'multiple_access_candidates' : 'no_access_candidate'));
                continue;
            }
            $a = reset($candidates);
            $switch = (string) $a['hostid'];
            if ($hid === $switch || !isset($hosts[$switch])) {
                continue;
            }
            $uplink = [$hid => [
                'host' => (string) ($hosts[$switch]['host'] ?? ''),
                'port' => (string) $a['port'],
            ]];
            $updated = LldpEdgeBuilder::uplinkEdges($hosts, $uplink, $edges,
                $metrics['port_traffic'] ?? [], $metrics['port_speed'] ?? [],
                $metrics['port_errors'] ?? [], $metrics['port_discards'] ?? [],
                $metrics['port_names'] ?? []);
            if (count($updated) !== count($edges) + 1) {
                self::reject($diagnostics, 'edge_builder_declined');
                continue;
            }
            $i = count($updated) - 1;
            $updated[$i]['id'] = 'fdb_' . $switch . '_' . $hid;
            $updated[$i]['iface'] = 'nt:fdb';
            $updated[$i]['src'] = ['fdb'];
            $updated[$i]['match'] = 'fdb';
            $updated[$i]['confidence'] = 60; // inferred; never "confirmed"
            $updated[$i]['confirmed'] = false;
            // A learned destination can be behind a phone, VM host, AP or
            // unmanaged switch. Metrics of its switch port do NOT describe
            // the individual device or direct cable. Keep them unknown.
            $updated[$i]['port_metrics'] = [];
            $updated[$i]['last_seen'] = (int) $a['ts'];
            $updated[$i]['vlan'] = $a['vlan'];
            $edges = $updated;
            $alreadyLinked[$hid] = true;
            $diagnostics['added']++;
            if (++$new >= self::MAX_NEW_EDGES) {
                break;
            }
        }
        // Multiple destinations learned on one physical switch port are
        // common (e.g. IP phone with downstream PC). Flag, never assume
        // direct cabling or silently discard either monitored endpoint.
        $occupancy = [];
        foreach ($edges as $e) {
            if (($e['match'] ?? '') !== 'fdb') continue;
            $sw = (string) ($e['from'] ?? '');
            $port = (string) ($e['ports'][$sw] ?? '');
            if ($port !== '') {
                $key = $sw . '|' . $port;
                $occupancy[$key] = ($occupancy[$key] ?? 0) + 1;
            }
        }
        foreach ($edges as &$e) {
            if (($e['match'] ?? '') !== 'fdb') continue;
            $sw = (string) ($e['from'] ?? '');
            $port = (string) ($e['ports'][$sw] ?? '');
            $key = $sw . '|' . $port;
            $e['fdb_shared_port'] = ($occupancy[$key] ?? 0) > 1
                || count($learnedOnPort[$key] ?? []) > 1;
        }
        unset($e);
        return $edges;
    }

    /** Aggregate reason counters only: no host identities or raw observations. */
    private static function reject(array &$diagnostics, string $reason): void {
        $diagnostics['rejected'][$reason] = ($diagnostics['rejected'][$reason] ?? 0) + 1;
    }

    private static function isAggregatePort(string $port): bool {
        return preg_match('/^(?:Trk\d+|LAG\d+|Po\d+|Port[- ]?channel\d+|'
            . 'Bond\d+|ae\d+|Eth[- ]?Trunk\d+|Bridge\d+)$/i',
            trim($port)) === 1;
    }

    private static function fresh(int $ts, int $now, int $ttl = self::MAX_AGE): bool {
        return $ts > 0 && $ts <= $now + 60 && $now - $ts <= $ttl;
    }

    private static function sameProxy(array $host, array $switch): bool {
        $a = (string) ($host['proxyid'] ?? '0');
        $b = (string) ($switch['proxyid'] ?? '0');
        return $a !== '0' && $b !== '0' && $a === $b;
    }

    /** Shared MAC identity for FDB and Wi-Fi: tags, inventory, scoped router ARP. */
    public static function macsByHost(array $hosts, array $arpRows, int $now,
            array $ruckusApRows = []): array {
        $result = [];
        $ips = [];
        foreach ($hosts as $id => $host) {
            $hid = (string) $id;
            foreach (($host['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'nt:lldp') {
                    foreach (self::parseMacs((string) ($tag['value'] ?? '')) as $mac) {
                        $result[$hid][$mac] = true;
                    }
                }
            }
            foreach (['macaddress_a', 'macaddress_b'] as $field) {
                foreach (self::parseMacs((string) ($host['inventory'][$field] ?? '')) as $mac) {
                    $result[$hid][$mac] = true;
                }
            }
            $proxy = (string) ($host['proxyid'] ?? '0');
            if ($proxy === '0') {
                continue;
            }
            foreach (($host['interfaces'] ?? []) as $iface) {
                $ip = (string) ($iface['ip'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[$proxy][$ip][$hid] = true;
                }
            }
        }
        $macsByIp = [];
        foreach ($arpRows as $row) {
            $router = (string) ($row['hostid'] ?? '');
            $proxy = (string) ($hosts[$router]['proxyid'] ?? '0');
            if ($proxy === '0' || !self::fresh((int) ($row['lastclock'] ?? 0), $now)) {
                continue;
            }
            foreach (FdbParser::lines((string) ($row['lastvalue'] ?? '')) as [$oid, $val]) {
                $octets = FdbParser::lastN($oid, 4);
                if ($octets === null || max($octets) > 255) {
                    continue;
                }
                $ip = implode('.', $octets);
                if (!isset($ips[$proxy][$ip]) || count($ips[$proxy][$ip]) !== 1) {
                    continue;
                }
                $mac = self::parseMacs($val);
                if (count($mac) !== 1) {
                    continue;
                }
                $macsByIp[$proxy][$ip][$mac[0]] = true;
            }
        }

        // Ruckus ZoneDirector exposes AP MAC/IP pairs in nt.wifi.zd.ap.1/.10.
        // Use these as endpoint identities (never as wireless-client links).
        // A duplicate IP with multiple AP MACs stays ambiguous and is ignored.
        $zd = [];
        foreach ($ruckusApRows as $row) {
            $controller = (string) ($row['hostid'] ?? '');
            $proxy = (string) ($hosts[$controller]['proxyid'] ?? '0');
            $key = (string) ($row['key_'] ?? '');
            if ($proxy === '0' || !self::fresh((int) ($row['lastclock'] ?? 0), $now)
                    || (string) ($row['status'] ?? '0') !== '0'
                    || (string) ($row['state'] ?? '0') !== '0') {
                continue;
            }
            foreach (FdbParser::lines((string) ($row['lastvalue'] ?? '')) as [$oid, $val]) {
                // ZoneDirector AP identity table: index ends in six MAC octets;
                // the preceding .6 is the string length and is deliberately ignored.
                $octets = FdbParser::lastN($oid, 6);
                if ($octets === null || max($octets) > 255) continue;
                $mac = FdbResolver::normalizeMac(implode(':', array_map(
                    static fn(int $part): string => str_pad(dechex($part), 2, '0', STR_PAD_LEFT),
                    $octets)));
                if ($mac === null || $mac === '00:00:00:00:00:00'
                        || $mac === 'ff:ff:ff:ff:ff:ff'
                        || (hexdec(substr($mac, 0, 2)) & 1) !== 0) continue;
                if ($key === 'nt.wifi.zd.ap.1') {
                    $reported = self::parseMacs($val);
                    if (count($reported) === 1 && $reported[0] === $mac) {
                        $zd[$proxy][$mac]['confirmed'] = true;
                    }
                } elseif ($key === 'nt.wifi.zd.ap.10'
                        && preg_match('/(?:IpAddress:\s*)?(\d{1,3}(?:\.\d{1,3}){3})/', $val, $m)
                        && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $zd[$proxy][$mac]['ip'] = $m[1];
                }
            }
        }
        foreach ($zd as $proxy => $entries) {
            foreach ($entries as $mac => $entry) {
                $ip = (string) ($entry['ip'] ?? '');
                if ($ip !== '' && !empty($entry['confirmed'])
                        && isset($ips[$proxy][$ip]) && count($ips[$proxy][$ip]) === 1) {
                    $macsByIp[$proxy][$ip][$mac] = true;
                }
            }
        }
        foreach ($macsByIp as $proxy => $ipRows) {
            foreach ($ipRows as $ip => $macs) {
                if (count($macs) !== 1) {
                    continue; // Conflicting ARP reporters: do not guess.
                }
                $hid = (string) array_key_first($ips[$proxy][$ip]);
                $result[$hid][(string) array_key_first($macs)] = true;
            }
        }
        return $result;
    }

    /** Parse MACs only from explicit MAC-shaped tokens, never ordinary LLDP names. */
    private static function parseMacs(string $value): array {
        $out = [];
        if (preg_match_all('/(?<![0-9a-f])(?:[0-9a-f]{2}[:\s-]){5}[0-9a-f]{2}(?![0-9a-f])|(?<![0-9a-f])[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}(?![0-9a-f])|(?<![0-9a-f])[0-9a-f]{12}(?![0-9a-f])/i', $value, $m)) {
            foreach ($m[0] as $raw) {
                $norm = FdbResolver::normalizeMac($raw);
                if ($norm !== null && $norm !== '00:00:00:00:00:00'
                        && $norm !== 'ff:ff:ff:ff:ff:ff'
                        && (hexdec(substr($norm, 0, 2)) & 1) === 0) {
                    $out[$norm] = true;
                }
            }
        }
        return array_keys($out);
    }
}
