<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Offline role-gating regression for NetworkTopologyCreateHost.
 * No Zabbix API calls and no host writes; synthetic role permissions only.
 */
namespace Modules\NetworkTopology\Actions {
    class NetworkTopologyController {
        protected int $userType = 0;
        protected bool $allowHosts = false;
        public array $checkedRights = [];

        protected function getUserType(): int {
            return $this->userType;
        }
        protected function checkAccess(string $right): bool {
            $this->checkedRights[] = $right;
            return $this->allowHosts;
        }
    }
}

namespace {
    const USER_TYPE_ZABBIX_ADMIN = 2;
    class CRoleHelper {
        public const UI_CONFIGURATION_HOSTS = 'ui.configuration.hosts';
    }

    require dirname(__DIR__) . '/actions/NetworkTopologyCreateHost.php';

    class RoleProbe extends \Modules\NetworkTopology\Actions\NetworkTopologyCreateHost {
        public function allowedFor(int $type, bool $hostsAccess): bool {
            $this->userType = $type;
            $this->allowHosts = $hostsAccess;
            $this->checkedRights = [];
            return $this->checkPermissions();
        }
    }

    $probe = new RoleProbe();
    $cases = [
        ['user with host right', 1, true, false, 0],
        ['admin without host right', 2, false, false, 1],
        ['admin with host right', 2, true, true, 1],
        ['super admin without host right', 3, false, false, 1],
        ['super admin with host right', 3, true, true, 1]
    ];
    foreach ($cases as [$name, $type, $grant, $expected, $numChecks]) {
        $actual = $probe->allowedFor($type, $grant);
        $rights = $probe->checkedRights;
        if ($actual !== $expected || count($rights) !== $numChecks
            || ($numChecks && $rights[0] !== CRoleHelper::UI_CONFIGURATION_HOSTS)) {
            fwrite(STDERR, "FAIL: {$name}\n");
            exit(1);
        }
        echo "PASS: {$name}\n";
    }
    echo "CreateHost role-permission regressions PASS\n";
}
