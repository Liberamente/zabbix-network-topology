<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\NetworkTopology\Actions {
    class NetworkTopologyController {
        public const API_VERSION = 1;
        public array $input = ['hostid'=>'100','groupids'=>['10']];
        public array $response = [];
        public int $userType = 1;
        public bool $ajax = true;
        public bool $valid = true;
        public bool $rate = true;
        protected function disableCsrfValidation(): void {}
        protected function requireAjax(): bool { return $this->ajax; }
        protected function validateInput(array $rules): bool { return $this->valid; }
        protected function getUserType(): int { return $this->userType; }
        protected function getInput(string $key, $default = null) { return $this->input[$key] ?? $default; }
        protected function throttle(string $key, int $max, int $window): bool { return $this->rate; }
        protected function jsonResponse(array $data): void { $this->response = $data; }
    }
}

namespace {
    const USER_TYPE_ZABBIX_USER = 1;
    final class API {
        public static array $calls = [];
        public static array $rows = [];
        public static function HostGroup() { return new MockRead('group'); }
        public static function Host() { return new MockRead('host'); }
        public static function Item() { return new MockRead('item'); }
    }
    final class MockRead {
        public function __construct(private string $kind) {}
        public function get(array $args) {
            API::$calls[] = [$this->kind, $args];
            return API::$rows[$this->kind] ?? [];
        }
    }
    require __DIR__ . '/../topology/PortInventory.php';
    require __DIR__ . '/../actions/NetworkTopologyPortInventory.php';

    class Probe extends \Modules\NetworkTopology\Actions\NetworkTopologyPortInventory {
        public function run(): void {
            $this->response = [];
            if ($this->checkInput() && $this->checkPermissions()) $this->doAction();
        }
    }
    function check($actual, $expected, string $label): void {
        if ($actual !== $expected) {
            fwrite(STDERR, "FAIL: $label\n" . var_export([$actual,$expected],true));
            exit(1);
        }
        echo "PASS: $label\n";
    }
    $probe = new Probe();
    $sample = ['hostid'=>'100','key_'=>'net.if.type[17]','type'=>'20','status'=>'0','state'=>'0','lastvalue'=>'6','lastclock'=>time()];
    API::$rows = ['group'=>['10'=>['groupid'=>'10']], 'host'=>[['hostid'=>'100']], 'item'=>[$sample]];
    $probe->run();
    check(array_column($probe->response['ports'],'ifindex'),['17'],'visible monitored host');
    check(array_column(API::$calls,0),['group','host','item'],'API order');
    check(API::$calls[1][1]['groupids'],['10'],'host group intersection');
    check(API::$calls[2][1]['hostids'],['100'],'single host item scope');

    API::$calls = [];
    API::$rows['host'] = [];
    $probe->run();
    check(array_column(API::$calls,0),['group','host'],'revoked or unrelated host stops before items');
    check(isset($probe->response['error']),true,'revoked or unrelated host rejected');

    API::$calls = [];
    API::$rows['group'] = [];
    $probe->run();
    check(array_column(API::$calls,0),['group'],'inaccessible group stops before hosts');
    check(isset($probe->response['error']),true,'inaccessible group rejected');

    API::$rows['group'] = ['10'=>['groupid'=>'10']];
    API::$rows['host'] = [['hostid'=>'999']];
    API::$calls = [];
    $probe->run();
    check(array_column(API::$calls,0),['group','host'],'unexpected host rejected');

    API::$rows['host'] = [['hostid'=>'100']];
    API::$rows['item'] = array_fill(0,5001,$sample);
    $probe->run();
    check($probe->response['truncated'] ?? false,true,'oversized inventory rejected');
    check(isset($probe->response['ports']),false,'no partial inventory');

    API::$rows['item'] = [$sample, array_replace($sample,['hostid'=>'999','key_'=>'net.if.type[18]'])];
    $probe->run();
    check(array_column($probe->response['ports'],'ifindex'),['17'],'unexpected item excluded');

    API::$calls = [];
    $probe->ajax = false;
    $probe->run();
    check(API::$calls,[],'non-AJAX request rejected');
    $probe->ajax = true;
    $probe->userType = 0;
    $probe->run();
    check(API::$calls,[],'unauthenticated role rejected');
    $probe->userType = 1;
    $probe->rate = false;
    $probe->run();
    check(API::$calls,[],'rate-limited request rejected');
    echo "Port inventory controller scope regressions PASS\n";
}
