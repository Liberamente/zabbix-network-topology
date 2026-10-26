<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;
use CCsrfTokenHelper;
use Modules\NetworkTopology\Topology\ManualDependencies;
use Modules\NetworkTopology\Topology\DependencyWriteValidator;
use Modules\NetworkTopology\Topology\DependencyScopeMerge;
use Modules\NetworkTopology\Topology\DependencyResolver;
use Modules\NetworkTopology\Topology\Revision;

/**
 * Explicit admin-confirmed connectivity dependencies; no topology edges or
 * trigger dependencies are changed here. Not registered in manifest yet.
 */
class NetworkTopologyDependencies extends NetworkTopologyController {
    private const MAX_PAYLOAD = 131072;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->requireAjax()) {
            $this->jsonResponse(['error' => 'Method not allowed']);
            return false;
        }
        if (!$this->validateInput([
            'dependencies' => 'required|string',
            'base' => 'required|string',
            'nt_csrf' => 'required|string',
            'groupids' => 'required|array_id'
        ])) {
            $this->jsonResponse(['error' => 'Invalid input']);
            return false;
        }
        if (!CCsrfTokenHelper::check((string) $this->getInput('nt_csrf', ''),
                'network.topology.dependencies')) {
            $this->jsonResponse(['error' => 'CSRF token invalid']);
            return false;
        }
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() === USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        if (!$this->throttle('dependencies', 10, 10)) return;
        $raw = (string) $this->getInput('dependencies', '');
        if (strlen($raw) > self::MAX_PAYLOAD) {
            $this->jsonResponse(['error' => 'Payload too large']); return;
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 2000) {
            $this->jsonResponse(['error' => 'Invalid dependency list']); return;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 4
                    || array_diff(array_keys($row), ['upstream', 'downstream', 'type', 'source'])
                    || array_diff(['upstream', 'downstream', 'type', 'source'], array_keys($row))) {
                $this->jsonResponse(['error' => 'Invalid dependency record']); return;
            }
        }
        $clean = ManualDependencies::sanitize($rows);
        // Fail closed: never silently discard unauthorized or invalid records.
        if (count($clean) !== count($rows)) {
            $this->jsonResponse(['error' => 'Invalid or duplicate dependencies']); return;
        }
        // Scope comes from Zabbix Host.get, never from client-provided host IDs.
        // Multiple groups may be selected, but they must all be valid and
        // visible to the current user; unknown IDs fail closed.
        $groupids = array_values(array_unique(array_map('strval',
            (array) $this->getInput('groupids', []))));
        if (!$groupids || count($groupids) > 100) {
            $this->jsonResponse(['error' => 'Invalid group scope']); return;
        }
        $groups = API::HostGroup()->get([
            'groupids' => $groupids, 'output' => ['groupid']
        ]);
        if (!is_array($groups) || count($groups) !== count($groupids)) {
            $this->jsonResponse(['error' => 'Unauthorized group scope']); return;
        }
        $found = API::Host()->get([
            'groupids' => $groupids,
            'output' => ['hostid', 'proxyid'],
            'monitored_hosts' => true,
            'preservekeys' => true
        ]);
        if (!is_array($found)) {
            $this->jsonResponse(['error' => 'Host scope lookup failed']); return;
        }
        $hosts = [];
        $scopeIds = [];
        foreach ($found as $id => $host) {
            $hosts[(string) $id] = $host;
            $scopeIds[(string) $id] = true;
        }
        if (!DependencyWriteValidator::validate($rows, $hosts, $scopeIds)) {
            $this->jsonResponse(['error' => 'Invalid dependency or unauthorized scope']);
            return;
        }

        $current = ManualDependencies::loadShared();
        // Replace only rows with BOTH endpoints inside the authorized scope.
        // Preserve all other customer and cross-scope dependencies.
        $merged = DependencyScopeMerge::replace($current, $clean, $scopeIds);
        if (count($merged) > 2000) {
            $this->jsonResponse(['error' => 'Dependency limit exceeded']); return;
        }
        // Resolve all referenced hosts for whole-graph cycle and proxy checks.
        $allIds = [];
        foreach ($merged as $d) {
            $allIds[(string) $d['upstream']] = true;
            $allIds[(string) $d['downstream']] = true;
        }
        if ($allIds) {
            $allHosts = API::Host()->get([
                'hostids' => array_keys($allIds),
                'output' => ['hostid', 'proxyid'],
                'preservekeys' => true
            ]);
            if (!is_array($allHosts) || count($allHosts) !== count($allIds)
                    || count(DependencyResolver::resolve($allHosts, $merged)) !== count($merged)) {
                $this->jsonResponse(['error' => 'Invalid combined dependency graph']); return;
            }
        }
        // This route requires an exact revision; Revision::matches('') is permissive
        // for legacy callers and must not be used for dependency writes.
        if (!hash_equals(Revision::of($current),
                (string) $this->getInput('base', ''))) {
            $this->jsonResponse([
                'error' => 'Dependencies changed concurrently',
                'conflict' => true,
                'revision' => Revision::of($current)
            ]);
            return;
        }

        try {
            $saved = ManualDependencies::saveSharedChecked($merged, (string) $this->getInput('base', ''));
        }
        catch (\Throwable $e) {
            error_log('network.topology.dependencies: ' . $e);
            $this->jsonResponse(['error' => 'Dependencies could not be saved']); return;
        }
        $this->jsonResponse([
            'ok' => true,
            'dependencies' => $saved,
            'revision' => Revision::of($saved)
        ]);
    }
}
