// SPDX-License-Identifier: AGPL-3.0-or-later
// Read-only live-pilot scope and topology safety checks (no web, no Zabbix).
// Run: node --test tests/VerifiedTrunkLivePilotTest.cjs
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const fixture = require('./fixtures/verified-trunks-synthetic.json');
const validator = require('../assets/js/nt-verified-trunk-model.js');

// Evaluate the actual ES module source with exports rewritten for Node VM;
// the project uses esbuild on the live frontend, but tests are pure Node.
const moduleSource = fs.readFileSync(path.join(__dirname,
    '../assets/js/modules/verified-trunk-live-pilot.js'), 'utf8');
const sandbox = {
    module: {exports: {}},
    window: {NTVerifiedPreview: validator}
};
vm.runInNewContext(
    moduleSource.replace(/^export /gm, '') +
    '\nmodule.exports = {' +
    'canOfferVerifiedTrunkPilot,loadVerifiedTrunkPilot,' +
    'clearVerifiedTrunkPilot,applyVerifiedTrunkPilot,' +
    'consumeVerifiedPilotPositionGuard};',
    sandbox, {filename: 'verified-trunk-live-pilot.js'}
);
const live = sandbox.module.exports;
const now = fixture.generated_at;
const cfg = {
    is_super_admin: true, wallboard: false,
    selected_hostid: '', selected_groupids: ['900']
};
const liveNodes = fixture.logical_links.flatMap(l => l.endpoints)
    .reduce((map, e) => {
        map.set(e.hostid, {
            id: e.hostid, label: e.name, type: 'switch',
            ping: e.hostid === '1001' ? 2.4 : 0.7,
            severity: e.hostid === '1001' ? 4 : 0,
            problems: e.hostid === '1001' ? 3 : 0,
            unavailable: false,
            traffic: {in: 420, out: 318}
        });
        return map;
    }, new Map());
const nodes = [...liveNodes.values()];
const rawEdges = Array.from({length: 12}, (_, i) => ({
    id: 'lldp_original_' + i, from: '1001', to: '1002',
    src: ['lldp'], ports: {}, port_idx: {}
}));

function payload() {
    const edges = fixture.logical_links.map(link => {
        const [a, b] = link.endpoints;
        const members = link.physical_member_links.map(pair => {
            const [x, y] = pair.endpoints;
            return {
                ports: {
                    [a.hostid]: a.member_port_labels[String(x.ifindex)],
                    [b.hostid]: b.member_port_labels[String(y.ifindex)]
                },
                port_idx: {
                    [a.hostid]: String(x.ifindex),
                    [b.hostid]: String(y.ifindex)
                },
                lldp_index: {
                    [a.hostid]: x.lldp_index,
                    [b.hostid]: y.lldp_index
                },
                dynamic_skew_seconds: pair.dynamic_skew_seconds,
                last_seen: Math.max(x.lldp_clock,x.fdb_clock,y.lldp_clock,y.fdb_clock)
            };
        });
        return {
            id: 'ntv_' + a.hostid + '_' + a.logical_ifindex
                + '__' + b.hostid + '_' + b.logical_ifindex,
            from: a.hostid, to: b.hostid,
            ports: {[a.hostid]: a.logical_ifname, [b.hostid]: b.logical_ifname},
            port_idx: {
                [a.hostid]: String(a.logical_ifindex),
                [b.hostid]: String(b.logical_ifindex)
            },
            nt_members: members, nt_member_count: members.length,
            confirmed: true, nt_verified_static_trunk: true,
            nt_offline_preview: true, nt_evidence_link_id: link.link_id
        };
    });
    return {
        schema: 1, source: 'zabbix-tools/offline-logical-links',
        preview_only: true, publication_allowed: false,
        live_node_status_available: false, groupid: '900',
        snapshot_generated_at: now,
        nodes: nodes.map(n => ({
            id: n.id, label: n.label, type: 'switch',
            ping: null, severity: null, problems: null,
            nt_offline_preview: true
        })),
        edges: edges,
        summary: {distinct_switches: 5, logical_links: 4,
                  physical_member_pairs: 8}
    };
}
const input = JSON.stringify(payload());
function start() {
    live.clearVerifiedTrunkPilot();
    // Drain the one-shot position-save guard of the previous test.
    live.consumeVerifiedPilotPositionGuard();
    return live.loadVerifiedTrunkPilot(input, cfg, nodes, now + 50);
}
function invalid(p, customCfg, visibility, t) {
    live.clearVerifiedTrunkPilot();
    assert.throws(() => live.loadVerifiedTrunkPilot(JSON.stringify(p),
        customCfg || cfg, visibility || nodes, t || now + 50));
}

test('disabled by default: original live nodes and physical edges intact', () => {
    live.clearVerifiedTrunkPilot();
    const r = live.applyVerifiedTrunkPilot(nodes, rawEdges, cfg, now + 50);
    assert.equal(r.active, false);
    assert.equal(r.nodes, nodes);
    assert.equal(r.edges, rawEdges);
});

test('pilot enabled only for authorized single host group', () => {
    assert.equal(live.canOfferVerifiedTrunkPilot(cfg), true);
    assert.equal(live.canOfferVerifiedTrunkPilot(
        {...cfg, is_super_admin: false}), false);
    assert.equal(live.canOfferVerifiedTrunkPilot(
        {...cfg, selected_hostid: '1001'}), false);
    assert.equal(live.canOfferVerifiedTrunkPilot(
        {...cfg, selected_groupids: ['900','901']}), false);
    assert.equal(live.canOfferVerifiedTrunkPilot(
        {...cfg, wallboard: true}), false);
});

test('five real Zabbix node statuses, exactly four trunk edges, eight members', () => {
    const summary = start();
    assert.equal(summary.nodes, 5);
    const q = live.applyVerifiedTrunkPilot(nodes, rawEdges, cfg, now + 50);
    assert.equal(q.active, true);
    assert.equal(q.nodes.length, 5);
    assert.equal(q.edges.length, 4);
    assert.equal(q.edges.reduce((s,e) => s + e.nt_member_count,0), 8);
    assert.ok(q.edges.every(e => e.nt_verified_static_trunk &&
        e.nt_offline_preview && e.confirmed));
    assert.equal(q.nodes.find(n => n.id === '1001').severity, 4);
    assert.equal(q.nodes.find(n => n.id === '1001').problems, 3);
    assert.equal(q.nodes.find(n => n.id === '1001').ping, 2.4);
    assert.equal(q.nodes.find(n => n.id === '1001').traffic.in, 420);
    assert.ok(!q.edges.some(e => String(e.id).startsWith('lldp_original')));
    assert.equal(q.edges[0].nt_members.length, 2);
    assert.equal(q.edges[0].ports['1001'], 'Trk1');
    assert.equal(live.consumeVerifiedPilotPositionGuard(), true);
    assert.equal(live.consumeVerifiedPilotPositionGuard(), false);
});

test('not allowed to load another tenant or to expose invisible nodes', () => {
    const bad = payload(); bad.groupid='901'; invalid(bad);
    invalid(payload(), {...cfg, is_super_admin:false});
    invalid(payload(), {...cfg, selected_groupids:['900','901']});
    invalid(payload(), cfg, nodes.filter(n=>n.id !== '1005'));
});

test('stale or future capture never becomes a live graph', () => {
    invalid(payload(), cfg, nodes, now+901);
    invalid(payload(), cfg, nodes, now-40);
    start();
    let q = live.applyVerifiedTrunkPilot(nodes, rawEdges, cfg, now+901);
    assert.equal(q.active, false);
    assert.equal(q.expired, true);
    assert.equal(q.edges, rawEdges);
    assert.equal(live.consumeVerifiedPilotPositionGuard(), true);
});

test('if group or permissions change, restore original backend graph', () => {
    start();
    const q = live.applyVerifiedTrunkPilot(nodes, rawEdges,
        {...cfg,selected_groupids:['901']}, now+50);
    assert.equal(q.active,false);
    assert.equal(q.expired,true);
    assert.equal(q.nodes,nodes);
    assert.equal(live.consumeVerifiedPilotPositionGuard(),true);
});

test('revoked host access cancels pilot; does not draw missing switch', () => {
    start();
    const shortNodes=nodes.filter(n=>n.id !== '1004');
    const q=live.applyVerifiedTrunkPilot(shortNodes,rawEdges,cfg,now+50);
    assert.equal(q.active,false);
    assert.equal(q.expired,true);
    assert.equal(q.nodes,shortNodes);
});

test('invalid source and fabricated network health fail closed', () => {
    const bad=payload();bad.publication_allowed=true;invalid(bad);
    const bad2=payload();bad2.nodes[0].severity=0;invalid(bad2);
    const bad3=payload();bad3.edges[0].nt_members.pop();invalid(bad3);
    const bad4=payload();bad4.edges[0].nt_verified_static_trunk=false;invalid(bad4);
    assert.throws(() => live.loadVerifiedTrunkPilot(
        'not-json',cfg,nodes,now+50));
});
