<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require_once dirname(__DIR__).'/topology/ZoneDirectorWifiSource.php';
use Modules\NetworkTopology\Topology\ZoneDirectorWifiSource;

$apRoot='1.3.6.1.4.1.25053.1.2.2.1.1.2.1.1';
$clRoot='1.3.6.1.4.1.25053.1.2.2.1.1.3.1.1';
$ap='6.2.170.187.204.221.16'; // synthetic identifiers, not customer data
$cl='6.2.170.187.204.221.1';
$apWalk=[
  1=>".$apRoot.'.1.'.$ap.' = Hex-STRING: 02 AA BB CC DD 10',
  3=>".$apRoot.'.3.'.$ap.' = INTEGER: 1',
  10=>".$apRoot.'.10.'.$ap.' = IpAddress: 192.0.2.4',
];
$clWalk=[
  2=>".$clRoot.'.2.'.$cl.' = Hex-STRING: 02 AA BB CC DD 10',
  8=>".$clRoot.'.8.'.$cl.' = IpAddress: 192.0.2.147',
];
function check(bool $ok,string $description): void {
    if (!$ok) { echo "FAIL: $description\n"; exit(1); }
    echo "PASS: $description\n";
}
$rows=ZoneDirectorWifiSource::associations($apWalk,$clWalk);
check(count($rows)===1,'associated client parsed');
check($rows[0]['ap_ip']==='192.0.2.4','AP resolved by MAC and IP');
check($rows[0]['client_mac']==='02:aa:bb:cc:dd:01','MAC client from SNMP index');
check(!isset($rows[0]['signal_dbm']),'signal not invented');
$down=$apWalk; $down[3]=str_replace('INTEGER: 1','INTEGER: 0',$down[3]);
check(ZoneDirectorWifiSource::associations($down,$clWalk)===[],'inactive AP ignored');
$missing=$clWalk; $missing[2]=str_replace('02 AA BB CC DD 10','02 AA BB CC DD 11',$missing[2]);
check(ZoneDirectorWifiSource::associations($apWalk,$missing)===[],'unknown AP ignored');
$duplicate=$apWalk;
foreach ([1,3,10] as $col) {
    $duplicate[$col].="\n".str_replace($ap,'6.2.170.187.204.221.17',$apWalk[$col]);
    if ($col===1) $duplicate[$col]=preg_replace('/(\.1\.6\.88\.182\.51\.0\.159\.17 = Hex-STRING: ).*/','$1 02 AA BB CC DD 11',$duplicate[$col]);
}
check(ZoneDirectorWifiSource::associations($duplicate,$clWalk)===[],'duplicate active IP rejected');
echo "All ZoneDirector source tests passed.\n";
