import assert from 'node:assert/strict';

globalThis.window = {
    NT_CONFIG: { lang: 'en_US' },
    innerWidth: 1280,
    innerHeight: 900
};
globalThis.localStorage = {
    getItem() { return null; },
    setItem() {}
};

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { language: 'en-US' }
});

const { buildEdgeElements } =
    await import('../assets/js/modules/build-elements.js');

const { buildCytoscapeStyle } =
    await import('../assets/js/modules/render-tech-style.js');

const nodes = [
    { id: 'demo-switch', traffic: { in: 0, out: 0 }, severity: 0 },
    { id: 'demo-host', traffic: { in: 0, out: 0 }, severity: 0 }
];

function physical(id, port) {
    return {
        id,
        from: 'demo-switch',
        to: 'demo-host',
        src: ['lldp'],
        reporters: ['demo-switch', 'demo-host'],
        match: 'exact',
        confirmed: true,
        confidence: 100,
        ports: {
            'demo-switch': port,
            'demo-host': port
        },
        port_metrics: {}
    };
}

const inferred = {
    id: 'lldp_portid_demo-switch_demo-host',
    from: 'demo-switch',
    to: 'demo-host',
    src: ['lldp'],
    reporters: ['demo-switch'],
    match: 'lldp_portid',
    port_match: 'lldp_local_fdb',
    evidence: ['lldp_portid', 'arp', 'fdb'],
    confirmed: false,
    confidence: 70,
    ports: { 'demo-switch': 'Ethernet30' },
    port_idx: {},
    port_metrics: {},
    fdb_shared_port: true
};

// Due veri cavi paralleli + una associazione inferita.
// Il trunk deve restare x2, mai x3.
const elements = buildEdgeElements([
    physical('cable-1', 'Ethernet1'),
    physical('cable-2', 'Ethernet2'),
    inferred
], nodes);

assert.equal(elements.length, 3);

const association = elements.find(
    e => e.data.kind === 'lldp_portid'
)?.data;

assert.ok(association, 'LLDP Port-ID edge missing');
assert.equal(association.isLLDP, true);
assert.equal(association.confirmed, false);
assert.equal(association.fdbSharedPort, true);
assert.equal(association.confidence, 70);
assert.deepEqual(
    association.evidence,
    ['lldp_portid', 'arp', 'fdb']
);

assert.equal(association.portSrc, 'Ethernet30');
assert.equal(association.portTgt, '');
assert.equal(association.trafficIn, 0);
assert.equal(association.trafficOut, 0);
assert.equal(association.capBps, 0);
assert.equal(association.perLink, false);
assert.equal(association.portDown, null);

assert.equal(
    association.bundleSize,
    undefined,
    'Inferred edge must not become a physical trunk member'
);

const cables = elements.filter(
    e => e.data.kind !== 'lldp_portid'
);

assert.equal(cables.length, 2);

for (const cable of cables) {
    assert.equal(
        cable.data.bundleSize,
        2,
        'Physical trunk must remain x2'
    );
}

const style = buildCytoscapeStyle(false, 32).find(
    s => s.selector === 'edge[kind = "lldp_portid"]'
);

assert.ok(style, 'Dedicated inferred edge style missing');
assert.notEqual(
    style.style['line-style'],
    'solid',
    'Inferred edge must not look like a confirmed cable'
);

console.log('PASS: LLDP Port-ID UI invariants');
console.log('PASS: physical trunk x2 preserved');
console.log('PASS: inferred association never counted as cable');
console.log('PASS: no fabricated link metrics');
