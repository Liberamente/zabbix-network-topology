<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Offline cache-key isolation checks; no external Zabbix or APCu required. */
class CWebUser {
    public static array $data = [];
}
require dirname(__DIR__) . '/actions/NtCache.php';

use Modules\NetworkTopology\Actions\NtCache;

$keyMethod = (new ReflectionClass(NtCache::class))->getMethod('key');

function keyFor(ReflectionMethod $method, int $uid, int $role, int $type,
        array $parts = [[7, 12]], string $ns = 'data_payload'): string {
    CWebUser::$data = ['userid' => $uid, 'roleid' => $role, 'type' => $type];
    return $method->invoke(null, $ns, $uid, $parts);
}
function checkKey(string $test, bool $passed): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$test}\n");
        exit(1);
    }
    echo "PASS: {$test}\n";
}

$a = keyFor($keyMethod, 42, 3, 2);
checkKey('same user/scope stable', $a === keyFor($keyMethod, 42, 3, 2));
checkKey('group ordering canonical', $a === keyFor($keyMethod, 42, 3, 2, [[12, 7]]));
checkKey('different role isolated', $a !== keyFor($keyMethod, 42, 4, 2));
checkKey('different user type isolated', $a !== keyFor($keyMethod, 42, 3, 3));
checkKey('different userid isolated', $a !== keyFor($keyMethod, 43, 3, 2));
checkKey('different group isolated', $a !== keyFor($keyMethod, 42, 3, 2, [[7, 15]]));
checkKey('different namespace isolated', $a !== keyFor($keyMethod, 42, 3, 2, [[7, 12]], 'health'));
checkKey('schema bumped to v2', strncmp($a, 'nt:v2:', 6) === 0);

// No logged-in user must never retrieve cached data even if APCu is installed.
CWebUser::$data = [];
checkKey('anonymous cache read denied', NtCache::get('data_payload', [[7, 12]]) === null);

checkKey('current host scope unchanged', NtCache::sameHostScope([12, 7], [7 => [], 12 => []]));
checkKey('revoked host rejects cache', !NtCache::sameHostScope([7, 12], [7 => []]));
checkKey('new host rejects cache', !NtCache::sameHostScope([7], [7 => [], 12 => []]));
checkKey('all rights revoked rejects cache', !NtCache::sameHostScope([7], []));
checkKey('API error rejects cache', !NtCache::sameHostScope([7], false));
checkKey('legacy cache missing scope rejects cache', !NtCache::sameHostScope(null, [7 => []]));
echo "NtCache role/group isolation regressions PASS\n";
