<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Actions;

use API;
use Modules\NetworkTopology\Topology\PortInventory;

/** On-demand, read-only interface inventory for one visible monitored host. */
class NetworkTopologyPortInventory extends NetworkTopologyController {
    private const MAX_ITEMS = 5000;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (!$this->requireAjax()) return false;
        if (!$this->validateInput([
            'hostid' => 'required|id',
            'groupids' => 'required|array_id'
        ])) {
            $this->jsonResponse(['error'=>'Invalid input']);
            return false;
        }
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        if (!$this->throttle('port_inventory', 10, 10)) return;
        $hostid = (string) $this->getInput('hostid');
        $groups = array_values(array_unique(array_map('strval', $this->getInput('groupids', []))));
        if (!$groups || count($groups) > 100) {
            $this->jsonResponse(['error'=>'Invalid group scope']);
            return;
        }
        $allowed = API::HostGroup()->get([
            'output'=>['groupid'], 'groupids'=>$groups, 'preservekeys'=>true
        ]);
        if (!is_array($allowed) || count($allowed) !== count($groups)) {
            $this->jsonResponse(['error'=>'Host not found or not accessible']);
            return;
        }
        $hosts = API::Host()->get([
            'output'=>['hostid'], 'hostids'=>[$hostid],
            'groupids'=>$groups, 'monitored_hosts'=>true
        ]);
        if (!is_array($hosts) || count($hosts) !== 1
                || (string) ($hosts[0]['hostid'] ?? '') !== $hostid) {
            $this->jsonResponse(['error'=>'Host not found or not accessible']);
            return;
        }
        $items = API::Item()->get([
            'output'=>['hostid','key_','type','status','state','lastvalue','lastclock','units'],
            'hostids'=>[$hostid], 'monitored'=>true,
            'filter'=>['type'=>[20]],
            'search'=>['key_'=>['net.if.','ifName','ifDescr','ifAlias']],
            'searchByAny'=>true, 'startSearch'=>true,
            'limit'=>self::MAX_ITEMS + 1
        ]);
        if (!is_array($items)) {
            $this->jsonResponse(['error'=>'Interface data unavailable']);
            return;
        }
        // Never represent a partial inventory as a complete switch.
        if (count($items) > self::MAX_ITEMS) {
            $this->jsonResponse(['error'=>'Interface inventory exceeds limit','truncated'=>true]);
            return;
        }
        $now = time();
        $this->jsonResponse([
            'api_version'=>self::API_VERSION,'hostid'=>$hostid,
            'generated_at'=>$now,'max_age_seconds'=>900,
            'truncated'=>false,'ports'=>PortInventory::build($items,$hostid,$now)
        ]);
    }
}
