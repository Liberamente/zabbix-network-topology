<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once __DIR__ . '/../topology/PortInventory.php';
use Modules\NetworkTopology\Topology\PortInventory;
$now = 2000000000;
function sample(string $key, $value, array $extra = []): array {
    return $extra + ['hostid'=>'1','status'=>'0','state'=>'0','type'=>'20','lastclock'=>2000000000,'key_'=>$key,'lastvalue'=>$value,'units'=>preg_match('/^net\.if\.(in|out|speed)\[/', $key) ? 'bps' : ''];
}
function check($actual, $expected): void {
    if ($actual !== $expected) {
        fwrite(STDERR, var_export([$actual,$expected],true));
        exit(1);
    }
}
$rows = PortInventory::build([
    sample('net.if.type[17]',6), sample('net.if.status[ifOperStatus.17]',1),
    sample('net.if.status[ifAdminStatus.17]',2), sample('net.if.in[ifHCInOctets.17]',0),
    sample('net.if.out[17]',100), sample('net.if.out[ifHCOutOctets.17]',200),
    sample('net.if.speed[17]',1000000000), sample('ifName[17]','Gi1/0/5'),
    sample('net.if.type[501]',161), sample('net.if.type[502]',135),
    sample('net.if.type[99]',6,['hostid'=>'2']),
    sample('net.if.type[98]',6,['lastclock'=>$now-901]),
    sample('net.if.type[97]',6,['state'=>'1']),
    sample('net.if.type[96]',6,['type'=>'0']),
    sample('net.if.type[95]',6,['status'=>'1']),
    sample('net.if.type[eth0]',6),
    sample('net.if.in[ifOperStatus.94]',10),
    sample('net.if.speed[17]',4294967295)
], '1', $now);
check(array_column($rows,'ifindex'),['17','501','502']);
check(array_column($rows,'kind'),['ethernet_candidate','aggregation','vlan']);
check($rows[0]['connector_confirmed'],false);
check($rows[0]['position'],null);
check($rows[0]['fields']['in_bps']['value'],0.0);
check(isset($rows[0]['fields']['out_bps']),false);
check($rows[0]['fields']['oper_status']['value'],1);
check($rows[0]['fields']['admin_status']['value'],2);
check($rows[0]['fields']['if_name']['value'],'Gi1/0/5');
check($rows[0]['fields']['speed_bps']['value'],1000000000.0);
check(PortInventory::build([], '1', $now),[]);
$extra = PortInventory::build([
    sample('net.if.type[1]',6,['type'=>'18','snmp_master_verified'=>true]),
    sample('net.if.type[2]',6,['type'=>'18']),
    sample('net.if.in[1]',5,['units'=>'Bps']),
    sample('net.if.in.errors[ifInErrors.1]',4,['preprocessing'=>[['type'=>'10']]]),
    sample('net.if.out.errors[ifOutErrors.1]',100)
], '1', $now);
check(array_column($extra,'ifindex'),['1']);
check(isset($extra[0]['fields']['in_bps']),false);
check($extra[0]['fields']['in_errors_ps']['value'],4.0);
check(isset($extra[0]['fields']['out_errors_ps']),false);
echo "Port inventory scope, evidence and unknown-value tests PASS\n";
