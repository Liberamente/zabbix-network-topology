// SPDX-License-Identifier: AGPL-3.0-or-later
// Synthetic pass-through UI regression: no real hosts, IPs or MACs.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

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
let tip;
const root = new MockElement('root');
globalThis.document = {
    body: {
        appendChild(e) { if (e.id === 'nt-ring-tip') tip = e; }
    },
    createElement(tag) { return new MockElement(tag); },
    createTextNode(s) { return { textContent: String(s), children: [] }; },
    getElementById(id) { return id === 'nt-root' ? root : null; },
    hidden: false
};
globalThis.window = {
    NT_CONFIG: { lang: 'en_US' },
    innerWidth: 1280,
    innerHeight: 900
};
Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { language: 'en-US' }
});
globalThis.localStorage = { getItem() { return null; } };
let fetches = 0;
globalThis.fetch = () => {
    fetches++;
    throw Error('pass-through must not fetch individual link history');
};

const { buildEdgeElements } = await import('../assets/js/modules/build-elements.js');
const { buildCytoscapeStyle } = await import('../assets/js/modules/render-tech-style.js');
const { showEdgeTip } = await import('../assets/js/modules/tooltip.js');
const { showEdgeDetail } = await import('../assets/js/modules/edge-detail.js');

const nodes = [
    { id: '100', traffic: { in: 1000000, out: 2000000 }, severity: 0 },
    { id: '101', traffic: { in: 4000000, out: 5000000 }, severity: 0 },
    { id: '102', traffic: { in: 10000000, out: 20000000 }, severity: 5 }
];
function physical(id, port) {
    return {
        id, from: '100', to: '101',
        src: ['lldp'], reporters: ['100', '101'],
        match: 'alias', confirmed: true, confidence: 100,
        ports: { '100': port, '101': port },
        port_metrics: {}
    };
}
const association = {
    id: 'pass_through_101_102',
    from: '101', to: '102',
    match: 'pass_through_inferred',
    iface: 'nt:pass_through',
    src: ['cdp', 'lldp', 'arp', 'fdb'],
    evidence: [
        'cdp_01_ipv4', 'lldp_bridge_telephone',
        'arp_ip_mac', 'fdb_two_macs_same_port',
        'existing_switch_phone_edge'
    ],
    confirmed: false, confidence: 65,
    via_switch: '100', via_switch_port: 'Gi7',
    ports: [], port_idx: [], port_metrics: [],
    fdb_shared_port: true
};
const elements = buildEdgeElements([
    physical('cable-1', 'Gi1'),
    physical('cable-2', 'Gi2'),
    association
], nodes);
assert.equal(elements.length, 3);
const link = elements.find(e => e.data.kind === 'pass_through')?.data;
assert.ok(link, 'separate pass-through kind');
assert.equal(link.source, '101');
assert.equal(link.target, '102');
assert.equal(link.isLLDP, false);
assert.equal(link.confirmed, false);
assert.equal(link.confidence, 65);
assert.equal(link.viaSwitchPort, 'Gi7');
assert.equal(link.portSrc, '');
assert.equal(link.portTgt, '');
assert.equal(link.trafficIn, 0);
assert.equal(link.trafficOut, 0);
assert.equal(link.capBps, 0);
assert.equal(link.perLink, false);
assert.equal(link.portDown, null);
assert.equal(link.tLabel, '');
assert.deepEqual(link.evidence, association.evidence);

const cables = elements.filter(e => e.data.kind !== 'pass_through');
assert.equal(cables.length, 2);
for (const cable of cables) {
    assert.equal(cable.data.bundleSize, 2,
        'two verified physical members must remain x2');
}
assert.equal(link.bundleSize, undefined,
    'pass-through association cannot become a trunk member');

const style = buildCytoscapeStyle(false, 25).find(
    s => s.selector === 'edge[kind = "pass_through"]'
);
assert.ok(style, 'dedicated visual style is mandatory');
assert.notEqual(style.style['line-style'], 'solid',
    'never display inferred transit as a verified cable');

const allText = e =>
    [e.textContent || '', ...(e.children || []).flatMap(allText)].join(' ');

showEdgeTip({ clientX: 200, clientY: 150 },
    link, 'Synthetic VoIP phone', 'Synthetic industrial device');
assert.ok(tip, 'tooltip should render');
assert.match(allText(tip), /pass-through.*inferred/i);
assert.match(allText(tip), /Gi7/);
assert.match(allText(tip), /not a confirmed physical cable/i);
assert.equal(tip.innerHTML, '');
assert.equal(fetches, 0);

const panel = new MockElement('panel');
const fakeNode = (label) => ({ data: key => key === 'label' ? label : undefined });
showEdgeDetail(panel, {
    data: key => key === undefined ? link : link[key],
    source: () => fakeNode('Synthetic VoIP phone'),
    target: () => fakeNode('Synthetic industrial device'),
    hasClass: () => false
});
assert.match(allText(panel), /pass-through.*inferred/i);
assert.match(allText(panel), /Gi7/);
assert.match(allText(panel), /not a confirmed physical cable/i);
assert.doesNotMatch(allText(panel), /Utilization|Gb\/s|Errors\/s|link utilization/i);
assert.equal(fetches, 0, 'no individual traffic history requested');

const kpi = readFileSync(
    new URL('../assets/js/modules/kpi.js', import.meta.url), 'utf8'
);
assert.match(kpi, /kind === 'pass_through'/,
    'KPI must exclude this inferred association from verified links');

console.log('PassThroughUiTest: PASS (synthetic, no fabricated cable/traffic)');
