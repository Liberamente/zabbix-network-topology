import assert from 'node:assert/strict';
import { buildDependencyEdgeElements, dependencyEdgeStyle } from '../assets/js/modules/dependency-edges.js';

const nodes = [{id:'100'}, {id:'200'}, {id:'300'}];
const relation = (a,b,extra={}) => ({
  upstream:a,downstream:b,type:'connectivity',
  source:'manual-confirmed',confirmed:true,...extra
});
const input = [
  relation('100','200'), relation('100','200'), relation('200','100'),
  relation('100','999'), relation('100','100'),
  relation('100','300',{source:'fdb'}),
  relation('100','300',{confirmed:false}),
  relation('200','300',{type:'physical'})
];
const output = buildDependencyEdgeElements(input,nodes);
assert.equal(output.length,2,'visible, confirmed, directed, distinct dependencies only');
assert.equal(output[0].data.source,'100');
assert.equal(output[0].data.target,'200');
assert.equal(output[1].data.source,'200');
assert.equal(output[1].data.target,'100');
for (const edge of output) {
  assert.equal(edge.data.kind,'dependency');
  assert.equal(edge.data.isLLDP,false);
  assert.equal(edge.data.perLink,false);
  assert.equal(edge.data.portSrc,null);
  assert.equal(edge.data.tLabel,'');
}
assert.equal(buildDependencyEdgeElements(input,[nodes[0]]).length,0);
assert.equal(buildDependencyEdgeElements(null,nodes).length,0);
const style=dependencyEdgeStyle();
assert.equal(style[0].selector,'edge[kind = "dependency"]');
assert.equal(style[0].style['target-arrow-shape'],'triangle');
assert.equal(style[0].style.label,'');
console.log('DependencyEdges: PASS (scoped display, directed, dedupe, evidence, no cable metrics)');
