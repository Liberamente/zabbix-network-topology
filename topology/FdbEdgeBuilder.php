<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Add optional, inferred MAC-learning edges from ordinary Zabbix SNMP items.
 *
 * No SNMP requests, external collectors, configuration writes or host changes.
 * LLDP/CDP edges take precedence. FDB means "MAC learned on this switch
 * port", NOT proof of a direct physical cable (IP phones/bridges may forward).
 */
final class FdbEdgeBuilder {
    private const MAX_AGE = 900;             // two/three 5m polling windows
    private const MAX_ARP_FDB_SKEW = 600;    // independent polling phases
    private const MAX_VALUE_BYTES = 1048576;
    private const MAX_INFERRED_EDGES = 2000;

    /**
     * @param array $hosts hostid => API::Host()->get(), including interfaces + hostgroups
     * @param array $edges existing LLDP/CDP/uplink edges; never modify them
     * @param array $items only nt.arp / nt.fdb.* items with lastclock + lastvalue
     * @param array $selectedGroupIds exact requested groupids, never generic shared groups
     * @return array Original edges plus bounded inferred access-port observations
     */
    public static function augment(array $hosts, array $edges, array $items,
            array $selectedGroupIds, ?int $now = null): array {
        $now = $now ?? time();
        $selected = [];
        foreach ($selectedGroupIds as $groupId) {
            if (ctype_digit((string) $groupId) && (int) $groupId > 0) {
                $selected[(string) $groupId] = true;
            }
        }
        if ($selected === [] || $hosts === [] || $items === []) {
            return $edges;
        }

        $hostGroups = [];
        foreach ($hosts as $hid => $host) {
            foreach ($host['hostgroups'] ?? [] as $g) {
                $gid = (string) ($g['groupid'] ?? '');
                if (isset($selected[$gid])) {
                    $hostGroups[(string) $hid][$gid] = true;
                }
            }
        }
        $sources = [];
        foreach ($items as $row) {
            $hid = (string) ($row['hostid'] ?? '');
            $key = (string) ($row['key_'] ?? '');
            if (!isset($hosts[$hid], $hostGroups[$hid])
                    || !in_array($key, [
                        'nt.arp', 'nt.fdb.dot1q',
                        'nt.fdb.baseport', 'nt.fdb.ifname'
                    ], true)) {
                continue;
            }
            $value = $row['lastvalue'] ?? null;
            $clock = (int) ($row['lastclock'] ?? 0);
            if (!is_string($value) || strlen($value) > self::MAX_VALUE_BYTES
                    || $clock <= 0 || $clock > $now + 30
                    || $clock < $now - self::MAX_AGE) {
                continue;
            }
            // Duplicate item key on a single host would be ambiguous.
            if (isset($sources[$hid][$key])) {
                unset($sources[$hid][$key]);
                continue;
            }
            $sources[$hid][$key] = ['value' => $value, 'clock' => $clock];
        }

        // ARP (IP -> MAC) is learned on a router, not necessarily on switches.
        // Retain its reporter host for tenant-scope checks.
        $arps = [];
        foreach ($sources as $srcHid => $rows) {
            $row = $rows['nt.arp'] ?? null;
            if ($row === null) continue;
            foreach (explode("\n", $row['value']) as $line) {
                if (!preg_match(
                        '/^\.1\.3\.6\.1\.2\.1\.4\.22\.1\.2\.\d+\.'
                        . '((?:\d{1,3}\.){3}\d{1,3})\s*=\s*Hex-STRING:\s*(.*)$/i',
                        trim($line), $m)) {
                    continue;
                }
                $mac = self::hexMac($m[2]);
                if ($mac !== null && filter_var($m[1], FILTER_VALIDATE_IP,
                        FILTER_FLAG_IPV4) !== false) {
                    $arps[$m[1]][$mac][(string) $srcHid] = $row['clock'];
                }
            }
        }
        if ($arps === []) return $edges;

        // Bridge port -> IF-MIB ifIndex -> ifName, then dot1q FDB MAC -> port.
        // Bridge ports with ifName "Trk1", "Port-channel", etc are TRANSIT
        // uplinks, not directly connected endpoint ports. Never draw one.
        $learned = [];
        foreach ($sources as $swid => $rows) {
            if (!isset($rows['nt.fdb.baseport'], $rows['nt.fdb.ifname'],
                    $rows['nt.fdb.dot1q'])) continue;
            $bridge = [];
            foreach (explode("\n", $rows['nt.fdb.baseport']['value']) as $line) {
                if (preg_match('/^\.1\.3\.6\.1\.2\.1\.17\.1\.4\.1\.2\.(\d+)'
                        . '\s*=\s*INTEGER:\s*(\d+)\s*$/', trim($line), $m)) {
                    $bridge[$m[1]] = $m[2];
                }
            }
            $names = [];
            foreach (explode("\n", $rows['nt.fdb.ifname']['value']) as $line) {
                if (preg_match('/^\.1\.3\.6\.1\.2\.1\.31\.1\.1\.1\.1\.(\d+)'
                        . '\s*=\s*STRING:\s*"?([^"]{1,128})"?\s*$/',
                        trim($line), $m)) {
                    $names[$m[1]] = trim($m[2]);
                }
            }
            foreach (explode("\n", $rows['nt.fdb.dot1q']['value']) as $line) {
                if (!preg_match('/^\.1\.3\.6\.1\.2\.1\.17\.7\.1\.2\.2\.1\.2\.'
                        . '(\d+)\.((?:\d{1,3}\.){5}\d{1,3})'
                        . '\s*=\s*INTEGER:\s*(\d+)\s*$/', trim($line), $m)) {
                    continue;
                }
                $mac = self::oidMac($m[2]);
                if ($mac === null || !isset($bridge[$m[3]])) continue;
                $ifindex = $bridge[$m[3]];
                $port = $names[$ifindex] ?? '';
                if (!self::physicalPort($port)) continue;
                $key = (string) $swid . '/' . $ifindex;
                // VLAN duplicates at one port are fine; different ports are
                // conflicting evidence and will fail the unique-port check.
                $learned[$mac][$key] = [
                    'switch' => (string) $swid,
                    'ifindex' => (string) $ifindex,
                    'port' => $port,
                    'clock' => $rows['nt.fdb.dot1q']['clock'],
                ];
            }
        }

        // A "disconnected" Zabbix host must appear in this group exactly
        // once per IP and once per MAC. Same MAC across multiple IP-hosts may
        // be one router with several VLAN subinterfaces: never draw 3 cables.
        $ipHosts = [];
        foreach ($hosts as $hid => $host) {
            if (!isset($hostGroups[(string) $hid])) continue;
            foreach ($host['interfaces'] ?? [] as $iface) {
                $ip = trim((string) ($iface['ip'] ?? ''));
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipHosts[$ip][(string) $hid] = true;
                }
            }
        }
        $connected = [];
        foreach ($edges as $edge) {
            if (isset($edge['from'])) $connected[(string) $edge['from']] = true;
            if (isset($edge['to'])) $connected[(string) $edge['to']] = true;
        }
        $candidates = [];
        $macHosts = [];
        foreach ($ipHosts as $ip => $owners) {
            if (count($owners) !== 1 || !isset($arps[$ip])) continue;
            $targetId = (string) array_key_first($owners);
            if (isset($connected[$targetId]) || count($arps[$ip]) !== 1) continue;
            $mac = (string) array_key_first($arps[$ip]);
            $ports = $learned[$mac] ?? [];
            if (count($ports) !== 1) continue;
            $p = reset($ports);
            if ($p['switch'] === $targetId
                    || !self::commonGroup($hostGroups,
                        [$targetId, $p['switch']], $selected)) continue;
            $sourceClock = null;
            foreach ($arps[$ip][$mac] as $arpHid => $clock) {
                if (!self::commonGroup($hostGroups,
                        [$targetId, $p['switch'], $arpHid], $selected)) continue;
                if (abs($clock - $p['clock']) > self::MAX_ARP_FDB_SKEW) continue;
                $sourceClock = $clock;
                break;
            }
            if ($sourceClock === null) continue;
            $macHosts[$mac][$targetId] = true;
            $candidates[] = [
                'target' => $targetId, 'mac' => $mac,
                'switch' => $p['switch'],
                'port' => $p['port'], 'ifindex' => $p['ifindex'],
                'clock' => min($sourceClock, $p['clock']),
            ];
        }

        // Multi-homed or multi-IP hosts: at most ONE unambiguous
        // learned attachment per host. Multiple physical placements are
        // not grounds to choose the first reported cable.
        $targetLocations = [];
        foreach ($candidates as $c) {
            $targetLocations[$c['target']][$c['switch'] . '/' . $c['ifindex']] = true;
        }
        $allowed = [];
        $perPort = [];
        $usedTargets = [];
        foreach ($candidates as $c) {
            $tgt = $c['target'];
            if (count($macHosts[$c['mac']]) !== 1
                    || count($targetLocations[$tgt]) !== 1
                    || isset($usedTargets[$tgt])) continue;
            $usedTargets[$tgt] = true;
            $allowed[] = $c;
            $perPort[$c['switch'] . '/' . $c['ifindex']] =
                ($perPort[$c['switch'] . '/' . $c['ifindex']] ?? 0) + 1;
        }
        usort($allowed, static fn($a, $b): int =>
            strcmp($a['switch'] . '/' . $a['target'],
                   $b['switch'] . '/' . $b['target']));
        $created = 0;
        foreach ($allowed as $c) {
            if ($created >= self::MAX_INFERRED_EDGES) break;
            $swid = $c['switch'];
            $targetId = $c['target'];
            $edges[] = [
                'id' => 'fdb_' . $swid . '_' . $targetId,
                'from' => $swid, 'to' => $targetId,
                'iface' => 'nt.fdb.dot1q',
                'src' => ['fdb', 'arp'],
                'confirmed' => false,
                'match' => 'arp_fdb',
                'ports' => [$swid => $c['port']],
                'port_idx' => [$swid => $c['ifindex']],
                'port_metrics' => [],
                'fdb_learned' => true,
                'fdb_shared_port' =>
                    $perPort[$swid . '/' . $c['ifindex']] > 1,
                'last_seen' => $c['clock'],
            ];
            $created++;
        }
        return $edges;
    }

    private static function commonGroup(array $hostGroups, array $hosts,
            array $selected): bool {
        $intersection = $selected;
        foreach ($hosts as $id) {
            $intersection = array_intersect_key($intersection,
                $hostGroups[(string) $id] ?? []);
            if ($intersection === []) return false;
        }
        return true;
    }

    private static function hexMac(string $value): ?string {
        $bytes = preg_split('/\s+/', trim($value));
        if (count($bytes) !== 6) return null;
        foreach ($bytes as $b) {
            if (!preg_match('/^[0-9a-f]{2}$/i', $b)) return null;
        }
        $mac = strtoupper(implode('', $bytes));
        return ($mac === '000000000000' || $mac === 'FFFFFFFFFFFF')
            ? null : $mac;
    }

    private static function oidMac(string $value): ?string {
        $bytes = explode('.', $value);
        if (count($bytes) !== 6) return null;
        $hex = '';
        foreach ($bytes as $b) {
            if (!ctype_digit($b) || (int) $b > 255) return null;
            $hex .= sprintf('%02X', (int) $b);
        }
        return ($hex === '000000000000' || $hex === 'FFFFFFFFFFFF')
            ? null : $hex;
    }

    private static function physicalPort(string $port): bool {
        if ($port === '' || strlen($port) > 64
                || preg_match('/[\x00-\x1F\x7F]/', $port)) return false;
        // Standard physical ifName variants; reject trunk/VLAN/virtual
        // interfaces even if they have a MAC forwarding-table entry.
        if (preg_match('/^(?:tr[k]?|lag|po|port.channel|bond|ae|br|bridge'
                . '|vlan|lo|loopback|tun|wg|ppp)/i', $port)) return false;
        return preg_match('/^(?:[1-9]\d*|(?:Gi|Te|Fa|Eth|Ethernet|ge-|xe-|'
                . 'et-|ether|sfp|qsfp)[\w.\/:-]{0,50})$/i', $port) === 1;
    }
}
