import assert from 'node:assert/strict';
globalThis.window = {
    NT_CONFIG: { lang: 'en' }
};

const { injectGhostNodes } = await import(
    '../assets/js/modules/build-elements.js'
);

const nodes = [
    { id: '101', label: 'Synthetic Switch' },
    { id: '102', label: 'Synthetic Phone' }
];

const edges = [{
    id: 'lldp_portid_101_102',
    from: '101',
    to: '102',
    match: 'lldp_portid',
    evidence: ['lldp_portid', 'arp', 'fdb'],
    ports: { '101': 'Gi1/0/5' }
}];

const quality = [{
    id: '101',
    label: 'Synthetic Switch',
    unmatched: [
        {
            raw: 'OLD-PHONE',
            src: 'lldp',
            port: 'Gi1/0/5'
        },
        {
            raw: 'UNKNOWN-DEVICE',
            src: 'lldp',
            port: 'Gi1/0/6'
        }
    ]
}];

// Before backend reconciliation, the observation remains a Ghost.
const before = injectGhostNodes(nodes, edges, quality, 'all');
assert.equal(
    before.nodes.some(n => n.id === 'ghost_old-phone'),
    true,
    'Unreconciled LLDP observations remain visible'
);

// Simulate the backend result after exact LLDP-index reconciliation.
const reconciledQuality = structuredClone(quality);
reconciledQuality[0].unmatched =
    reconciledQuality[0].unmatched.filter(
        u => u.raw !== 'OLD-PHONE'
    );

const result = injectGhostNodes(
    nodes, edges, reconciledQuality, 'all'
);

assert.equal(
    result.nodes.some(n => n.id === 'ghost_old-phone'),
    false,
    'A reconciled observation must not be rendered as a Ghost'
);

assert.equal(
    result.nodes.some(n => n.id === 'ghost_unknown-device'),
    true,
    'An unrelated unknown neighbor must remain visible'
);

console.log('PASS: reconciled Ghost removed; unknown Ghost preserved');
