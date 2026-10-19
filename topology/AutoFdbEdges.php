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
     */
    public static function append(array $hosts, array $walkItems, array $capItems,
            array $edges, array $manualUplinks, array $metrics, int $now): array {
        $walks = [];
        $arpRows = [];
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
            return $edges;
        }
        $hits = FdbParser::parseHostMap($byHost);
        if (!$hits) {
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
        if (!$lldp['known']) {
            return $edges;
        }
        $ports = $lldp['ports'];
        $known = $lldp['known'];

        // All current LLDP switch-to-switch edges are uplinks as well, even
        // when a neighbor's capability bits were incomplete.
        foreach ($edges as $edge) {
            $src = (string) ($edge['from'] ?? '');
            $dst = (string) ($edge['to'] ?? '');
            if (!isset($byHost[$src], $byHost[$dst])) {
                continue;
            }
            foreach ([$src, $dst] as $hid) {
                $name = (string) ($edge['ports'][$hid] ?? '');
                if ($name === '' && isset($edge['port_idx'][$hid])) {
                    $name = $ifNames[$hid][(string) $edge['port_idx'][$hid]] ?? '';
                }
                if ($name !== '') {
                    $ports[$hid][$name] = true;
                }
            }
        }

        $byMac = [];
        foreach ($hits as $hit) {
            $byMac[$hit['mac']][] = $hit;
        }
        $identity = self::macsByHost($hosts, $arpRows, $now);
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
                continue;  // Keep every existing LLDP and manual connection.
            }
            $candidates = [];
            $ambiguous = false;
            foreach ($macs as $mac => $_) {
                if (count($macOwners[$mac]) !== 1 || empty($byMac[$mac])) {
                    continue;
                }
                // Search within this topology only, and only through the same
                // nonzero proxy. Prevent MAC reuse across managed clients.
                $validHits = [];
                foreach ($byMac[$mac] as $hit) {
                    $sw = (string) $hit['hostid'];
                    if (!self::sameProxy($hosts[$hid], $hosts[$sw] ?? [])) {
                        continue;
                    }
                    if (!isset($known[$sw])) {
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
                    if ($res['status'] === 'ambiguous') {
                        $ambiguous = true;
                    }
                    continue;
                }
                $a = $res['access'][0];
                $candidates[(string) $a['hostid'] . '|' . $a['port']] = $a;
            }
            if ($ambiguous || count($candidates) !== 1) {
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
                continue;
            }
            $i = count($updated) - 1;
            $updated[$i]['id'] = 'fdb_' . $switch . '_' . $hid;
            $updated[$i]['iface'] = 'nt:fdb';
            $updated[$i]['src'] = ['fdb'];
            $updated[$i]['match'] = 'fdb';
            $updated[$i]['confidence'] = 60; // inferred; never "confirmed"
            $updated[$i]['confirmed'] = false;
            $updated[$i]['last_seen'] = (int) $a['ts'];
            $updated[$i]['vlan'] = $a['vlan'];
            $edges = $updated;
            $alreadyLinked[$hid] = true;
            if (++$new >= self::MAX_NEW_EDGES) {
                break;
            }
        }
        return $edges;
    }

    private static function fresh(int $ts, int $now, int $ttl = self::MAX_AGE): bool {
        return $ts > 0 && $ts <= $now + 60 && $now - $ts <= $ttl;
    }

    private static function sameProxy(array $host, array $switch): bool {
        $a = (string) ($host['proxyid'] ?? '0');
        $b = (string) ($switch['proxyid'] ?? '0');
        return $a !== '0' && $b !== '0' && $a === $b;
    }

    /** MAC identity: known nt:lldp MAC, host inventory, optional scoped router ARP. */
    private static function macsByHost(array $hosts, array $arpRows, int $now): array {
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
        $arpByIp = [];
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
                $arpByIp[$proxy][$ip][$mac[0]] = true;
            }
        }
        foreach ($arpByIp as $proxy => $ipRows) {
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
