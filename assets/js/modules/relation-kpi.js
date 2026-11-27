// SPDX-License-Identifier: AGPL-3.0-or-later
// Count raw scoped relations, not the rendered (possibly bundled) graph.
export function countRelations(nodes, edges) {
    const ids = new Set(nodes.filter(n => !n._isGhost && !n._isInternet)
        .map(n => String(n.id)));
    const out = { total: 0, verified: 0, unconfirmed: 0, manual: 0, stale: 0 };
    for (const e of edges) {
        if (!ids.has(String(e.from)) || !ids.has(String(e.to))
            || e._isGhostEdge || e._isInternetEdge || e._isBundleEdge) continue;
        out.total++;
        const src = Array.isArray(e.src) ? e.src : [];
        if (String(e.id || '').startsWith('ml_')) out.manual++;
        else if (e.stale === true) out.stale++;
        else if (e.confirmed === true && src.some(s => s === 'lldp' || s === 'cdp')
            && !src.some(s => ['fdb', 'arp', 'wifi'].includes(s))
            && !['lldp_portid', 'fdb', 'wifi', 'pass_through', 'pass_through_inferred'].includes(e.match)
            && !['lldp_portid', 'fdb', 'wifi', 'pass_through', 'pass_through_inferred'].includes(e.kind)) out.verified++;
        else out.unconfirmed++;
    }
    return out;
}
