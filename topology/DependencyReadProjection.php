<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * Read-side dependency projection. The caller supplies the authorized host
 * list returned by Zabbix Host.get AND the nodes in the current map scope.
 * This class cannot grant visibility and never creates physical edges.
 */
final class DependencyReadProjection {
    public static function project(array $declared, array $visibleNodes,
            array $authorizedHosts): array {
        $visible = [];
        foreach ($visibleNodes as $node) {
            if (!is_array($node)) continue;
            $id = (string) ($node['id'] ?? '');
            if ($id !== '' && preg_match('/^[0-9]{1,20}$/', $id)
                    && isset($authorizedHosts[$id])) {
                $visible[$id] = $authorizedHosts[$id];
            }
        }
        return DependencyResolver::resolve($visible, $declared);
    }
}
