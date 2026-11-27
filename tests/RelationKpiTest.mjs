// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict';
import { countRelations } from '../assets/js/modules/relation-kpi.js';
const nodes = [{id:'1'}, {id:'2'}, {id:'3'}, {id:'ghost',_isGhost:true}];
const link = {from:'1',to:'2',src:['lldp'],confirmed:true};
const edges = [link, {...link, id:'parallel'}, {...link,src:['cdp']},
    {...link,src:['fdb']}, {...link,match:'lldp_portid'},
    {...link,src:['lldp','fdb','arp'],match:'pass_through_inferred'},
    {...link,src:['wifi']}, {...link,src:['tag']}, {...link,confirmed:false},
    {...link,id:'ml_1_2'}, {...link,stale:true},
    {...link,_isBundleEdge:true}, {...link,to:'ghost'}, {...link,to:'9'},
    {...link,to:'3'}];
assert.deepEqual(countRelations(nodes,edges), {total:12,verified:4,unconfirmed:6,manual:1,stale:1});
assert.deepEqual(countRelations(nodes.slice(0,2),edges), {total:11,verified:3,unconfirmed:6,manual:1,stale:1});
assert.deepEqual(countRelations([],edges), {total:0,verified:0,unconfirmed:0,manual:0,stale:0});
console.log('Relation KPI scope and provenance tests PASS');
