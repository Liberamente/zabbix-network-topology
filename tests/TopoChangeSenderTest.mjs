// SPDX-License-Identifier: AGPL-3.0-or-later
// Offline smoke test for topo-change-sender.sh. Only synthetic inputs.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../tools/topo-change-sender.sh', import.meta.url));
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nt-sender-fixture-'));
const bin = path.join(dir, 'bin');
fs.mkdirSync(bin);

function executable(name, source) {
    const target = path.join(bin, name);
    fs.writeFileSync(target, source, { mode: 0o700 });
}
const fakeCurl = [
    '#!/usr/bin/env node',
    "const fs = require('fs');",
    "const args = process.argv.slice(2);",
    "fs.appendFileSync(process.env.TEST_CURL_LOG, JSON.stringify(args) + '\\n');",
    "const p = args.find(x => x.startsWith('password@'));",
    "if (p) {",
    "  const file = p.slice('password@'.length);",
    "  if (fs.readFileSync(file, 'utf8') !== process.env.TEST_PASSWORD) process.exit(7);",
    "  if ((fs.statSync(file).mode & 0o777) !== 0o600) process.exit(8);",
    "}",
    "const at = args.indexOf('-o');",
    "if (at >= 0 && args[at+1] !== '/dev/null') {",
    "  if ((fs.statSync(args[at+1]).mode & 0o777) !== 0o600) process.exit(9);",
    "  fs.copyFileSync(process.env.TEST_FIXTURE, args[at+1]);",
    "}"
].join('\n') + '\n';
const fakeSender = [
    '#!/usr/bin/env node',
    "const fs = require('fs');",
    "const args = process.argv.slice(2);",
    "const i = args.indexOf('-i');",
    "if (i >= 0) {",
    "  const file = args[i+1];",
    "  if ((fs.statSync(file).mode & 0o777) !== 0o600) process.exit(10);",
    "  fs.appendFileSync(process.env.TEST_SENDER_INPUT, fs.readFileSync(file));",
    "}",
    "fs.appendFileSync(process.env.TEST_SENDER_LOG, JSON.stringify(args) + '\\n');"
].join('\n') + '\n';
executable('curl', fakeCurl);
executable('zabbix_sender', fakeSender);

const fixture = path.join(dir, 'large.json');
const curlLog = path.join(dir, 'curl.log');
const senderLog = path.join(dir, 'sender.log');
const senderInput = path.join(dir, 'sender-input.log');
fs.writeFileSync(fixture, JSON.stringify({
    topo_changes: { added: [{ a: 'lab-switch', b: 'lab-host' }], removed: [] },
    health: { avg: 91, min: 78 },
    padding: 'z'.repeat(300000) // would exceed argv limit in the former implementation
}));
const password = 'synthetic-password-not-a-secret';
const baseEnv = {
    ...process.env,
    PATH: bin + path.delimiter + process.env.PATH,
    ZBX_URL: 'https://example.invalid',
    ZBX_USER: 'synthetic-monitor',
    ZBX_PASS: password,
    GROUPIDS: '17,18',
    SENDER_HOST: 'synthetic-trapper',
    SENDER_CONF: '/dev/null',
    TEST_CURL_LOG: curlLog,
    TEST_SENDER_LOG: senderLog,
    TEST_SENDER_INPUT: senderInput,
    TEST_FIXTURE: fixture,
    TEST_PASSWORD: password
};

function run(overrides = {}) {
    fs.writeFileSync(curlLog, '');
    fs.writeFileSync(senderLog, '');
    fs.writeFileSync(senderInput, '');
    return spawnSync('bash', [script], {
        env: { ...baseEnv, ZBX_INSECURE: '0', ZBX_CACERT: '', ...overrides },
        encoding: 'utf8',
        maxBuffer: 1024 * 1024
    });
}
function records(file) {
    return fs.readFileSync(file, 'utf8').trim().split('\n')
        .filter(Boolean).map(s => JSON.parse(s));
}
function hasArg(args, value) {
    return args.some(x => x === value);
}

try {
    const main = run();
    assert.equal(main.status, 0, main.stderr || String(main.error));
    assert.match(main.stdout, /topo-changes: 1/);
    const curlCalls = records(curlLog);
    assert.equal(curlCalls.length, 2);
    assert.ok(curlCalls.every(c => !hasArg(c, '-k')), 'TLS bypass is forbidden by default');
    assert.ok(curlCalls.every(c => !c.join(' ').includes(password)), 'password leaked in curl argv');
    const field = curlCalls[0].find(a => a.startsWith('password@'));
    assert.ok(field, 'password not passed with curl @file convention');
    assert.ok(!fs.existsSync(field.slice('password@'.length)), 'password file not cleaned up');
    const response = curlCalls[1];
    const responseFile = response[response.indexOf('-o') + 1];
    assert.ok(responseFile && !fs.existsSync(responseFile), 'response file not cleaned up');
    const sent = records(senderLog);
    assert.equal(sent.length, 4, 'expected topology count/text and health avg/min');
    const keys = sent.map(a => a.includes('-i') ? 'nt.topo.changes.text' : a[a.indexOf('-k') + 1]).sort();
    assert.match(fs.readFileSync(senderInput, 'utf8'), /nt\.topo\.changes\.text/);
    assert.ok(!sent.flat().join(' ').includes('lab-switch'));
    const inputPath = sent.find(a => a.includes('-i'));
    assert.ok(!fs.existsSync(inputPath[inputPath.indexOf('-i') + 1]));
    assert.deepEqual(keys, [
        'nt.health.score', 'nt.health.score.min',
        'nt.topo.changes', 'nt.topo.changes.text'
    ].sort());
    assert.ok(sent.some(a => a.includes('1')), 'topology event count not sent');

    const withCa = run({ ZBX_CACERT: path.join(dir, 'test-ca.pem') });
    assert.equal(withCa.status, 0, withCa.stderr || String(withCa.error));
    assert.ok(records(curlLog).every(a => hasArg(a, '--cacert')));
    assert.ok(records(curlLog).every(a => !hasArg(a, '-k')));

    const lab = run({ ZBX_INSECURE: '1' });
    assert.equal(lab.status, 0, lab.stderr || String(lab.error));
    assert.ok(records(curlLog).every(a => hasArg(a, '-k')));
    assert.match(lab.stderr, /WARN: ZBX_INSECURE=1/);

    const blocked = run({ GROUPIDS: '' });
    assert.notEqual(blocked.status, 0, 'empty scope must fail');
    assert.equal(records(curlLog).length, 0, 'failed scope must not call curl');
    assert.equal(records(senderLog).length, 0, 'failed scope must not send data');

    for (const invalid of ['<html>login</html>', '{', '{}', '{"error":"denied"}', '{"topo_changes":{"added":null,"removed":[]}}']) {
        fs.writeFileSync(fixture, invalid);
        const failed = run();
        assert.notEqual(failed.status, 0, 'invalid response must fail');
        assert.equal(records(senderLog).length, 0, 'invalid response must not send metrics');
    }

    fs.writeFileSync(fixture, JSON.stringify({ topo_changes: { added: [], removed: [] } }));
    const empty = run();
    assert.equal(empty.status, 0, empty.stderr);
    assert.match(empty.stdout, /topo-changes: 0/);
    assert.equal(records(senderLog).length, 2);

    fs.writeFileSync(fixture, JSON.stringify({ topo_changes: { added: [{ a: 'x'.repeat(300000), b: 'lab' }], removed: [] } }));
    const long = run();
    assert.equal(long.status, 0, long.stderr);
    assert.match(long.stdout, /topo-changes: 1/);
    const input = fs.readFileSync(senderInput, 'utf8');
    assert.match(input, /\[truncated\]/);
    assert.ok(Buffer.byteLength(input) < 61000);
    assert.ok(records(senderLog).flat().every(a => a.length < 1000));

    console.log('TopoChangeSender synthetic integration tests PASS');
} finally {
    fs.rmSync(dir, { recursive: true, force: true });
}
