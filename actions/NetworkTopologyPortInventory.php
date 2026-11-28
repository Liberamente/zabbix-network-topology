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
            'output'=>['hostid','key_','type','status','state','lastvalue','lastclock','units','master_itemid','itemid'],
            'selectPreprocessing'=>['type','params'],
            'hostids'=>[$hostid], 'monitored'=>true,
            'filter'=>['type'=>[20,18]],
            'search'=>['key_'=>['net.if.','ifName','ifDescr','ifAlias','nt.fdb.ifname']],
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
        // A dependent item is accepted only with a visible, enabled,
        // supported SNMP master on the same host. Nested/unknown masters
        // are deliberately excluded until their complete chain is proven.
        $masterIds = [];
        foreach ($items as $item) {
            if ((string) ($item['hostid'] ?? '') === $hostid
                    && (string) ($item['type'] ?? '') === '18'
                    && preg_match('/^[1-9][0-9]*$/D', (string) ($item['master_itemid'] ?? ''))) {
                $masterIds[(string) $item['master_itemid']] = true;
            }
        }
        $verified = [];
        if ($masterIds) {
            $masters = API::Item()->get([
                'output'=>['itemid','hostid','type','status','state'],
                'hostids'=>[$hostid], 'itemids'=>array_keys($masterIds),
                'filter'=>['type'=>[20]], 'monitored'=>true
            ]);
            if (is_array($masters)) {
                foreach ($masters as $master) {
                    $id = (string) ($master['itemid'] ?? '');
                    if (isset($masterIds[$id])
                            && (string) ($master['hostid'] ?? '') === $hostid
                            && (string) ($master['type'] ?? '') === '20'
                            && (string) ($master['status'] ?? '') === '0'
                            && (string) ($master['state'] ?? '') === '0') $verified[$id] = true;
                }
            }
        }
        foreach ($items as &$item) {
            $item['snmp_master_verified'] = isset($verified[(string) ($item['master_itemid'] ?? '')]);
        }
        unset($item);
        $now = time();
        $this->jsonResponse([
            'api_version'=>self::API_VERSION,'hostid'=>$hostid,
            'generated_at'=>$now,'max_age_seconds'=>900,
            'truncated'=>false,'ports'=>PortInventory::build($items,$hostid,$now)
        ]);
    }
}
