import assert from 'node:assert/strict';

// Simulated browser environment required by i18n.js.
globalThis.window = {
    NT_CONFIG: { lang: 'en_US' }
};

globalThis.localStorage = {
    getItem() { return null; },
    setItem() {}
};

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { language: 'en-US' }
});

const { renderCdpEvidenceTable } =
    await import('../assets/js/modules/cdp-evidence-table.js');

const theme = {
    borderSoft: '#ddd',
    text: '#111',
    sub: '#666'
};

const records = [
    {
        reporter: '101',
        port: '14',
        raw: '01 AC 11 02 52',
        status: 'associated_inferred',
        candidate_count: 1,
        shared_port: true,
        evidence: ['lldp_portid', 'fdb'],
        identity_confirmed: false
    },
    {
        reporter: '101',
        port: '15',
        raw: 'SYNTHETIC-AMBIGUOUS',
        status: 'ambiguous',
        candidate_count: 2,
        shared_port: false,
        evidence: ['fdb'],
        identity_confirmed: false
    },
    {
        reporter: '102',
        port: '16',
        raw: '<script>alert(1)</script>',
        status: 'unmatched',
        candidate_count: 0,
        shared_port: false,
        evidence: [],
        identity_confirmed: false
    }
];

const before = JSON.stringify(records);
const html = renderCdpEvidenceTable(records, theme);

assert.match(html, /Associated \(inferred\)/);
assert.match(html, /Ambiguous/);
assert.match(html, /Unmatched/);
assert.match(html, /01 AC 11 02 52/);
assert.match(html, /lldp_portid, fdb/);
assert.match(html, /&lt;script&gt;/);
assert.doesNotMatch(html, /<script>/);

assert.equal(JSON.stringify(records), before);
assert.equal(renderCdpEvidenceTable([], theme), '');

console.log('PASS: CDP evidence UI, escaping and immutability');
