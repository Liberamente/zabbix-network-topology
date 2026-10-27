import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildDependencyEdgeElements, dependencyEdgeStyle } from '../assets/js/modules/dependency-edges.js';

// Integration smoke test: normal production render() calls have no fifth
// argument, so the overlay remains empty until a trusted scoped provider exists.
const source=readFileSync(new URL('../assets/js/modules/render-tech.js', import.meta.url),'utf8');
assert.match(source,/export function render\(wrap, nodes, edges, dataUrl, dependencies = \[\]\)/);
assert.match(source,/buildEdgeElements\(edges, nodes\)\)\s*\.concat\(buildDependencyEdgeElements\(dependencies, nodes\)\)/);
assert.match(source,/buildCytoscapeStyle\(dark, nodes.length\)\.concat\(dependencyEdgeStyle\(dark\)\)/);
assert.deepEqual(buildDependencyEdgeElements(undefined,[{id:'101'},{id:'102'}]),[]);
const synthetic=buildDependencyEdgeElements([{
    upstream:'101',downstream:'102',type:'connectivity',
    source:'manual-confirmed',confirmed:true
}],[{id:'101'},{id:'102'}]);
assert.equal(synthetic.length,1);
assert.equal(synthetic[0].data.kind,'dependency');
assert.equal(dependencyEdgeStyle()[0].style['target-arrow-shape'],'triangle');
console.log('DependencyRenderOptIn: PASS (default off, separate data & style, synthetic direction)');
