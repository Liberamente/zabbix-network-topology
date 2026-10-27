// Directed dependency display adapter: separate from physical topology edges.
// Inputs must already be authorized, scoped and validated server-side.
export function buildDependencyEdgeElements(relations, visibleNodes) {
    if (!Array.isArray(relations) || !Array.isArray(visibleNodes)) return [];
    const known = new Set(visibleNodes.map(n => String(n.id)));
    const seen = new Set();
    const result = [];
    for (const r of relations) {
        if (!r || typeof r !== 'object') continue;
        const upstream = String(r.upstream ?? '');
        const downstream = String(r.downstream ?? '');
        if (!known.has(upstream) || !known.has(downstream)
                || upstream === downstream || !upstream || !downstream
                || r.type !== 'connectivity' || r.source !== 'manual-confirmed'
                || r.confirmed !== true) continue;
        const key = JSON.stringify([upstream, downstream]);
        if (seen.has(key)) continue;
        seen.add(key);
        result.push({
            data: {
                id: 'ntdep_' + encodeURIComponent(upstream) + '_' + encodeURIComponent(downstream),
                source: upstream,
                target: downstream,
                kind: 'dependency',
                dependencyType: 'connectivity',
                dependencySource: 'manual-confirmed',
                confirmed: true,
                isDependency: true,
                isLLDP: false,
                perLink: false,
                src: ['manual-confirmed'],
                tLabel: '',
                portSrc: null,
                portTgt: null
            }
        });
    }
    return result;
}

// Install AFTER the standard Cytoscape style, so dependency arrows cannot
// inherit a physical cable's line color, traffic labels or port decorations.
export function dependencyEdgeStyle(dark = false) {
    return [{
        selector: 'edge[kind = "dependency"]',
        style: {
            'width': 2,
            'line-color': dark ? '#f0abfc' : '#a21caf',
            'line-style': 'dashed',
            'line-dash-pattern': [2, 5],
            'target-arrow-shape': 'triangle',
            'target-arrow-color': dark ? '#f0abfc' : '#a21caf',
            'target-arrow-fill': 'filled',
            'arrow-scale': 0.8,
            'curve-style': 'bezier',
            'opacity': 0.8,
            'label': '',
            'source-label': '',
            'target-label': ''
        }
    }];
}
