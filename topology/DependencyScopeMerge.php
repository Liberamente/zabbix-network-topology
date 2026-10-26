<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/** Pure scoped replacement: only dependencies fully inside the selected scope change. */
final class DependencyScopeMerge {
    public static function replace(array $current, array $replacement, array $scopeIds): array {
        $retained = [];
        foreach ($current as $row) {
            if (!isset($scopeIds[(string) ($row['upstream'] ?? '')],
                    $scopeIds[(string) ($row['downstream'] ?? '')])) {
                $retained[] = $row;
            }
        }
        // Cross-scope and out-of-scope rows remain unchanged. The caller
        // validates replacements and the merged graph before persistence.
        return array_merge($retained, $replacement);
    }
}
