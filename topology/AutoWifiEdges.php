<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Read-only, conservative MikroTik Wi-Fi association edges.
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * A wireless association is NOT a copper FDB connection. This class reads
 * only caller-scoped Zabbix item lastvalues; it never contacts an AP or Zabbix.
 * Host identities are shared with AutoFdbEdges (tags, inventory, ARP).
 */
final class AutoWifiEdges {
    private const WIFI_OID = '1.3.6.1.4.1.14988.1.1.1.2.1.3';
    private const MAX_WIFI_AGE = 180; // 1m polling, tolerate two missed polls
    private const MAX_ARP_AGE = 900;  // 5m polling, tolerate 15 minutes
    private const MAX_NEW_EDGES = 400;
    private const MAX_TOTAL_EDGES = 6000;

    /**
     * @param array $hosts       Visible Zabbix hostid => host API rows
     * @param array $wifiItems   Scoped nt.wifi.clients walk[] item rows
     * @param array $walkItems   Scoped nt.arp rows (also used by Auto FDB)
     * @param array $edges       Existing LLDP + manual edges
     * @param array $manual      Parsed nt:uplink tags, by hostid
     */
    public static function append(array $hosts, array $wifiItems, array $walkItems,
            array $edges, array $manual, int $now): array {
        if (!$hosts || !$wifiItems || count($edges) >= self::MAX_TOTAL_EDGES) {
            return $edges;
        }

        $arpRows = [];
        foreach ($walkItems as $it) {
            $hid = (string) ($it['hostid'] ?? '');
            if (($it['key_'] ?? '') === 'nt.arp' && isset($hosts[$hid])
                    && self::validItem($it, $now, self::MAX_ARP_AGE)) {
                $arpRows[] = $it;
            }
        }
        $identity = AutoFdbEdges::macsByHost($hosts, $arpRows, $now);
        $owners = [];
        foreach ($identity as $hid => $macs) {
            foreach ($macs as $mac => $_) {
                $owners[$mac][(string) $hid] = true;
            }
        }
        if (!$owners) {
            return $edges;
        }

        // mac => ap => radio-index set; no guessing on roaming or duplicates.
        $associated = [];
        foreach ($wifiItems as $it) {
            $ap = (string) ($it['hostid'] ?? '');
            if (!isset($hosts[$ap]) || ($it['key_'] ?? '') !== 'nt.wifi.clients'
                    || !self::validItem($it, $now, self::MAX_WIFI_AGE)) {
                continue;
            }
            $proxy = (string) ($hosts[$ap]['proxyid'] ?? '0');
            if ($proxy === '0') {
                continue;
            }
            foreach (FdbParser::lines((string) $it['lastvalue']) as [$oid, $rssi]) {
                // Exact MikroTik registration RSSI OID: 6 MAC octets + radio.
                if (!preg_match('/^\\.?' . preg_quote(self::WIFI_OID, '/')
                        . '\\.((?:\\d+\\.){5}\\d+)\\.(\\d+)$/', $oid, $m)) {
                    continue;
                }
                $octets = explode('.', $m[1]);
                if (count($octets) !== 6 || max(array_map('intval', $octets)) > 255
                        || !preg_match('/^-?\\d+$/', (string) $rssi)) {
                    continue;
                }
                $mac = FdbResolver::normalizeMac(implode(':', array_map(
                    static fn(string $part): string => str_pad(dechex((int) $part), 2, '0', STR_PAD_LEFT),
                    $octets)));
                if ($mac === null || $mac === '00:00:00:00:00:00'
                        || $mac === 'ff:ff:ff:ff:ff:ff'
                        || (hexdec(substr($mac, 0, 2)) & 1) !== 0) {
                    continue;
                }
                $radio = (string) (int) $m[2];
                $associated[$mac][$ap][$radio] = [
                    'ts' => (int) $it['lastclock'], 'rssi' => (int) $rssi,
                    'proxy' => $proxy,
                ];
            }
        }

        $linked = [];
        foreach ($edges as $edge) {
            $linked[(string) ($edge['from'] ?? '')] = true;
            $linked[(string) ($edge['to'] ?? '')] = true;
        }
        $added = 0;
        foreach ($associated as $mac => $aps) {
            if (count($aps) !== 1 || count($owners[$mac] ?? []) !== 1) {
                continue; // roaming between APs or duplicate MAC ownership
            }
            $client = (string) array_key_first($owners[$mac]);
            $ap = (string) array_key_first($aps);
            if ($ap === $client || isset($linked[$client]) || isset($manual[$client])
                    || count($aps[$ap]) !== 1 || !isset($hosts[$ap], $hosts[$client])) {
                continue;
            }
            $radio = (string) array_key_first($aps[$ap]);
            $observation = $aps[$ap][$radio];
            $proxy = $observation['proxy'];
            if ($proxy === '0' || (string) ($hosts[$client]['proxyid'] ?? '0') !== $proxy
                    || (string) ($hosts[$ap]['proxyid'] ?? '0') !== $proxy) {
                continue; // never connect across proxies
            }

            $edges[] = [
                'id' => 'wifi_' . $ap . '_' . $client,
                'from' => $ap,
                'to' => $client,
                'iface' => 'nt:wifi',
                'src' => ['wifi'],
                'reporters' => [$ap],
                'confirmed' => false, // single-sided AP measurement
                'match' => 'wifi',
                'port_match' => 'association',
                'ports' => [$ap => 'Wi-Fi radio ifIndex ' . $radio],
                'port_idx' => [$ap => $radio],
                'port_metrics' => [],
                'confidence' => 75,
                'last_seen' => $observation['ts'],
                'signal_dbm' => $observation['rssi'],
            ];
            $linked[$client] = true;
            if (++$added >= self::MAX_NEW_EDGES || count($edges) >= self::MAX_TOTAL_EDGES) {
                break;
            }
        }
        return $edges;
    }

    /**
     * Attach controller-sourced associations to visible hosts, using the same
     * conservative link policy as MikroTik. The controller is the reporter,
     * not an invented AP node. All item rows must be caller-scoped.
     */
    public static function appendController(array $hosts, array $controllerItems,
            array $walkItems, array $edges, array $manual, int $now): array {
        if (!$hosts || count($edges) >= self::MAX_TOTAL_EDGES) return $edges;
        $byController = [];
        foreach ($controllerItems as $item) {
            $hid = (string) ($item['hostid'] ?? '');
            $key = (string) ($item['key_'] ?? '');
            if (!isset($hosts[$hid]) || !preg_match('/^nt\\.wifi\\.zd\\.(ap|client)\\.(1|2|3|8|10)$/', $key, $m)
                || !self::validItem($item, $now, self::MAX_WIFI_AGE)) continue;
            $proxy = (string) ($hosts[$hid]['proxyid'] ?? '0');
            if ($proxy === '0') continue;
            $byController[$hid][$m[1]][(int)$m[2]] = $item;
        }
        $arpRows = [];
        foreach ($walkItems as $it) {
            if (($it['key_'] ?? '') === 'nt.arp'
                && isset($hosts[(string)($it['hostid'] ?? '')])
                && self::validItem($it, $now, self::MAX_ARP_AGE)) $arpRows[] = $it;
        }
        $owners = [];
        foreach (AutoFdbEdges::macsByHost($hosts, $arpRows, $now) as $hid => $macs) {
            foreach ($macs as $mac => $_) $owners[$mac][(string)$hid] = true;
        }
        $ipHosts = [];
        foreach ($hosts as $hid => $host) {
            foreach (($host['interfaces'] ?? []) as $iface) {
                $ip = (string) ($iface['ip'] ?? '');
                if ($ip !== '') $ipHosts[$ip][(string)$hid] = true;
            }
        }
        $linked = [];
        foreach ($edges as $edge) {
            $linked[(string)($edge['from'] ?? '')] = true;
            $linked[(string)($edge['to'] ?? '')] = true;
        }
        $candidates = [];
        foreach ($byController as $controller => $tables) {
            if (count($tables['ap'] ?? []) !== 3 || count($tables['client'] ?? []) !== 2) continue;
            $items = array_merge(array_values($tables['ap']), array_values($tables['client']));
            $times = array_map(static fn(array $it): int => (int)$it['lastclock'], $items);
            if (max($times) - min($times) > 120) continue;
            $apWalk = array_map(static fn(array $it): string => (string)$it['lastvalue'], $tables['ap']);
            $clWalk = array_map(static fn(array $it): string => (string)$it['lastvalue'], $tables['client']);
            $controllerProxy = (string) $hosts[$controller]['proxyid'];
            foreach (ZoneDirectorWifiSource::associations($apWalk, $clWalk) as $association) {
                $apSet = $ipHosts[$association['ap_ip']] ?? [];
                $clientSet = $ipHosts[$association['client_ip']] ?? [];
                if (count($apSet) !== 1 || count($clientSet) !== 1) continue;
                $ap = (string)array_key_first($apSet);
                $client = (string)array_key_first($clientSet);
                if ($ap === $client || isset($manual[$client]) || isset($linked[$client])
                    || (string)($hosts[$ap]['proxyid'] ?? '0') !== $controllerProxy
                    || (string)($hosts[$client]['proxyid'] ?? '0') !== $controllerProxy
                    || count($owners[$association['client_mac']] ?? []) !== 1
                    || !isset($owners[$association['client_mac']][$client])) continue;
                $candidates[$client][$ap][$controller] = min($times);
            }
        }
        $added = 0;
        foreach ($candidates as $client => $aps) {
            if (count($aps) !== 1 || isset($linked[$client])) continue;
            $ap = (string)array_key_first($aps);
            if (count($aps[$ap]) !== 1) continue;
            $controller = (string)array_key_first($aps[$ap]);
            $edges[] = [
                'id' => 'wifi_'.$ap.'_'.$client,
                'from' => $ap, 'to' => (string)$client,
                'iface' => 'nt:wifi', 'src' => ['wifi'],
                'reporters' => [$controller], 'confirmed' => false,
                'match' => 'wifi', 'port_match' => 'association',
                'ports' => [$ap => 'Wi-Fi association'],
                'port_idx' => [], 'port_metrics' => [],
                'confidence' => 75, 'last_seen' => $aps[$ap][$controller],
                'source_detail' => 'ruckus-zonedirector',
            ];
            $linked[$client] = true;
            if (++$added >= self::MAX_NEW_EDGES || count($edges) >= self::MAX_TOTAL_EDGES) break;
        }
        return $edges;
    }

    private static function validItem(array $it, int $now, int $ttl): bool {
        $ts = (int) ($it['lastclock'] ?? 0);
        return $ts > 0 && $ts <= $now + 60 && ($now - $ts) <= $ttl
            && (string) ($it['status'] ?? '0') === '0'
            && (string) ($it['state'] ?? '0') === '0'
            && (string) ($it['lastvalue'] ?? '') !== '';
    }
}
