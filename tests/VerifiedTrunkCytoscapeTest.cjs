// SPDX-License-Identifier: AGPL-3.0-or-later
// Run: node --test tests/VerifiedTrunkCytoscapeTest.cjs
// Offline model tests: NO browser, service, customer data, Zabbix or network.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fixture = require('./fixtures/verified-trunks-synthetic.json');
const { prepare } = require('../assets/js/nt-verified-trunk-model.js');

function makePayload() {
    const hosts = new Map();
    const edges = [];
    for (const link of fixture.logical_links) {
        const [a, b] = link.endpoints;
        for (const end of [a, b]) {
            hosts.set(end.hostid, {
                id: end.hostid, label: end.name, type: 'switch',
                host: '', ping: null, severity: null, problems: null,
                nt_offline_preview: true
            });
        }
        const members = link.physical_member_links.map(function (pair) {
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
                last_seen: Math.max(
                    x.lldp_clock, y.lldp_clock, x.fdb_clock, y.fdb_clock
                )
            };
        });
        edges.push({
            id: 'ntv_' + a.hostid + '_' + a.logical_ifindex
                + '__' + b.hostid + '_' + b.logical_ifindex,
            from: a.hostid, to: b.hostid,
            iface: 'nt:verified-static-trunk',
            src: ['lldp'], reporters: [a.hostid, b.hostid],
            confirmed: true, match: 'exact', port_match: 'exact',
            nt_verified_static_trunk: true,
            nt_offline_preview: true,
            ports: {
                [a.hostid]: a.logical_ifname,
                [b.hostid]: b.logical_ifname
            },
            port_idx: {
                [a.hostid]: String(a.logical_ifindex),
                [b.hostid]: String(b.logical_ifindex)
            },
            nt_members: members,
            nt_member_count: members.length,
            nt_evidence_link_id: link.link_id
        });
    }
    return {
        schema: 1, source: 'zabbix-tools/offline-logical-links',
        preview_only: true, publication_allowed: false,
        live_node_status_available: false,
        snapshot_generated_at: fixture.generated_at,
        groupid: fixture.groupids[0],
        nodes: [...hosts.values()], edges: edges,
        summary: {
            logical_links: edges.length, physical_member_pairs: 8,
            distinct_switches: hosts.size
        }
    };
}

function reject(modify) {
    const payload = makePayload();
    modify(payload);
    assert.throws(() => prepare(payload), /Offline topology rejected/);
}

test('five synthetic switches, exactly four trunk arcs, eight physical pairs', () => {
    const payload = makePayload();
    const model = prepare(payload);
    assert.equal(model.summary.nodes, 5);
    assert.equal(model.summary.links, 4);
    assert.equal(model.summary.physicalPairs, 8);
    assert.equal(model.edges.length, 4);
    assert.equal(model.edges.reduce((sum, edge) => sum + edge.members.length, 0), 8);
    assert.equal(model.summary.publicationAllowed, false);
    assert.equal(Object.keys(model.positions).length, 5);
    assert.equal(new Set(model.edges.map(e => e.id)).size, 4);
    assert.equal(model.edges[0].members.length, 2);
    assert.equal(model.edges[0].members[0].portA, '23');
    assert.equal(model.edges[0].members[0].portB, '45');
    assert.equal(model.nodes.every(x => x.label.startsWith('SW-')), true);
});

test('fixture preview has only actual switches and fixed layout', () => {
    const p = makePayload(), one = prepare(p), two = prepare(p);
    assert.deepEqual(one, two);
    assert.equal(new Set(one.edges.flatMap(x => [x.source, x.target])).size, 5);
    const xs = one.nodes.map(x => one.positions[x.id].x);
    assert.equal(new Set(xs).size, 5);
    assert.ok(xs.every(x => Number.isFinite(x)));
});

test('unverified endpoints from FDB/ARP are never imported', () => {
    const p = makePayload();
    p.results = [{classification: 'candidate_unverified', ip: '198.51.100.99'}];
    const model = prepare(p);
    assert.equal(JSON.stringify(model).includes('198.51.100.99'), false);
});

test('wrong publication or live status flags reject', () => {
    reject(p => {p.publication_allowed = true;});
    reject(p => {p.preview_only = false;});
    reject(p => {p.live_node_status_available = true;});
    reject(p => {p.source = 'unknown-source';});
});

test('unscoped nodes and duplicate switches reject', () => {
    reject(p => {p.nodes[0].severity = 0;});
    reject(p => {p.nodes[0].ping = 1;});
    reject(p => {p.nodes[0].problems = 0;});
    reject(p => {p.nodes.push({...p.nodes[0]});});
    reject(p => {p.edges[0].from = '9999';});
    reject(p => {p.groupid = 'Customer-X';});
});

test('no duplicate or unverified logical arcs', () => {
    reject(p => {p.edges.push({...p.edges[0]});});
    reject(p => {p.edges[0].nt_verified_static_trunk = false;});
    reject(p => {p.edges[0].confirmed = false;});
    reject(p => {p.edges[0].from = p.edges[0].to;});
    reject(p => {p.edges[0].nt_evidence_link_id = 'manual';});
    reject(p => {p.edges[0].id = 'e999';});
    reject(p => {p.edges[0].ports['1001'] = 'Ethernet23';});
});

test('physical members must remain complete and unique', () => {
    reject(p => {p.edges[0].nt_members.pop();});
    reject(p => {p.edges[0].nt_member_count = 1;});
    reject(p => {p.edges[0].nt_members[1].port_idx = {...p.edges[0].nt_members[0].port_idx};});
    reject(p => {p.edges[0].nt_members[0].ports['1001'] = null;});
    reject(p => {p.edges[0].nt_members[0].dynamic_skew_seconds = 121;});
    reject(p => {p.edges[0].nt_members[0].last_seen = p.snapshot_generated_at + 1;});
    reject(p => {p.summary.physical_member_pairs = 16;});
});

test('BFS positions support branches and remain finite', () => {
    const p = makePayload();
    // Node 1005 attaches to 1004 in baseline. Nodes on all branches
    // always receive finite coordinates and graph count remains correct.
    const model = prepare(p);
    assert.equal(Object.values(model.positions).every(
        xy => Number.isFinite(xy.x) && Number.isFinite(xy.y)), true);
    assert.ok(Object.keys(model.positions).length >= model.edges.length + 1);
});
