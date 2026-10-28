<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Offline, read-only candidate associations behind an Ethernet pass-through.
 *
 * An LLDP neighbor advertising Bridge+Telephone may be a VoIP telephone with
 * a downstream Ethernet port. MAC learning alone cannot prove that a cable
 * runs directly between the telephone and a downstream monitored device.
 *
 * Inputs MUST already be scoped by the caller to visible hosts in one group.
 * This class makes no Zabbix API calls and performs no writes.
 */
final class PassThroughEvidence
{
    private const FDB_TTL = 900;
    private const NEIGHBOR_TTL = 14400;
    private const MAX_PROPOSALS = 250;

    /**
     * Returns proposed, UNCONFIRMED associations; does NOT modify $edges.
     *
     * @param array $hosts          Scoped host.get rows keyed by hostid.
     * @param array $walkItems      Scoped nt.fdb.* and nt.arp item rows.
     * @param array $capItems       Scoped lldpRemSysCapEnabled item rows.
     * @param array $cdpItems       Scoped cdpCacheDeviceId item rows.
     * @param array $edges          Already built LLDP/manual/Wi-Fi/FDB edges.
     * @param array $manualUplinks  Existing nt:uplink overrides.
     */
    public static function propose(
        array $hosts,
        array $walkItems,
        array $capItems,
        array $cdpItems,
        array $edges,
        array $manualUplinks,
        int $now,
        ?array &$diagnostics = null
    ): array {
        // Optional aggregate counters: NEVER include host IDs, MACs or ports.
        $diagnostics = ['identified_phone_ports' => 0,
                        'accepted' => 0, 'excluded' => [],
                        'verified_uplink_copies_ignored' => 0];
        if (!$hosts || !$walkItems || !$capItems || !$cdpItems) {
            return [];
        }

        $walks = [];
        $arpRows = [];
        $roles = [
            'nt.fdb.dot1d' => 'dot1d',
            'nt.fdb.dot1q' => 'dot1q',
            'nt.fdb.baseport' => 'baseport',
            'nt.fdb.ifname' => 'ifname',
        ];
        foreach ($walkItems as $item) {
            $hid = (string) ($item['hostid'] ?? '');
            $key = (string) ($item['key_'] ?? '');
            if (!isset($hosts[$hid]) || !self::valid($item, $now, self::FDB_TTL)
                    || (string) ($item['lastvalue'] ?? '') === '') {
                continue;
            }
            if ($key === 'nt.arp') {
                $arpRows[] = $item;
            } elseif (isset($roles[$key])) {
                $role = $roles[$key];
                if (!isset($walks[$hid][$role])
                        || (int) $item['lastclock'] > $walks[$hid][$role]['ts']) {
                    $walks[$hid][$role] = [
                        'value' => (string) $item['lastvalue'],
                        'ts' => (int) $item['lastclock'],
                    ];
                }
            }
        }
        if (!$arpRows) {
            return [];
        }

        $bySwitch = [];
        $ifNames = [];
        foreach ($walks as $hid => $walk) {
            if (!isset($walk['baseport'], $walk['ifname'])
                    || (!isset($walk['dot1d']) && !isset($walk['dot1q']))) {
                continue;
            }
            $hid = (string) $hid;
            $ifNames[$hid] = FdbParser::ifNames($walk['ifname']['value']);
            $bySwitch[$hid] = [
                'dot1d' => $walk['dot1d']['value'] ?? '',
                'dot1q' => $walk['dot1q']['value'] ?? '',
                'baseport' => $walk['baseport']['value'],
                'ifname' => $walk['ifname']['value'],
                'ts' => max($walk['dot1d']['ts'] ?? 0, $walk['dot1q']['ts'] ?? 0),
            ];
        }
        if (!$bySwitch) {
            return [];
        }

        $locations = [];
        $portMacs = [];
        foreach (FdbParser::parseHostMap($bySwitch) as $hit) {
            $switch = (string) $hit['hostid'];
            $port = (string) $hit['port'];
            $mac = strtolower((string) $hit['mac']);
            if (self::aggregate($port)) {
                continue;
            }
            $place = self::place($switch, $port);
            $locations[$mac][$place] = $hit; // collapse repeated VLAN entries
            $portMacs[$place][$mac] = true;
        }

        // Only ARP-derived identities qualify for this first pilot. Never
        // silently describe inventory/tag identities as an ARP observation.
        $arpOnlyHosts = [];
        foreach ($hosts as $hid => $host) {
            $host['tags'] = [];
            $host['inventory'] = [];
            $arpOnlyHosts[$hid] = $host;
        }
        $arpIdentity = AutoFdbEdges::macsByHost($arpOnlyHosts, $arpRows, $now);
        $macOwners = [];
        foreach ($arpIdentity as $hid => $macs) {
            foreach ($macs as $mac => $_) {
                $macOwners[strtolower((string) $mac)][(string) $hid] = true;
            }
        }

        $capByPort = [];
        foreach ($capItems as $item) {
            $switch = (string) ($item['hostid'] ?? '');
            if (!isset($bySwitch[$switch]) || !self::valid($item, $now, self::NEIGHBOR_TTL)) {
                continue;
            }
            if (!preg_match('/^lldpRemSysCapEnabled\[\d+\.(\d+)\.\d+\]$/D',
                    (string) ($item['key_'] ?? ''), $m)) {
                continue;
            }
            $port = (string) ($ifNames[$switch][$m[1]] ?? $m[1]);
            $place = self::place($switch, $port);
            $class = self::phoneBridge((string) ($item['lastvalue'] ?? ''))
                ? 'phone' : 'other';
            $capByPort[$place][$class] = ($capByPort[$place][$class] ?? 0) + 1;
            $capByPort[$place]['ts'] = max(
                (int) ($capByPort[$place]['ts'] ?? 0),
                (int) $item['lastclock']
            );
        }

        // An IP is an identity candidate only when it occurs exactly once
        // within the selected visible hosts AND the same nonzero proxy.
        $ipOwners = [];
        foreach ($hosts as $hid => $host) {
            $proxy = (string) ($host['proxyid'] ?? '0');
            if ($proxy === '0') {
                continue;
            }
            foreach (($host['interfaces'] ?? []) as $interface) {
                $ip = (string) ($interface['ip'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipOwners[$proxy][$ip][(string) $hid] = true;
                }
            }
        }

        $phoneByPort = [];
        foreach ($cdpItems as $item) {
            $switch = (string) ($item['hostid'] ?? '');
            if (!isset($bySwitch[$switch])
                    || !self::valid($item, $now, self::NEIGHBOR_TTL)
                    || !preg_match('/^cdpCacheDeviceId\[(\d+)\.\d+\]$/D',
                        (string) ($item['key_'] ?? ''), $m)) {
                continue;
            }
            $port = (string) ($ifNames[$switch][$m[1]] ?? $m[1]);
            $place = self::place($switch, $port);
            if (($capByPort[$place]['phone'] ?? 0) < 1
                    || ($capByPort[$place]['other'] ?? 0) !== 0) {
                continue;
            }
            $ip = self::cdpIPv4((string) ($item['lastvalue'] ?? ''));
            $proxy = (string) ($hosts[$switch]['proxyid'] ?? '0');
            if ($ip === null || $proxy === '0'
                    || count($ipOwners[$proxy][$ip] ?? []) !== 1) {
                continue;
            }
            $phone = (string) array_key_first($ipOwners[$proxy][$ip]);
            if ($phone === $switch) {
                continue;
            }
            $phoneByPort[$place][$phone] = [
                'switch' => $switch,
                'port' => $port,
                'ts' => (int) $item['lastclock'],
            ];
        }

        $linked = [];
        $upstream = [];
        $verifiedSwitchUplinks = [];
        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from !== '') $linked[$from] = true;
            if ($to !== '') $linked[$to] = true;
            if ($from === '' || $to === ''
                    || in_array((string) ($edge['_type'] ?? ''),
                        ['hosts', 'manual'], true)) {
                continue;
            }
            // A manual/FDB/Wi-Fi edge cannot independently corroborate
            // the identity of the CDP/LLDP-observed phone. The upper leg
            // must already have neighbor-protocol evidence on this port.
            $sources = $edge['src'] ?? [];
            if (!is_array($sources)) {
                $sources = [];
            }
            if (($edge['match'] ?? '') !== 'lldp_portid'
                    && !in_array('lldp', $sources, true)
                    && !in_array('cdp', $sources, true)) {
                continue;
            }

            // A FDB entry seen on several switches is normal in a cascade:
            // an intermediate switch learns the destination on its uplink.
            // Discount ONLY the uplink ports of a *reciprocally confirmed*
            // LLDP/CDP switch-to-switch edge, with concrete local IF-MIB
            // labels at BOTH ends and the SAME nonzero proxy. A mere
            // two-ended port label is not reciprocal confirmation.
            if (($edge['confirmed'] ?? false) === true
                    && isset($bySwitch[$from], $bySwitch[$to])) {
                $proxyFrom = (string) ($hosts[$from]['proxyid'] ?? '0');
                $proxyTo = (string) ($hosts[$to]['proxyid'] ?? '0');
                $portFrom = self::verifiedSwitchPort($from, $edge, $ifNames);
                $portTo = self::verifiedSwitchPort($to, $edge, $ifNames);
                if ($proxyFrom !== '0' && $proxyFrom === $proxyTo
                        && $portFrom !== null && $portTo !== null) {
                    $verifiedSwitchUplinks[self::place($from, $portFrom)] = true;
                    $verifiedSwitchUplinks[self::place($to, $portTo)] = true;
                }
            }
            foreach ([$from => $to, $to => $from] as $switch => $peer) {
                $switch = (string) $switch;
                if (!isset($bySwitch[$switch])) {
                    continue;
                }
                $port = (string) ($edge['ports'][$switch] ?? '');
                $ifIndex = (string) ($edge['port_idx'][$switch] ?? '');
                if ($ifIndex !== '' && isset($ifNames[$switch][$ifIndex])) {
                    $port = $ifNames[$switch][$ifIndex];
                }
                if ($port !== '') {
                    $upstream[self::place($switch, $port)][(string) $peer] = true;
                }
            }
        }

        $diagnostics['identified_phone_ports'] = count($phoneByPort);
        $proposals = [];
        foreach ($phoneByPort as $place => $phones) {
            if (count($phones) !== 1) {
                self::reject($diagnostics, 'phone_identity_ambiguous');
                continue;
            }
            if (!isset($portMacs[$place]) || count($portMacs[$place]) !== 2) {
                self::reject($diagnostics, 'fdb_mac_count_not_two');
                continue;
            }
            $phone = (string) array_key_first($phones);
            $observation = $phones[$phone];
            $switch = $observation['switch'];
            $proxy = (string) ($hosts[$switch]['proxyid'] ?? '0');
            if (!isset($upstream[$place][$phone])) {
                self::reject($diagnostics, 'upstream_lldp_cdp_edge_missing');
                continue; // No invented switch-to-phone cable.
            }
            if ((string) ($hosts[$phone]['proxyid'] ?? '0') !== $proxy) {
                self::reject($diagnostics, 'phone_proxy_mismatch');
                continue;
            }
            $phoneMacs = array_keys($arpIdentity[$phone] ?? []);
            if (count($phoneMacs) !== 1) {
                self::reject($diagnostics, 'phone_arp_mac_not_unique');
                continue;
            }
            $phoneMac = strtolower((string) $phoneMacs[0]);
            if (count($macOwners[$phoneMac] ?? []) !== 1
                    || !self::uniqueAccessWithUplinks(
                        $locations[$phoneMac] ?? [], $place, $verifiedSwitchUplinks)) {
                self::reject($diagnostics, 'phone_fdb_location_not_unique');
                continue;
            }
            $otherMacs = array_values(array_diff(array_keys($portMacs[$place]), [$phoneMac]));
            if (count($otherMacs) !== 1) {
                self::reject($diagnostics, 'downstream_mac_not_unique');
                continue;
            }
            $downstreamMac = $otherMacs[0];
            if (count($macOwners[$downstreamMac] ?? []) !== 1
                    || !self::uniqueAccessWithUplinks(
                        $locations[$downstreamMac] ?? [], $place, $verifiedSwitchUplinks)) {
                self::reject($diagnostics, 'downstream_arp_fdb_not_unique');
                continue;
            }
            $endpoint = (string) array_key_first($macOwners[$downstreamMac]);
            if ($endpoint === $phone || $endpoint === $switch) {
                self::reject($diagnostics, 'invalid_self_association');
                continue;
            }
            if (isset($manualUplinks[$endpoint])) {
                self::reject($diagnostics, 'manual_uplink_precedence');
                continue;
            }
            if (isset($linked[$endpoint])) {
                self::reject($diagnostics, 'downstream_already_linked');
                continue;
            }
            if ((string) ($hosts[$endpoint]['proxyid'] ?? '0') !== $proxy) {
                self::reject($diagnostics, 'downstream_proxy_mismatch');
                continue;
            }

            $diagnostics['verified_uplink_copies_ignored'] +=
                count($locations[$phoneMac]) - 1
                + count($locations[$downstreamMac]) - 1;

            $proposals[] = [
                'id' => 'pass_through_' . $phone . '_' . $endpoint,
                'from' => $phone,
                'to' => $endpoint,
                'iface' => 'nt:pass_through',
                'src' => ['cdp', 'lldp', 'arp', 'fdb'],
                'match' => 'pass_through_inferred',
                'port_match' => 'same_switch_port',
                'confirmed' => false,
                'confidence' => 65,
                'ports' => [], // Phone downstream port and endpoint port UNKNOWN.
                'port_idx' => [],
                'port_metrics' => [], // Shared switch counters are not per-link.
                'fdb_shared_port' => true,
                'via_switch' => $switch,
                'via_switch_port' => $observation['port'],
                'evidence' => [
                    'cdp_01_ipv4', 'lldp_bridge_telephone',
                    'arp_ip_mac', 'fdb_two_macs_same_port',
                    'existing_switch_phone_edge',
                ],
                'last_seen' => min(
                    (int) $observation['ts'],
                    (int) $locations[$phoneMac][$place]['ts'],
                    (int) $locations[$downstreamMac][$place]['ts'],
                    (int) $capByPort[$place]['ts']
                ),
            ];
            if (count($proposals) >= self::MAX_PROPOSALS) {
                $diagnostics['proposal_limit_reached'] = true;
                break;
            }
        }
        $diagnostics['accepted'] = count($proposals);
        return $proposals;
    }

    /**
     * A MAC must occur on the exact candidate access port; its other
     * observations may be disregarded ONLY if all are verified switch-to-
     * switch uplinks, and none is another port of the same switch.
     *
     * No unverified/hypothetical uplink, missing port label or second local
     * FDB location can justify a downstream phone association.
     */
    private static function uniqueAccessWithUplinks(
        array $locations, string $accessPlace, array $verifiedUplinks
    ): bool {
        if (!isset($locations[$accessPlace])
                || isset($verifiedUplinks[$accessPlace])) {
            return false;
        }
        $sourceSwitch = (string) ($locations[$accessPlace]['hostid'] ?? '');
        foreach ($locations as $place => $hit) {
            if ($place === $accessPlace) {
                continue;
            }
            if ((string) ($hit['hostid'] ?? '') === $sourceSwitch
                    || !isset($verifiedUplinks[$place])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Never infer a bridge-port name from a numeric LLDP hint. A local
     * ifIndex/ifName match or a unique exact IF-MIB label is required.
     */
    private static function verifiedSwitchPort(
        string $hid, array $edge, array $ifNames
    ): ?string {
        $names = $ifNames[$hid] ?? [];
        $index = (string) ($edge['port_idx'][$hid] ?? '');
        if ($index !== '') {
            return isset($names[$index]) ? (string) $names[$index] : null;
        }
        $label = trim((string) ($edge['ports'][$hid] ?? ''));
        if ($label === '') {
            return null;
        }
        $matches = [];
        foreach ($names as $name) {
            if (strcasecmp(trim((string) $name), $label) === 0) {
                $matches[] = (string) $name;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function reject(array &$diagnostics, string $reason): void
    {
        $diagnostics['excluded'][$reason] =
            ($diagnostics['excluded'][$reason] ?? 0) + 1;
    }

    private static function place(string $hid, string $port): string
    {
        return $hid . '|' . strtolower(trim($port));
    }

    private static function valid(array $item, int $now, int $ttl): bool
    {
        $ts = (int) ($item['lastclock'] ?? 0);
        return $ts > 0 && $ts <= $now + 60 && ($now - $ts) <= $ttl
            && (string) ($item['status'] ?? '0') === '0'
            && (string) ($item['state'] ?? '0') === '0';
    }

    private static function aggregate(string $port): bool
    {
        return preg_match('/^(?:Trk\d+|LAG\d+|Po\d+|Port[- ]?channel\d+|'
            . 'Bond\d+|ae\d+|Eth[- ]?Trunk\d+|Bridge\d+)$/i', trim($port)) === 1;
    }

    private static function cdpIPv4(string $raw): ?string
    {
        // Observed device-specific encoding, NOT a universal CDP Device ID.
        if (!preg_match('/^01(?:\s+[0-9a-fA-F]{2}){4}$/D', trim($raw))) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw));
        return implode('.', array_map('hexdec', array_slice($parts, 1)));
    }

    private static function phoneBridge(string $raw): bool
    {
        $length = strlen($raw);
        if ($length === 1 || ($length === 2
                && (ord($raw[0]) < 0x21 || ord($raw[0]) > 0x7e
                    || ord($raw[1]) < 0x21 || ord($raw[1]) > 0x7e))) {
            $byte = ord($raw[0]);
            return ($byte & 0x24) === 0x24;
        }
        $text = trim($raw);
        if (preg_match('/^(?:[0-9a-fA-F]{2})(?:\s+[0-9a-fA-F]{2})?$/D', $text)) {
            $byte = hexdec(substr($text, 0, 2));
            return ($byte & 0x24) === 0x24;
        }
        return stripos($text, 'bridge') !== false
            && (stripos($text, 'telephone') !== false
                || stripos($text, 'phone') !== false);
    }
}
