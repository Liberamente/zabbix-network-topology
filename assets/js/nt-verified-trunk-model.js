// SPDX-License-Identifier: AGPL-3.0-or-later
/* global BigInt, module, globalThis */
/* Standalone offline Cytoscape preview model. No API, network or storage calls.
 * Never synthesize current ping, traffic, alarms, connectivity or availability.
 * Compatible with plain file:// <script> and Node's built-in test runner.
 */
(function (root) {
    'use strict';

    function fail(reason) {
        throw new Error('Offline topology rejected: ' + reason);
    }

    function digits(value) {
        return typeof value === 'string'
            && /^[1-9][0-9]{0,15}$/.test(value);
    }

    function text(value, limit) {
        return typeof value === 'string'
            && value.trim().length > 0 && value.length <= limit;
    }

    function canonicalHostPair(a, b) {
        // Match VerifiedTrunkAdapter.php's strcmp() canonical ordering,
        // including host IDs of different decimal string lengths.
        return [a, b].sort(function (x, y) {
            return x < y ? -1 : (x > y ? 1 : 0);
        });
    }

    function prepare(payload) {
        if (!payload || typeof payload !== 'object'
            || payload.schema !== 1
            || payload.preview_only !== true
            || payload.publication_allowed !== false
            || payload.live_node_status_available !== false
            || payload.source !== 'zabbix-tools/offline-logical-links') {
            fail('not an explicitly nonpublishable adapter payload');
        }
        if (!digits(payload.groupid)
            || !Number.isSafeInteger(payload.snapshot_generated_at)
            || payload.snapshot_generated_at <= 0
            || !Array.isArray(payload.nodes)
            || !Array.isArray(payload.edges)
            || payload.nodes.length < 2 || payload.nodes.length > 50
            || payload.edges.length < 1 || payload.edges.length > 100) {
            fail('invalid scope, graph size or capture clock');
        }
        const seen = new Set();
        const nodes = new Map();
        for (const node of payload.nodes) {
            if (!node || !digits(node.id)
                || !text(node.label, 256)
                || node.type !== 'switch'
                || node.nt_offline_preview !== true
                || node.severity !== null || node.problems !== null
                || node.ping !== null
                || nodes.has(node.id)) {
                fail('unknown or duplicated switch node, or fabricated live status');
            }
            nodes.set(node.id, { id: node.id, label: node.label });
        }
        const aggregateIfs = new Set();
        const memberPorts = new Set();
        let memberTotal = 0;
        const edges = [];
        for (const edge of payload.edges) {
            if (!edge || !text(edge.id, 128) || seen.has(edge.id)
                || edge.nt_verified_static_trunk !== true
                || edge.nt_offline_preview !== true
                || edge.confirmed !== true || !digits(edge.from)
                || !digits(edge.to) || edge.from === edge.to
                || !nodes.has(edge.from) || !nodes.has(edge.to)
                || !edge.ports || !edge.port_idx
                || !Array.isArray(edge.nt_members)
                || !Number.isSafeInteger(edge.nt_member_count)
                || edge.nt_member_count < 2 || edge.nt_member_count > 64
                || edge.nt_members.length !== edge.nt_member_count) {
                fail('unverified, duplicate, out-of-scope or incomplete logical edge');
            }
            seen.add(edge.id);
            const pair = canonicalHostPair(edge.from, edge.to);
            if (pair[0] !== edge.from || pair[1] !== edge.to) {
                fail('nondeterministic logical direction');
            }
            const names = edge.ports;
            const agg = edge.port_idx;
            if (![edge.from, edge.to].every(function (hid) {
                return typeof names[hid] === 'string'
                    && /^Trk[1-9][0-9]*$/.test(names[hid])
                    && digits(String(agg[hid]));
            })) {
                fail('invalid trunk port labels or IF-MIB indices');
            }
            const identity = 'trunk:' + edge.from + '/' + agg[edge.from]
                + '--' + edge.to + '/' + agg[edge.to];
            if (edge.nt_evidence_link_id !== identity
                || edge.id !== 'ntv_' + edge.from + '_' + agg[edge.from]
                    + '__' + edge.to + '_' + agg[edge.to]) {
                fail('logical edge ID does not match evidence');
            }
            for (const hid of [edge.from, edge.to]) {
                const key = hid + '/' + agg[hid];
                if (aggregateIfs.has(key)) {
                    fail('one trunk belongs to multiple links');
                }
                aggregateIfs.add(key);
            }
            const members = [];
            for (const member of edge.nt_members) {
                if (!member || !member.ports || !member.port_idx
                    || !member.lldp_index
                    || !Number.isSafeInteger(member.dynamic_skew_seconds)
                    || member.dynamic_skew_seconds < 0
                    || member.dynamic_skew_seconds > 120
                    || !Number.isSafeInteger(member.last_seen)
                    || member.last_seen <= 0
                    || member.last_seen > payload.snapshot_generated_at) {
                    fail('unverified, future or skewed physical member');
                }
                for (const hid of [edge.from, edge.to]) {
                    const index = String(member.port_idx[hid] || '');
                    const port = String(member.ports[hid] || '');
                    if (!digits(index) || !digits(port)
                        || !/^[0-9]+\.[1-9][0-9]*\.[0-9]+$/.test(
                            String(member.lldp_index[hid] || ''))) {
                        fail('physical port has no trusted mapping');
                    }
                    const indexParts = String(member.lldp_index[hid]).split('.');
                    if (Number(indexParts[1]) <= 0) {
                        fail('invalid LLDP local port');
                    }
                    const k = hid + '/' + index;
                    if (memberPorts.has(k)) {
                        fail('reused physical member');
                    }
                    memberPorts.add(k);
                }
                members.push({
                    portA: member.ports[edge.from],
                    portB: member.ports[edge.to],
                    ifindexA: String(member.port_idx[edge.from]),
                    ifindexB: String(member.port_idx[edge.to]),
                    nativeA: member.lldp_index[edge.from],
                    nativeB: member.lldp_index[edge.to],
                    skew: member.dynamic_skew_seconds,
                    lastSeen: member.last_seen
                });
            }
            memberTotal += members.length;
            edges.push({
                id: edge.id,
                source: edge.from,
                target: edge.to,
                sourceTrunk: names[edge.from],
                targetTrunk: names[edge.to],
                sourceIfindex: String(agg[edge.from]),
                targetIfindex: String(agg[edge.to]),
                memberCount: members.length,
                members: members,
                evidence: 'LLDP reciproco + trunk statico HPE',
                label: names[edge.from] + ' ↔ ' + names[edge.to]
                    + ' · ' + members.length + ' porte'
            });
        }
        if (!payload.summary
            || payload.summary.distinct_switches !== nodes.size
            || payload.summary.logical_links !== edges.length
            || payload.summary.physical_member_pairs !== memberTotal) {
            fail('summary does not match underlying network');
        }

        const sortedNodes = [...nodes.values()].sort(function (a, b) {
            const ax = BigInt(a.id), bx = BigInt(b.id);
            return ax < bx ? -1 : (ax > bx ? 1 : 0);
        });
        edges.sort(function (a, b) {
            return a.id.localeCompare(b.id);
        });
        const positions = layoutPositions(sortedNodes, edges);
        return {
            nodes: sortedNodes,
            edges: edges,
            positions: positions,
            summary: {
                nodes: sortedNodes.length,
                links: edges.length,
                physicalPairs: memberTotal,
                groupid: payload.groupid,
                captured: payload.snapshot_generated_at,
                publicationAllowed: false
            }
        };
    }

    // Deterministic BFS placement. Handles chains and branches; no server,
    // fixed customer labels or guessed geographical placement.
    function layoutPositions(nodes, edges) {
        const neighbor = new Map(nodes.map(function (n) {
            return [n.id, new Set()];
        }));
        for (const edge of edges) {
            neighbor.get(edge.source).add(edge.target);
            neighbor.get(edge.target).add(edge.source);
        }
        const visited = new Set(), level = new Map(), groups = new Map();
        while (visited.size < nodes.length) {
            const remaining = nodes.filter(function (n) {
                return !visited.has(n.id);
            });
            remaining.sort(function (a, b) {
                const deg = neighbor.get(a.id).size - neighbor.get(b.id).size;
                return deg || (BigInt(a.id) < BigInt(b.id) ? -1 : 1);
            });
            const root = remaining[0].id;
            const q = [[root, 0]];
            visited.add(root);
            const offset = groups.size ? Math.max(...groups.keys()) + 2 : 0;
            while (q.length) {
                const entry = q.shift();
                const node = entry[0], d = entry[1] + offset;
                level.set(node, d);
                if (!groups.has(d)) groups.set(d, []);
                groups.get(d).push(node);
                const neighbors = [...neighbor.get(node)].sort(function (a, b) {
                    return BigInt(a) < BigInt(b) ? -1 : 1;
                });
                for (const next of neighbors) {
                    if (!visited.has(next)) {
                        visited.add(next);
                        q.push([next, entry[1] + 1]);
                    }
                }
            }
        }
        const positions = {};
        for (const [depth, list] of groups) {
            list.forEach(function (id, i) {
                positions[id] = {
                    x: depth * 310,
                    y: (i - (list.length - 1) / 2) * 175
                };
            });
        }
        return positions;
    }

    const api = Object.freeze({ prepare: prepare, layoutPositions: layoutPositions });
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.NTVerifiedPreview = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
