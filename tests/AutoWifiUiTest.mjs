// Standalone regression test: node --experimental-default-type=module tests/AutoWifiUiTest.mjs
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
    appendChild(n) { this.children.push(n); return n; }
    replaceChildren(...nodes) {
        this.children = nodes;
        this.textContent = '';
        this.innerHTML = '';
    }
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
// Do not silently make history HTTP calls from a radio tooltip.
let fetchCount = 0;
globalThis.fetch = () => { fetchCount++; throw Error('unexpected fetch for Wi-Fi association'); };

const { showEdgeTip } = await import('../assets/js/modules/tooltip.js');
const { showEdgeDetail } = await import('../assets/js/modules/edge-detail.js');
const { applyTrafficHeatmap, markDisasterState, setWeathermapMode } = await import('../assets/js/modules/traffic.js');
const { buildCytoscapeStyle } = await import('../assets/js/modules/render-tech-style.js');
const { buildEdgeElements } = await import('../assets/js/modules/build-elements.js');

const apiEdge = { id: 'wifi_21_22', from: '21', to: '22', match: 'wifi', src: ['wifi'], signal_dbm: -71, port_idx: { '21': '3' }, confidence: 75 };
const nodes = [{ id: '21' }, { id: '22' }];
const data = buildEdgeElements([apiEdge], nodes)[0].data;
assert.equal(data.kind, 'wifi');
assert.equal(data.signalDbm, -71);
assert.equal(data.radioIfIndex, '3');
assert.equal(data.trafficIn, 0);
assert.equal(data.trafficOut, 0);

showEdgeTip({ clientX: 200, clientY: 150 }, data, 'Access Point', '<client>');
const visibleText = (e) =>
    [e.textContent || '', ...(e.children || []).flatMap(visibleText)].join(' ');
const tipText = visibleText(tip);
assert.match(tipText, /-71 dBm/);
assert.match(tipText, /ifIndex 3/);
assert.match(tipText, /SNMP MikroTik/);
assert.match(tipText, /<client>/);
assert.equal(tip.innerHTML, '', 'Wi-Fi tooltip must not use innerHTML');
assert.doesNotMatch(tipText, /Loading history|Gb\/s|Mb\/s|K[bB]\/s/);
assert.equal(fetchCount, 0);

const panel = new MockElement('panel');
const fakeNode = (id, label) => ({ id: () => id, data: k => ({ label })[k] });
const fakeEdge = {
    data: k => k === undefined ? data : data[k],
    hasClass: () => false,
    source: () => fakeNode('21', 'Test AP'),
    target: () => fakeNode('22', 'Client'),
};
showEdgeDetail(panel, fakeEdge);

const panelText = visibleText(panel);
assert.match(panelText, /-71 dBm/);
assert.match(panelText, /ifIndex 3/);
assert.match(panelText, /SNMP MikroTik/);
assert.doesNotMatch(panelText, /1 Gb\/s|Utilization|Traffic|Ports down|Errors\/s/);

const styles = buildCytoscapeStyle(false, 32);
assert.equal(styles.find(s => s.selector === 'edge[kind = "wifi"]').style['line-color'], '#0891b2');
assert.equal(styles.find(s => s.selector === 'edge[kind = "wifi"]').style['line-style'], 'dashed');

function mockEdge(kind, source, target) {
    const d = { kind, source, target, trafficIn: 2000000, trafficOut: 1000000, capBps: 1000000000 };
    const classes = new Set(['dead-edge']);
    const styles = {};
    return {
        d, classes, styles,
        data(key) { return key ? d[key] : d; },
        hasClass(c) { return classes.has(c); },
        addClass(c) { classes.add(c); },
        removeClass(c) { classes.delete(c); },
        style(k, v) { styles[k] = v; },
        removeStyle(k) { delete styles[k]; },
        source() { return { id() { return source; } }; },
        target() { return { id() { return target; } }; },
    };
}
function collection(items) {
    items.filter = function(fn) { return collection(Array.prototype.filter.call(this, fn)); };
    items.style = function(k, v) { this.forEach(e => e.style(k, v)); };
    return items;
}
const wifi = mockEdge('wifi', '21', '22');
const wired = mockEdge('ethernet', '21', '99');
wifi.classes.clear(); wired.classes.clear();
const cy = {
    edges: () => collection([wifi, wired]),
    nodes: () => collection([
        { id: () => '21', data: k => k === 'severity' ? 5 : null },
        { id: () => '22', data: k => k === 'severity' ? 0 : null },
        { id: () => '99', data: k => k === 'severity' ? 0 : null },
    ]),
};
setWeathermapMode(true);
applyTrafficHeatmap(cy);
assert.deepEqual(wifi.styles, {}, 'no heatmap inline overrides for Wi-Fi');
assert.ok(wired.styles['line-color'], 'wired heatmap remains enabled');
markDisasterState(cy);
assert.equal(wifi.hasClass('dead-edge'), false, 'Wi-Fi style not overridden by host-severity class');
assert.equal(wired.hasClass('dead-edge'), true, 'wired dead-edge warnings remain enabled');

console.log('PASS: Auto Wi-Fi UI (builder, tooltip, panel, styling, heatmap, severity, existing wired link)');
