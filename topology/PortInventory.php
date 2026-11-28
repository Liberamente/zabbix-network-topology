<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-only inventory of explicitly indexed IF-MIB items.
 * An Ethernet ifType is not proof of a physical connector or its position.
 * Callers must supply visibility-scoped items and host ID.
 */
final class PortInventory {
    public static function build(array $items, string $hostid, int $now, int $maxAge = 900): array {
        $ports = [];
        foreach ($items as $item) {
            if ((string) ($item['hostid'] ?? '') !== $hostid
                    || (string) ($item['status'] ?? '') !== '0'
                    || (string) ($item['state'] ?? '') !== '0'
                    || !((string) ($item['type'] ?? '') === '20'
                        || ((string) ($item['type'] ?? '') === '18'
                            && ($item['snmp_master_verified'] ?? false) === true))) continue;
            $clock = (int) ($item['lastclock'] ?? 0);
            if ($clock <= 0 || $clock > $now + 60 || $now - $clock > $maxAge) continue;
            $key = (string) ($item['key_'] ?? '');
            $value = $item['lastvalue'] ?? null;
            if ($value === null || $value === '') continue;
            $field = null;
            $idx = '';
            if (preg_match('/^net\.if\.(in|out|speed|status|type|adminstatus)(?:\.(errors|discards))?\[(?:(if[A-Za-z]+)\.)?([1-9]\d*)\]$/D', $key, $m)) {
                $idx = $m[4];
                $kind = $m[1];
                $suffix = $m[2];
                $oid = $m[3];
                $allowed = [
                    'in' => ['','ifInOctets','ifHCInOctets'],
                    'out' => ['','ifOutOctets','ifHCOutOctets'],
                    'speed' => ['','ifSpeed','ifHighSpeed'],
                    'status' => ['','ifOperStatus','ifAdminStatus'],
                    'adminstatus' => ['','ifAdminStatus'],
                    'type' => ['','ifType']
                ];
                if ($suffix !== '') {
                    if (!in_array($kind, ['in','out'], true)) continue;
                    $expected = 'if' . ucfirst($kind) . ucfirst($suffix);
                    if ($oid !== '' && $oid !== $expected) continue;
                    $field = $kind . '_' . $suffix . '_ps';
                } else {
                    if (!in_array($oid, $allowed[$kind], true)) continue;
                    $field = [
                        'in'=>'in_bps','out'=>'out_bps','speed'=>'speed_bps',
                        'status'=>$oid === 'ifAdminStatus' ? 'admin_status' : 'oper_status',
                        'adminstatus'=>'admin_status','type'=>'if_type'
                    ][$kind];
                }
            } elseif (preg_match('/^if(Name|Descr|Alias)[.\[]([1-9]\d*)\]?$/D', $key, $m)) {
                $field = ['Name'=>'if_name','Descr'=>'if_descr','Alias'=>'if_alias'][$m[1]];
                $idx = $m[2];
                $value = trim((string) $value);
            }
            if ($field === null) continue;
            $units = (string) ($item['units'] ?? '');
            if (in_array($field, ['in_bps','out_bps','speed_bps'], true)
                    && $units !== 'bps') continue;
            if (str_ends_with($field, '_ps')) {
                // Zabbix preprocessing 10 = change per second. A raw
                // cumulative counter must never be labelled as a rate.
                $steps = $item['preprocessing'] ?? [];
                if (count($steps) !== 1 || (string) ($steps[0]['type'] ?? '') !== '10') continue;
            }
            if (!in_array($field, ['if_name','if_descr','if_alias'], true)) {
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) continue;
                $value = (float) $value;
                if (in_array($field, ['if_type','admin_status','oper_status'], true)) {
                    $max = ['if_type'=>2147483647,'admin_status'=>3,'oper_status'=>7][$field];
                    if ($value < 1 || $value > $max || floor($value) !== $value) continue;
                    $value = (int) $value;
                }
                if ($field === 'speed_bps' && ($value === 4294967295.0 || $value === 0.0)) continue;
            }
            $ports[$idx][$field][] = ['value'=>$value,'clock'=>$clock,'key'=>$key];
        }
        $out = [];
        foreach ($ports as $idx => $fields) {
            $row = ['ifindex'=>(string) $idx,'connector_confirmed'=>false,'position'=>null,'fields'=>[]];
            foreach ($fields as $field => $samples) {
                // Duplicate conflicting sources are unknown, not summed.
                $first = $samples[0]['value'];
                foreach ($samples as $sample) {
                    if ($sample['value'] !== $first) continue 2;
                }
                usort($samples, static fn($a,$b) => $b['clock'] <=> $a['clock'] ?: strcmp($a['key'],$b['key']));
                $row['fields'][$field] = $samples[0];
            }
            $type = $row['fields']['if_type']['value'] ?? null;
            $row['kind'] = match ($type) {
                6, 62, 69, 117 => 'ethernet_candidate',
                161 => 'aggregation',
                135, 136 => 'vlan',
                24, 53, 131, 209 => 'logical',
                default => 'unknown'
            };
            $out[] = $row;
        }
        usort($out, static fn($a,$b) => strnatcmp($a['ifindex'],$b['ifindex']));
        return $out;
    }
}
