<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only ZoneDirector source adapter. It only transforms SNMP walks already
 * collected within the caller's scope. It never looks up hosts or creates edges.
 *
 * Caller must enforce item freshness, group/proxy visibility and permission
 * boundaries before using the normalized associations.
 */
final class ZoneDirectorWifiSource {
    private const AP_ROOT = '1.3.6.1.4.1.25053.1.2.2.1.1.2.1.1';
    private const CLIENT_ROOT = '1.3.6.1.4.1.25053.1.2.2.1.1.3.1.1';

    /**
     * @param array<int,string> $apWalks     SNMP output, keyed by column number
     * @param array<int,string> $clientWalks SNMP output, keyed by column number
     * @return array<int,array{client_mac:string,client_ip:string,ap_mac:string,ap_ip:string,source:string}>
     */
    public static function associations(array $apWalks, array $clientWalks): array {
        $mac = static function (string $value): ?string {
            if (!preg_match('/^Hex-STRING:\s*((?:[0-9A-Fa-f]{2}(?:\s+|$)){6})$/', trim($value), $m)) {
                return null;
            }
            $parts = preg_split('/\s+/', trim($m[1]));
            if (count($parts) !== 6) return null;
            $result = strtolower(implode(':', $parts));
            if ($result === '00:00:00:00:00:00' || $result === 'ff:ff:ff:ff:ff:ff'
                || (hexdec(substr($result, 0, 2)) & 1) !== 0) return null;
            return $result;
        };
        $columns = static function (array $walks, string $root, array $wanted): array {
            $result = [];
            foreach ($wanted as $column) {
                foreach (explode("\n", $walks[$column] ?? '') as $line) {
                    if (!preg_match('/^\.?'.preg_quote($root, '/').'\.'.(int)$column
                        .'\.6\.((?:\d+\.){5}\d+)\s*=\s*(.+)$/', trim($line), $m)) continue;
                    $octets = array_map('intval', explode('.', $m[1]));
                    if (count($octets) !== 6 || max($octets) > 255 || min($octets) < 0) continue;
                    $index = strtolower(implode(':', array_map(
                        static fn(int $n): string => sprintf('%02x', $n), $octets)));
                    $result[$index][$column] = trim($m[2]);
                }
            }
            return $result;
        };
        $apRows = $columns($apWalks, self::AP_ROOT, [1, 3, 10]);
        $clientRows = $columns($clientWalks, self::CLIENT_ROOT, [2, 8]);
        $byMac = [];
        $byIp = [];
        foreach ($apRows as $index => $row) {
            $apMac = $mac($row[1] ?? '');
            if ($apMac === null || $apMac !== $index || ($row[3] ?? '') !== 'INTEGER: 1'
                || !preg_match('/^IpAddress:\s*(\S+)$/', $row[10] ?? '', $m)
                || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
            $byMac[$apMac] = $m[1];
            $byIp[$m[1]][$apMac] = true;
        }
        $out = [];
        foreach ($clientRows as $clientMac => $row) {
            if ($mac('Hex-STRING: '.str_replace(':', ' ', $clientMac)) === null) continue;
            $apMac = $mac($row[2] ?? '');
            if ($apMac === null || !isset($byMac[$apMac])
                || count($byIp[$byMac[$apMac]]) !== 1
                || !preg_match('/^IpAddress:\s*(\S+)$/', $row[8] ?? '', $m)
                || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
            $out[] = [
                'client_mac' => $clientMac, 'client_ip' => $m[1],
                'ap_mac' => $apMac, 'ap_ip' => $byMac[$apMac],
                'source' => 'ruckus-zonedirector',
            ];
        }
        return $out;
    }
}
