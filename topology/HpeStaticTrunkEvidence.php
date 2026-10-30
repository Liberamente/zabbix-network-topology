<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Topology;

/**
 * Conservative HPE CONFIG-MIB adapter, used only to classify *transit*
 * FDB copies. It does not publish physical or logical edges.
 *
 * A particular TrkN label is accepted only when ALL local static trunk
 * groups have complete, reciprocally observed member pairs and the entire
 * set of local Trk labels accounts for exactly those groups. No numeric
 * TrkN <-> hpSwitchPortTrunkGroup offset is guessed.
 *
 * All inputs are already restricted to one authorized group/proxy.
 */
final class HpeStaticTrunkEvidence
{
    private const MAX_AGE = 900;
    private const GROUP_OID = '1.3.6.1.4.1.11.2.14.11.5.1.7.1.3.1.1.8.';
    private const TYPE_OID  = '1.3.6.1.4.1.11.2.14.11.5.1.7.1.3.1.1.13.';

    /**
     * @param array $trunkItems  Scoped nt.hpe.trunk.group/.type item rows
     * @param array $ifNames     switch hostid => ifIndex => ifName
     * @param array $peerPorts   "switch|localport" => "peer|peerport";
     *                           only from reciprocal LLDP/CDP switch edges
     * @return array<string,true> Verified logical transit places switch|trkN
     */
    public static function verifiedPlaces(
        array $trunkItems, array $ifNames, array $peerPorts, int $now
    ): array {
        if (!$trunkItems || !$ifNames || !$peerPorts) {
            return [];
        }
        $walks = [];
        foreach ($trunkItems as $item) {
            $hid = (string) ($item['hostid'] ?? '');
            $key = (string) ($item['key_'] ?? '');
            if (!isset($ifNames[$hid])
                    || !in_array($key, ['nt.hpe.trunk.group', 'nt.hpe.trunk.type'], true)
                    || !self::fresh($item, $now)) {
                continue;
            }
            if (isset($walks[$hid][$key])) {
                // More than one fresh source with the same key is ambiguous.
                $walks[$hid]['ambiguous'] = true;
                continue;
            }
            $walks[$hid][$key] = (string) ($item['lastvalue'] ?? '');
        }

        $groupsBySwitch = [];
        $labelsBySwitch = [];
        foreach ($walks as $sw => $walk) {
            if (!empty($walk['ambiguous'])
                    || !isset($walk['nt.hpe.trunk.group'], $walk['nt.hpe.trunk.type'])) {
                continue;
            }
            $groups = self::column($walk['nt.hpe.trunk.group'], self::GROUP_OID);
            $types = self::column($walk['nt.hpe.trunk.type'], self::TYPE_OID);
            if ($groups === null || $types === null || !$groups) {
                continue;
            }
            $physicalGroups = [];
            $invalid = false;
            foreach ($groups as $ifIndex => $groupId) {
                if ($groupId === 0) {
                    continue;
                }
                // trunk(1) is HPE CONFIG-MIB's STATIC trunk type.
                if ($groupId < 1 || ($types[$ifIndex] ?? null) !== 1
                        || !isset($ifNames[$sw][$ifIndex])) {
                    $invalid = true;
                    break;
                }
                $name = trim((string) $ifNames[$sw][$ifIndex]);
                if ($name === '' || preg_match('/^Trk[1-9][0-9]*$/iD', $name)) {
                    $invalid = true;
                    break;
                }
                $physicalGroups[$groupId][self::place((string) $sw, $name)] = true;
            }
            if ($invalid || !$physicalGroups || count($physicalGroups) > 32) {
                continue;
            }

            // All configured logical trunk names must be accounted for.
            // Never infer group 2 => Trk1 from numbering alone.
            $trks = [];
            foreach ($ifNames[$sw] as $name) {
                $label = trim((string) $name);
                if (preg_match('/^Trk([1-9][0-9]*)$/iD', $label, $m)) {
                    $canonical = 'trk' . (int) $m[1];
                    if (isset($trks[$canonical])) {
                        $invalid = true;
                        break;
                    }
                    $trks[$canonical] = true;
                }
            }
            if ($invalid || count($trks) !== count($physicalGroups)) {
                continue;
            }
            for ($n = 1; $n <= count($physicalGroups); $n++) {
                if (!isset($trks['trk' . $n])) {
                    $invalid = true;
                    break;
                }
            }
            if ($invalid) {
                continue;
            }

            $candidateGroups = [];
            foreach ($physicalGroups as $gid => $members) {
                if (count($members) < 2 || count($members) > 64) {
                    $invalid = true;
                    break;
                }
                $remote = [];
                $peers = [];
                foreach (array_keys($members) as $place) {
                    $other = $peerPorts[$place] ?? null;
                    if (!is_string($other) || $other === '') {
                        $invalid = true;
                        break;
                    }
                    $remote[$other] = true;
                    $peers[explode('|', $other, 2)[0]] = true;
                }
                if ($invalid || count($peers) !== 1 || count($remote) !== count($members)) {
                    $invalid = true;
                    break;
                }
                $candidateGroups[(string) $gid] = [
                    'members' => $members,
                    'remote' => $remote,
                    'remote_switch' => (string) array_key_first($peers),
                ];
            }
            if (!$invalid) {
                $groupsBySwitch[(string) $sw] = $candidateGroups;
                $labelsBySwitch[(string) $sw] = $trks;
            }
        }

        // A group is trusted only if the remote switch exposes an EXACT
        // counterpart group with the same reciprocal member set.
        $trusted = [];
        foreach ($groupsBySwitch as $sw => $groups) {
            $ok = true;
            foreach ($groups as $group) {
                $otherGroups = $groupsBySwitch[$group['remote_switch']] ?? [];
                $matches = 0;
                foreach ($otherGroups as $otherGroup) {
                    if ($otherGroup['remote_switch'] !== $sw) {
                        continue;
                    }
                    if (count($otherGroup['members']) === count($group['remote'])
                            && array_diff_key($group['remote'], $otherGroup['members']) === []) {
                        $matches++;
                    }
                }
                if ($matches !== 1) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            foreach (array_keys($labelsBySwitch[$sw]) as $label) {
                $trusted[self::place($sw, $label)] = true;
            }
        }
        return $trusted;
    }

    private static function column(string $walk, string $oidPrefix): ?array
    {
        $result = [];
        foreach (FdbParser::lines($walk) as [$oid, $value]) {
            $oid = ltrim(trim((string) $oid), '.');
            if (!str_starts_with($oid, $oidPrefix)) {
                return null;
            }
            $idx = substr($oid, strlen($oidPrefix));
            if (!ctype_digit($idx) || !ctype_digit((string) $value)
                    || isset($result[(string) $idx])) {
                return null;
            }
            $result[(string) $idx] = (int) $value;
        }
        return $result;
    }

    private static function place(string $sw, string $port): string
    {
        return $sw . '|' . strtolower(trim($port));
    }

    private static function fresh(array $item, int $now): bool
    {
        $ts = (int) ($item['lastclock'] ?? 0);
        return (string) ($item['status'] ?? '0') === '0'
            && (string) ($item['state'] ?? '0') === '0'
            && $ts > 0 && $ts <= $now + 60 && $now - $ts <= self::MAX_AGE;
    }
}
