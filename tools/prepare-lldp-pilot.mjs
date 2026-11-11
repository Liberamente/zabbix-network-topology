#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Isolate a portable LLDP template for a one-host Zabbix pilot.
// No API calls, no customer data, no mutations of existing templates.
import { randomBytes } from 'node:crypto';
import { readFileSync, openSync, writeFileSync, closeSync, unlinkSync } from 'node:fs';
import { resolve, isAbsolute } from 'node:path';

const TEMPLATE_PATH = new URL('../templates/nt_lldp_snmp_template.yaml', import.meta.url);
const ORIGINAL = 'NT LLDP Neighbors by SNMP';

function requireCondition(ok, message) {
    if (!ok) throw new Error(message);
}
function uuids(text) {
    return [...text.matchAll(/^\s+- uuid: ([a-f0-9]{32})\s*$/gm)]
        .map(m => m[1]);
}
function makePilot(original, suffix) {
    requireCondition(/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/.test(suffix),
        'Pilot suffix must contain only ASCII letters, digits, underscores or hyphens');
    requireCondition(original.startsWith("zabbix_export:\n  version: '7.0'\n"),
        'Unexpected Zabbix export format');

    const templatePos = original.indexOf('\n  templates:\n');
    requireCondition(templatePos >= 0, 'Missing templates section');

    const groupPrefix = original.slice(0, templatePos);
    const templateGroupUuids = uuids(groupPrefix);
    requireCondition(templateGroupUuids.length === 1, 'Unexpected template group layout');

    // The bundled Cisco CDP discovery is not needed for the isolated
    // LLDP test. Removing it avoids any unnecessary CDP probes.
    const cdpMarker =
        /^ {8}- uuid: [a-f0-9]{32}\n {10}name: 'CDP neighbor discovery \(Cisco\)'/m;
    const cdpOffset = original.search(cdpMarker);
    requireCondition(cdpOffset > templatePos, 'CDP discovery layout changed');
    requireCondition(original.slice(cdpOffset).includes('key: nt.cdp.cache.discovery'),
        'Expected CDP discovery missing');

    let pilot = original.slice(0, cdpOffset).trimEnd() + '\n';
    const newName = ORIGINAL + ' - PILOT-' + suffix;

    const namePattern =
        /^ {6}(template|name): 'NT LLDP Neighbors by SNMP'$/gm;
    let replacedNames = 0;
    pilot = pilot.replace(namePattern, (_line, field) => {
        replacedNames++;
        return "      " + field + ": '" + newName + "'";
    });
    requireCondition(replacedNames === 2, 'Expected exactly two root template name fields');

    const uuidPattern = /^(\s+- uuid: )([a-f0-9]{32})$/gm;
    const startPos = pilot.indexOf('\n  templates:\n');
    const head = pilot.slice(0, startPos);
    let body = pilot.slice(startPos);
    let replacedUuids = 0;
    const old = new Set(uuids(original));

    body = body.replace(uuidPattern, (_line, prefix, _uuid) => {
        let next;
        do {
            next = randomBytes(16).toString('hex');
        } while (old.has(next));
        replacedUuids++;
        return prefix + next;
    });

    requireCondition(replacedUuids === 9,
        'Expected exactly 9 UUIDs for template, master, LLDP discovery and 6 prototypes');
    pilot = head + body;

    const all = uuids(pilot);
    requireCondition(all.length === 10 && new Set(all).size === 10,
        'Unexpected or duplicate pilot UUIDs');
    requireCondition(uuids(head)[0] === templateGroupUuids[0],
        'Existing template group identifier must be retained');
    requireCondition(all.slice(1).every(uuid => !old.has(uuid)),
        'A UUID is shared with the production template');

    for (const key of [
        'lldpRemSysName', 'lldpRemPortId', 'lldpRemPortDesc',
        'lldpRemSysDesc', 'lldpRemSysCapEnabled', 'lldpRemChassisId'
    ]) {
        requireCondition(pilot.includes("key: '" + key + "[{#SNMPINDEX}]'"),
            'Missing stable LLDP item prototype key ' + key);
    }
    requireCondition(!pilot.includes('key: nt.cdp.cache.discovery'),
        'CDP rule unexpectedly included');
    requireCondition(!pilot.includes("template: '" + ORIGINAL + "'"),
        'Original template name unexpectedly present');

    return { pilot, name: newName };
}

function selfTest() {
    const original = readFileSync(TEMPLATE_PATH, 'utf8');
    const first = makePilot(original, 'SYNTHETIC-TEST');
    const second = makePilot(original, 'SYNTHETIC-TEST');
    requireCondition(first.name === second.name, 'Pilot name is unstable');
    requireCondition(first.pilot !== second.pilot, 'Pilot UUIDs must be random');
    requireCondition(uuids(first.pilot)[0] === uuids(original)[0],
        'Existing template group UUID was changed');
    requireCondition(uuids(first.pilot).length === 10, 'Wrong number of UUIDs');
    requireCondition(original.includes('key: nt.cdp.cache.discovery'),
        'Source template mutated');
    requireCondition(!first.pilot.includes('key: nt.cdp.cache.discovery'),
        'Pilot must omit CDP');
    requireCondition(first.pilot.includes("key: 'lldpRemSysName[{#SNMPINDEX}]'"),
        'Stable neighbor key was lost');
    console.log('LLDP_PILOT_TEMPLATE_SELF_TEST=PASS');
}

function main() {
    const args = process.argv.slice(2);
    if (args.length === 1 && args[0] === '--self-test') {
        selfTest();
        return;
    }
    if (args.length !== 2) {
        throw new Error('Usage: node tools/prepare-lldp-pilot.mjs ABSOLUTE_OUTPUT.yaml PILOT_SUFFIX');
    }
    const [destination, suffix] = args;
    requireCondition(isAbsolute(destination) && /\.yaml$/i.test(destination),
        'Output must be an absolute path ending in .yaml');
    const output = resolve(destination);
    requireCondition(!output.includes('/modules-web/') &&
        !output.includes('/usr/share/zabbix/modules/'),
    'Refusing to write into an installed module directory');

    const original = readFileSync(TEMPLATE_PATH, 'utf8');
    const {pilot, name} = makePilot(original, suffix);
    // Exclusive creation means an existing file can never be overwritten.
    // Root-only permissions protect the local pilot artifacts by default.
    let fd;
    try {
        fd = openSync(output, 'wx', 0o600);
        writeFileSync(fd, pilot, 'utf8');
        closeSync(fd);
        fd = undefined;
    } catch (e) {
        if (fd !== undefined) {
            closeSync(fd);
            unlinkSync(output);
        }
        throw e;
    }
    console.log('PILOT_TEMPLATE_CREATED=PASS');
    console.log('PILOT_TEMPLATE_NAME=' + name);
    console.log('PILOT_TEMPLATE_UUIDS=9_NEW');
    console.log('LLDP_ITEM_PROTOTYPES=6');
    console.log('CDP_DISCOVERY=EXCLUDED');
    console.log('EXISTING_TEMPLATE_MODIFIED=NO');
    console.log('ZABBIX_API_MUTATIONS=0');
    console.log('OUTPUT=' + output);
}

try {
    main();
} catch (error) {
    console.error('LLDP_PILOT_TEMPLATE=FAIL: ' + error.message);
    process.exitCode = 1;
}
