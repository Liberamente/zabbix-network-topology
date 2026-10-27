import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildDependencyEdgeElements } from '../assets/js/modules/dependency-edges.js';

const entry = readFileSync(new URL('../assets/js/network-topology.js',import.meta.url),'utf8');
const render = readFileSync(new URL('../assets/js/modules/render-tech.js',import.meta.url),'utf8');
const backend = readFileSync(new URL('../actions/NetworkTopologyData.php',import.meta.url),'utf8');
assert.match(entry,/dependencies: Array\.isArray\(data\.dependencies\) \? data\.dependencies : \[\]/);
assert.match(entry,/return render\(wrap, p\.nodes, p\.edges, url, dependencies\)/);
assert.match(render,/window\._ntLastData\.dependencies = Array\.isArray\(data\.dependencies\)/);
assert.match(render,/cy\.edges\('\[kind = "dependency"\]'\)\.remove\(\)/);
assert.match(render,/buildDependencyEdgeElements\(\s*window\._ntLastData\.dependencies, currentNodes\)/);
assert.match(backend,/'dependencies' => \$visible_dependencies/);
const r = [{upstream:'1',downstream:'2',type:'connectivity',source:'manual-confirmed',confirmed:true}];
assert.equal(buildDependencyEdgeElements(r,[{id:'1'},{id:'2'}]).length,1);
assert.equal(buildDependencyEdgeElements(r,[{id:'1'}]).length,0);
assert.equal(buildDependencyEdgeElements([], [{id:'1'},{id:'2'}]).length,0);
console.log('DependencyReadWiring: PASS (read-only payload, refresh, current scope, empty default)');
