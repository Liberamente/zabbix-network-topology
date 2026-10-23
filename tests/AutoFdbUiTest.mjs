// SPDX-License-Identifier: AGPL-3.0-or-later
// Synthetic UI contract: no real hostids, clients or networks.
import assert from 'node:assert/strict';

class MockElement {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.style = { cssText: '', setProperty() {} };
        this.classList = { contains() { return false; }, toggle() {} };
        this.textContent = '';
        this.innerHTML = '';
        this.offsetWidth = 180;
        this.offsetHeight = 120;
    }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this.children = children; this.innerHTML = ''; }
    addEventListener() {}
}

const root = new MockElement('root');
let tip;
globalThis.document = {
    body: { appendChild(e) { if (e.id === 'nt-ring-tip') tip = e; } },
    createElement(tag) { return new MockElement(tag); },
    createTextNode(s) { return { textContent: String(s), children: [] }; },
    getElementById(id) { return id === 'nt-root' ? root : null; },
    hidden: false,
};
globalThis.window = { NT_CONFIG: { lang: 'en_US' }, innerWidth: 1280, innerHeight: 900 };
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { language: 'en-US' } });
globalThis.localStorage = { getItem() { return null; } };

let fetchCount = 0;
globalThis.fetch = () => { fetchCount++; throw Error('FDB UI must not fetch link history'); };

const { showEdgeTip } = await import('../assets/js/modules/tooltip.js');
const { showEdgeDetail } = await import('../assets/js/modules/edge-detail.js');
const { applyTrafficHeatmap, markDisasterState, setWeathermapMode } =
    await import('../assets/js/modules/traffic.js');
const { buildCytoscapeStyle } = await import('../assets/js/modules/render-tech-style.js');
const { buildEdgeElements } = await import('../assets/js/modules/build-elements.js');
const { annotateBundles } = await import('../assets/js/modules/parallel-links.js');

const apiEdge = {
    id: 'fdb_31_32', from: '31', to: '32', match: 'fdb',
    src: ['fdb'], confirmed: false, confidence: 60,
    ports: { '31': 'GigabitEthernet3' },
    port_idx: { '31': '3' },
    fdb_shared_port: true,
    last_seen: 1800000000,
    port_metrics: { '31': { in: 12340000, out: 56780000 } }
};
const nodes = [
    { id: '31', traffic: { in: 1000000, out: 2000000 }, severity: 5 },
    { id: '32', traffic: { in: 3000000, out: 4000000 } }
];
const built = buildEdgeElements([apiEdge], nodes);
assert.equal(built.length, 1);
const data = built[0].data;
assert.equal(data.kind, 'fdb');
assert.equal(data.isLLDP, false);
assert.equal(data.portSrc, 'GigabitEthernet3');
assert.equal(data.fdbSharedPort, true);
assert.equal(data.perLink, false);
assert.equal(data.trafficIn, 0);
assert.equal(data.trafficOut, 0);
assert.equal(data.capBps, 0);
assert.equal(data.portDown, null);

// LLDP Port-ID fallback: no fabricated per-endpoint metrics.
// The switch port also learns a downstream device's MAC.
const portIdEdge = {
    id: 'lldp_portid_31_32',
    from: '31',
    to: '32',
    match: 'lldp_portid',
    src: ['lldp'],
    reporters: ['31'],
    confirmed: false,
    confidence: 70,
    evidence: ['lldp_portid', 'arp', 'fdb'],
    ports: { '31': 'GigabitEthernet3' },
    fdb_shared_port: true,
    port_metrics: []
};

const portIdNodes = nodes.map(n => ({
    ...n,
    link_speed: 1000000000
}));

const portIdData = buildEdgeElements(
    [portIdEdge], portIdNodes
)[0].data;

assert.equal(portIdData.matchKind, 'lldp_portid');
assert.equal(portIdData.confirmed, false);
assert.equal(portIdData.kind, 'lldp_portid');
assert.equal(portIdData.isLLDP, true);
assert.deepEqual(portIdData.reporters, ['31']);
assert.deepEqual(portIdData.evidence, ['lldp_portid', 'arp', 'fdb']);

assert.deepEqual(
    [
        portIdData.trafficIn,
        portIdData.trafficOut,
        portIdData.capBps,
        portIdData.tLabel,
        portIdData.fdbSharedPort
    ],
    [0, 0, 0, '', true],
    'Unilateral LLDP must not inherit node traffic or hide shared port'
);

const text = e => [e.textContent || '', ...(e.children || []).flatMap(text)].join(' ');
showEdgeTip({ clientX: 200, clientY: 150 }, data, 'Switch', '<unknown>');
assert.match(text(tip), /ARP.*FDB/i);
assert.match(text(tip), /GigabitEthernet3/);
assert.match(text(tip), /Shared|multiple/i);
assert.equal(tip.innerHTML, '', 'FDB tooltip must use textContent, not HTML interpolation');
assert.equal(fetchCount, 0, 'no history fetch is valid for an inferred edge');

const panel = new MockElement('panel');
const fakeNode = (id,label) => ({ id: () => id, data: k => ({ label })[k] });
const fakeEdge = {
    data: k => k === undefined ? data : data[k],
    hasClass: () => false,
    source: () => fakeNode('31','Switch'),
    target: () => fakeNode('32','Endpoint'),
};
showEdgeDetail(panel, fakeEdge);
assert.match(text(panel), /ARP.*FDB/i);
assert.match(text(panel), /GigabitEthernet3/);
assert.match(text(panel), /multiple/i);
assert.doesNotMatch(text(panel), /Utilization|Gb\/s|Errors\/s|link utilization/i);

// A reconciled, one-sided LLDP association must explain its
// evidence without fetching aggregate host traffic history.
tip.replaceChildren();
const fetchBeforePortId = fetchCount;

showEdgeTip(
    { clientX: 200, clientY: 150 },
    portIdData,
    'Test Switch',
    'Test Endpoint'
);

const portIdTipText = text(tip);

assert.match(
    portIdTipText,
    /LLDP\s*\+\s*ARP\s*\+\s*FDB/i,
    'LLDP Port-ID tooltip must explain corroborated one-sided evidence'
);

assert.match(portIdTipText, /GigabitEthernet3/);
assert.match(portIdTipText, /shared|multiple/i);
assert.match(portIdTipText, /one-sided|unconfirmed|not confirmed/i);
assert.equal(tip.innerHTML, '',
    'LLDP Port-ID tooltip must use safe text content');
assert.equal(fetchCount, fetchBeforePortId,
    'LLDP Port-ID tooltip must not request host history');

const portIdPanel = new MockElement('portIdPanel');

const portIdWidgetEdge = {
    data: k => k === undefined ? portIdData : portIdData[k],
    hasClass: () => false,
    source: () => fakeNode('31', 'Test Switch'),
    target: () => fakeNode('32', 'Test Endpoint'),
};

showEdgeDetail(portIdPanel, portIdWidgetEdge);

const portIdPanelText = text(portIdPanel);

assert.match(portIdPanelText, /LLDP\s*\+\s*ARP\s*\+\s*FDB/i);
assert.match(portIdPanelText, /GigabitEthernet3/);
assert.match(portIdPanelText, /shared|multiple/i);
assert.match(portIdPanelText, /one-sided|unconfirmed|not confirmed/i);
assert.doesNotMatch(
    portIdPanelText,
    /Utilization|Gb\/s|Errors\/s|link utilization|Traffic/i,
    'No invented per-link metrics in the detail panel'
);

const styles = buildCytoscapeStyle(false, 32);
const fdb = styles.find(s => s.selector === 'edge[kind = "fdb"]').style;
assert.equal(fdb['line-style'], 'dotted');
assert.equal(fdb['line-color'], '#b45309');

const portIdStyle = styles.find(
    s => s.selector === 'edge[kind = "lldp_portid"]'
)?.style;
assert.ok(portIdStyle, 'LLDP Port-ID style exists');
assert.equal(portIdStyle['line-style'], 'dashed');

function mockEdge(kind, source, target) {
    const d = { kind, source, target, trafficIn: 2000000, trafficOut: 1000000, capBps: 1000000000 };
    const classes = new Set(['dead-edge']), rendered = {};
    return {
        classes, rendered,
        data(key) { return key ? d[key] : d; },
        hasClass(c) { return classes.has(c); },
        addClass(c) { classes.add(c); },
        removeClass(c) { classes.delete(c); },
        style(k,v) { rendered[k] = v; },
        removeStyle(k) { delete rendered[k]; },
        source() { return { id() { return source; } }; },
        target() { return { id() { return target; } }; },
    };
}
function collection(items) {
    items.filter = function(fn) { return collection(Array.prototype.filter.call(this,fn)); };
    items.style = function(k,v) { this.forEach(e => e.style(k,v)); };
    return items;
}
const inferred = mockEdge('fdb','31','32');
const observed = mockEdge('lldp_portid','31','32');
const wired = mockEdge('ethernet','31','99');
inferred.classes.clear(); observed.classes.clear(); wired.classes.clear();
const cy = {
    edges: () => collection([inferred,observed,wired]),
    nodes: () => collection([
        { id: () => '31', data: k => k === 'severity' ? 5 : null },
        { id: () => '32', data: k => k === 'severity' ? 0 : null },
        { id: () => '99', data: k => k === 'severity' ? 0 : null }
    ])
};
setWeathermapMode(true);
applyTrafficHeatmap(cy);
assert.deepEqual(inferred.rendered, {}, 'unmeasured FDB edge must not receive a weathermap color');
assert.deepEqual(observed.rendered, {},
    'LLDP Port-ID edge must not receive a weathermap color');
assert.ok(wired.rendered['line-color'], 'original LLDP/Ethernet heatmap still works');
markDisasterState(cy);
assert.equal(inferred.hasClass('dead-edge'), false);
assert.equal(observed.hasClass('dead-edge'), false);
assert.equal(wired.hasClass('dead-edge'), true);

// One-sided identity evidence is not a confirmed network path.
// What-if/Root Cause must not traverse it as physical certainty.
const { reachable } =
    await import('../assets/js/modules/whatif.js');

const scenarioEdges = [
    { a: 'r', b: 's', kind: 'ethernet' },
    { a: 's', b: 'pc', kind: 'lldp_portid' },
];

const scenarioNodes = {};

for (const id of ['r', 's', 'pc']) {
    scenarioNodes[id] = {
        id: () => id,
        connectedEdges: () => scenarioEdges
            .filter(e => e.a === id || e.b === id)
            .map(e => ({
                data: key => key === 'kind' ? e.kind : undefined,
                id: () => e.a + '_' + e.b,
                source: () => ({ id: () => e.a }),
                target: () => ({ id: () => e.b }),
            })),
    };
}

const reached = reachable(
    {
        getElementById: id => scenarioNodes[id],
    },
    [scenarioNodes.r],
    null
);

assert.deepEqual(
    Object.keys(reached).sort(),
    ['r', 's'],
    'One-sided LLDP association must not establish a confirmed path'
);


// One-sided LLDP identity evidence is not a physical LAG member.
const physicalLink = {
    data: {
        id: 'physical_31_32',
        source: '31', target: '32',
        kind: 'ethernet', isLLDP: true,
        portSrc: 'Gi1/0/7',
        trafficIn: 1000, trafficOut: 2000,
    },
};

const identityLink = {
    data: {
        id: 'lldp_portid_31_32',
        source: '31', target: '32',
        kind: 'lldp_portid', isLLDP: true,
        portSrc: 'Gi1/0/8',
        trafficIn: 0, trafficOut: 0,
    },
};

annotateBundles([physicalLink, identityLink]);

assert.equal(
    identityLink.data.bundleSize,
    undefined,
    'Reconciled LLDP must not count as a LAG member'
);

assert.equal(
    physicalLink.data.bundleSize,
    undefined,
    'A corroborated association must not inflate a physical LAG'
);

// Two genuinely physical LLDP links must still form a bundle.
const physicalPair = [
    {
        data: {
            id: 'cable_a',
            source: '31', target: '32',
            kind: 'ethernet', isLLDP: true,
            portSrc: 'Gi1/0/5',
        },
    },
    {
        data: {
            id: 'cable_b',
            source: '31', target: '32',
            kind: 'ethernet', isLLDP: true,
            portSrc: 'Gi1/0/6',
        },
    },
];

annotateBundles(physicalPair);

assert.equal(
    physicalPair[0].data.bundleSize, 2,
    'Existing physical x2 trunk must remain unchanged'
);

console.log('PASS: native FDB inferred edge UI + shared-port warning + no false traffic');
