<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;
use CCsrfTokenHelper;
use Modules\NetworkTopology\Topology\ManualDependencies;
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
            'nt_csrf' => 'required|string'
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
        foreach ($rows as $row) {\n            if (!is_array($row) || count($row) !== 4\n                    || array_diff(array_keys($row), ['upstream', 'downstream', 'type', 'source'])\n                    || array_diff(['upstream', 'downstream', 'type', 'source'], array_keys($row))) {\n                $this->jsonResponse(['error' => 'Invalid dependency record']); return;\n            }\n        }\n        $clean = ManualDependencies::sanitize($rows);
        // Fail closed: never silently discard unauthorized or invalid records.
        if (count($clean) !== count($rows)) {
            $this->jsonResponse(['error' => 'Invalid or duplicate dependencies']); return;
        }
        $ids = [];
        foreach ($clean as $d) {
            $ids[$d['upstream']] = true;
            $ids[$d['downstream']] = true;
        }
        $hosts = [];
        if ($ids) {
            $found = API::Host()->get([
                'hostids' => array_keys($ids),
                'output' => ['hostid', 'proxyid'],
                'preservekeys' => true
            ]);
            if (!is_array($found)) {
                $this->jsonResponse(['error' => 'Host validation failed']); return;
            }
            foreach ($found as $id => $host) {
                $hosts[(string) $id] = $host;
            }
            if (count($hosts) !== count($ids)) {
                $this->jsonResponse(['error' => 'Unknown host in dependency']); return;
            }
        }
        $resolved = DependencyResolver::resolve($hosts, $clean);
        if (count($resolved) !== count($clean)) {
            $this->jsonResponse(['error' => 'Cycle, cross-proxy or invalid dependency']);
            return;
        }

        $current = ManualDependencies::loadShared();
        // This route requires an exact revision; Revision::matches('') is permissive\n        // for legacy callers and must not be used for dependency writes.\n        if (!hash_equals(Revision::of($current),\n                (string) $this->getInput('base', ''))) {
            $this->jsonResponse([
                'error' => 'Dependencies changed concurrently',
                'conflict' => true,
                'revision' => Revision::of($current)
            ]);
            return;
        }

        try {
            $saved = ManualDependencies::saveShared($clean);
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
