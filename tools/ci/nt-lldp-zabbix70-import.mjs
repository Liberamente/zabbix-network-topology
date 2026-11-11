#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Zabbix 7.0 CI integration only. Loopback API + disposable Docker DB.
// No production credentials, SSH, SNMP, customer network or Zabbix API.
import { readFileSync } from 'node:fs';
import { connect } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

const API_URL = 'http://127.0.0.1:18080/api_jsonrpc.php';
const HOST = 'nt-lldp-ci-synthetic-host';
const TEMPLATE = 'NT LLDP Neighbors by SNMP';
const SOURCE = readFileSync(new URL('../../templates/nt_lldp_snmp_template.yaml', import.meta.url), 'utf8');
const EXPECTED_UUID = '38caefc2777a4ea5a8e2f9a2060c0660';
const PREFIXES = [
    'lldpRemSysName', 'lldpRemPortId', 'lldpRemPortDesc',
    'lldpRemSysDesc', 'lldpRemSysCapEnabled', 'lldpRemChassisId'
];
let auth = null;
let seq = 0;

function assert(ok, description, details = '') {
    if (!ok) throw new Error('FAIL: ' + description + (details ? ': ' + details : ''));
    console.log('PASS: ' + description);
}
async function rpc(method, params = {}, useAuth = true) {
    const payload = {jsonrpc: '2.0', method, params, id: ++seq};
    if (useAuth && auth) payload.auth = auth;
    const res = await fetch(API_URL, {method: 'POST', headers: {'content-type': 'application/json'},
        body: JSON.stringify(payload), signal: AbortSignal.timeout(15000)});
    if (!res.ok) throw new Error(method + ' HTTP ' + res.status);
    const data = await res.json();
    if (data.error) throw new Error(method + ': ' + JSON.stringify(data.error));
    return data.result;
}
async function waitForApi() {
    let last = '';
    for (let n = 0; n < 90; n++) {
        try {
            const version = await rpc('apiinfo.version', {}, false);
            if (version.startsWith('7.0.')) {
                console.log('ISOLATED_ZABBIX_VERSION=' + version);
                return;
            }
            throw new Error('Unexpected Zabbix version ' + version);
        } catch (error) {
            last = error.message;
            await delay(3000);
        }
    }
    throw new Error('Isolated Zabbix API not ready: ' + last);
}
const rules = {
    template_groups: {createMissing: false, updateExisting: false},
    templates: {createMissing: true, updateExisting: true},
    items: {createMissing: true, updateExisting: true, deleteMissing: false},
    discoveryRules: {createMissing: true, updateExisting: true, deleteMissing: false}
};
async function importTemplate(source) {
    return rpc('configuration.import', {format: 'yaml', source, rules});
}
async function templateInfo() {
    const rows = await rpc('template.get', {
        output: ['templateid', 'host', 'uuid'],
        filter: {host: [TEMPLATE]}
    });
    assert(rows.length === 1, 'one imported common template');
    assert(rows[0].uuid === EXPECTED_UUID, 'installed UUID preserved');
    return rows[0];
}
async function prototypes(templateid) {
    const rows = await rpc('itemprototype.get', {
        output: ['itemid', 'key_'], hostids: [templateid]
    });
    return rows.filter(row => PREFIXES.some(prefix =>
        row.key_ === prefix + '[{#SNMPINDEX}]'));
}
async function discoveries(hostid) {
    return rpc('discoveryrule.get', {
        output: ['itemid', 'key_', 'type'], hostids: [hostid]
    });
}
function trapperVariant(source) {
    // Only the master transport changes in the isolated CI. The LLDP
    // discovery/preprocessing and all six dependent prototypes stay byte-identical.
    const pattern = /(      items:\n        - uuid: [a-f0-9]{32}\n          name: 'LLDP: remote systems table \(SNMP walk\)'\n)          type: SNMP_AGENT\n          snmp_oid: '[^\n]+'\n/;
    assert(pattern.test(source), 'unmodified source has expected master SNMP item');
    let result = source.replace(pattern, '$1          type: TRAP\n');
    const delayText = "          key: nt.lldp.walk\n          delay: '{$NT.LLDP.INTERVAL}'\n";
    assert(result.includes(delayText), 'master item delay anchor found');
    result = result.replace(delayText, '          key: nt.lldp.walk\n');
    assert(result.includes('key: nt.lldp.rem.discovery'), 'LLDP discovery unchanged');
    assert(result.includes('key: nt.cdp.cache.discovery'), 'CDP rule unchanged');
    return result;
}
function walk(entries) {
    const rows = [];
    for (const [rem, port] of entries) {
        const stem = '.200.12.' + rem;
        for (const [column, value] of [
            [5, 'synthetic-chassis-01'],
            [7, port],
            [8, 'remote-' + port],
            [9, 'synthetic-peer-01'],
            [10, 'synthetic-system'],
            [12, '20 00']
        ]) rows.push('.1.0.8802.1.1.2.1.4.1.1.' + column + stem
            + ' = STRING: "' + value + '"');
    }
    return rows.join('\n');
}
async function sendWalk(value) {
    const json = Buffer.from(JSON.stringify({request: 'sender data', data: [
        {host: HOST, key: 'nt.lldp.walk', value}
    ]}), 'utf8');
    const header = Buffer.alloc(13);
    header.write('ZBXD', 0, 'ascii');
    header[4] = 1;
    header.writeBigUInt64LE(BigInt(json.length), 5);
    return new Promise((resolve, reject) => {
        const client = connect({host: '127.0.0.1', port: 10051});
        let response = Buffer.alloc(0);
        const timeout = setTimeout(() => {
            client.destroy(); reject(new Error('Zabbix trapper timed out'));
        }, 15000);
        client.on('error', error => {clearTimeout(timeout); reject(error);});
        client.on('connect', () => client.write(Buffer.concat([header, json])));
        client.on('data', chunk => response = Buffer.concat([response, chunk]));
        client.on('end', () => {
            clearTimeout(timeout);
            try {
                const len = Number(response.readBigUInt64LE(5));
                const reply = JSON.parse(response.subarray(13, 13 + len).toString());
                assert(reply.response === 'success' && /processed: 1; failed: 0/.test(reply.info || ''),
                    'synthetic walk accepted by isolated Zabbix trapper', JSON.stringify(reply));
                resolve(reply);
            } catch (error) { reject(error); }
        });
    });
}
async function sendWhenCached(value) {
    let last = '';
    for (let attempt = 0; attempt < 45; attempt++) {
        try {
            return await sendWalk(value);
        } catch (error) {
            last = error.message;
            await delay(3000);
        }
    }
    throw new Error('Synthetic sender item never entered server configuration cache: ' + last);
}
async function waitForHistoricalSample(itemid, expectedValue) {
    let samples = [];
    for (let attempt = 0; attempt < 35; attempt++) {
        samples = await rpc('history.get', {
            output: ['itemid', 'value', 'clock'],
            history: 1, itemids: [itemid], limit: 50
        });
        if (samples.some(row => row.value === expectedValue)) {
            console.log('PASS: prior item history.get samples survive LLD key transition');
            return;
        }
        await delay(2000);
    }
    throw new Error('Previous LLD item has no retrievable history sample '
        + JSON.stringify({itemid, expectedValue, sampleCount: samples.length}));
}
async function neighborItems(hostid) {
    const all = await rpc('item.get', {
        output: ['itemid', 'key_', 'lastvalue', 'lastclock', 'status', 'state', 'error'],
        hostids: [hostid], limit: 5000
    });
    return all.filter(row => PREFIXES.some(prefix =>
        row.key_.startsWith(prefix + '[')));
}
async function waitForStage(hostid, expectedNewValues, sourceWalk, oldKeys = []) {
    let last = [];
    for (let n = 0; n < 65; n++) {
        // A first walk discovers item prototypes; subsequent walks populate
        // their dependent values after the Zabbix server cache has synced.
        if (n > 0 && n % 5 === 0) await sendWhenCached(sourceWalk);
        last = await neighborItems(hostid);
        const names = last.filter(r => r.key_.startsWith('lldpRemPortId[')
            && !oldKeys.includes(r.key_));
        if (expectedNewValues.every(port => names.some(r => r.lastvalue === port))) {
            return last;
        }
        await delay(3000);
    }
    throw new Error('LLD did not expose expected Port-ID values: '
        + JSON.stringify({expectedNewValues, items: last.length,
            values: last.filter(r => r.key_.startsWith('lldpRemPortId['))
                .map(r => ({lastvalue: r.lastvalue, state: r.state,
                    status: r.status, error: r.error || '', lastclock: r.lastclock}))}));
}
function portKey(items, port, oldKeys = []) {
    const matches = items.filter(x => x.key_.startsWith('lldpRemPortId[')
        && x.lastvalue === port && !oldKeys.includes(x.key_));
    assert(matches.length === 1, 'exactly one item with Port-ID ' + port,
        'found=' + matches.length);
    return matches[0];
}
async function main() {
    await waitForApi();
    auth = await rpc('user.login', {username: 'Admin', password: 'zabbix'}, false);
    assert(typeof auth === 'string' && auth.length > 10, 'isolated default Admin logged in');
    await importTemplate(SOURCE);
    const first = await templateInfo();
    const before = await prototypes(first.templateid);
    assert(before.length === 6, 'six original LLDP prototype keys imported');
    const firstIds = new Map(before.map(x => [x.key_, x.itemid]));
    await importTemplate(SOURCE);
    const second = await templateInfo();
    const again = await prototypes(second.templateid);
    assert(second.templateid === first.templateid, 're-import preserves template ID');
    assert(again.length === 6
        && again.every(x => firstIds.get(x.key_) === x.itemid),
        'unmodified template re-import preserves all six prototype item IDs');

    await importTemplate(trapperVariant(SOURCE));
    const changed = await prototypes(first.templateid);
    assert(changed.length === 6
        && changed.every(x => firstIds.get(x.key_) === x.itemid),
        'test-only transport conversion preserves LLDP prototype IDs');

    const group = await rpc('hostgroup.create', {name: 'NT LLDP CI synthetic group'});
    const hostResult = await rpc('host.create', {
        host: HOST, name: HOST, groups: [{groupid: group.groupids[0]}],
        templates: [{templateid: first.templateid}],
        interfaces: [{type: 2, main: 1, useip: 1,
            ip: '127.0.0.1', dns: '', port: '161',
            details: {version: 2, community: 'synthetic-ci'}}]
    });
    const hostid = hostResult.hostids[0];
    const rulesOnHost = await discoveries(hostid);
    assert(rulesOnHost.some(r => r.key_ === 'nt.lldp.rem.discovery'),
        'LLDP discovery inherited by synthetic isolated host');
    assert(rulesOnHost.some(r => r.key_ === 'nt.cdp.cache.discovery'),
        'Cisco CDP discovery remains inherited');

    const masterItems = await rpc('item.get', {
        output: ['itemid', 'type', 'key_', 'status'], hostids: [hostid],
        filter: {key_: ['nt.lldp.walk']}
    });
    assert(masterItems.length === 1 && masterItems[0].type === '2',
        'synthetic host inherited Zabbix TRAP master item',
        JSON.stringify(masterItems.map(x => ({type: x.type, key_: x.key_}))));
    await sendWhenCached(walk([[7, 'eth0']]));
    const single = await waitForStage(hostid, ['eth0'], walk([[7, 'eth0']]));
    const original = portKey(single, 'eth0');
    const legacyKey = original.key_;
    const legacyId = original.itemid;
    assert(/^lldpRemPortId\[0\.12\.\d+\]$/.test(legacyKey),
        'single Port-ID uses numeric legacy-compatible StableId');

    await sendWalk(walk([[70, 'eth0'], [80, 'eth1']]));
    const multi = await waitForStage(hostid, ['eth0', 'eth1'], walk([[70, 'eth0'], [80, 'eth1']]), [legacyKey]);
    const a = portKey(multi, 'eth0', [legacyKey]);
    const b = portKey(multi, 'eth1');
    assert(multi.some(x => x.itemid === legacyId && x.lastvalue === 'eth0'),
        'legacy item retains its historical value during LLD transition');
    await waitForHistoricalSample(legacyId, 'eth0');
    assert(a.key_ !== b.key_, 'multiport creates distinct stable item keys');
    assert(a.key_ !== legacyKey && b.key_ !== legacyKey,
        'legacy aggregate key is never reattributed to a logical Port-ID');
    assert(a.itemid !== legacyId && b.itemid !== legacyId,
        'multiport LLD migration creates distinct item IDs');

    await sendWalk(walk([[700, 'eth0'], [800, 'eth1'], [900, 'aaa']]));
    const triple = await waitForStage(hostid, ['eth0', 'eth1', 'aaa'], walk([[700, 'eth0'], [800, 'eth1'], [900, 'aaa']]), [legacyKey]);
    const a2 = portKey(triple, 'eth0', [legacyKey]);
    const b2 = portKey(triple, 'eth1');
    assert(a2.itemid === a.itemid && b2.itemid === b.itemid,
        'adding earlier-sorting port does not replace existing item IDs');
    assert(a2.key_ === a.key_ && b2.key_ === b.key_,
        'adding third port does not change existing key-to-port mapping');

    console.log('ISOLATED_ZABBIX_70_IMPORT_AND_LLD=PASS');
    console.log('SYNTHETIC_LLDP_ITEM_COUNT=' + triple.length);
    console.log('HISTORY_RETENTION_NOTE=one-to-many transition intentionally changes item IDs');
}
main().catch(error => {
    console.error('ISOLATED_ZABBIX_70_IMPORT_AND_LLD=FAIL: ' + error.message);
    process.exitCode = 1;
});
