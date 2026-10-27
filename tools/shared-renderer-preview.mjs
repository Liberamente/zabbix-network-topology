// Offline shared-renderer preview. Synthetic fixtures only; never query Zabbix.
import { buildEdgeElements } from '../assets/js/modules/build-elements.js';
import { buildCytoscapeStyle } from '../assets/js/modules/render-tech-style.js';
import { buildDependencyEdgeElements, dependencyEdgeStyle } from '../assets/js/modules/dependency-edges.js';

const nodes = [
  {id:'sw-a', label:'LAB-SW-A', traffic:{in:0,out:0}, severity:0},
  {id:'sw-b', label:'LAB-SW-B', traffic:{in:0,out:0}, severity:0},
  {id:'pc', label:'LAB-PC', traffic:{in:0,out:0}, severity:0},
  {id:'phone', label:'LAB-PHONE', traffic:{in:0,out:0}, severity:0},
  {id:'ap', label:'LAB-AP', traffic:{in:0,out:0}, severity:0},
  {id:'client', label:'LAB-WIFI', traffic:{in:0,out:0}, severity:0}
];
const positions = {
  'sw-a':{x:160,y:210}, 'sw-b':{x:435,y:210},
  pc:{x:160,y:430}, phone:{x:435,y:430},
  ap:{x:700,y:210}, client:{x:700,y:430}
};
const physical = (id,p1,p2) => ({
  id, from:'sw-a', to:'sw-b', match:'exact', src:['lldp'],
  reporters:['sw-a','sw-b'], confirmed:true, confidence:100,
  ports:{'sw-a':p1,'sw-b':p2}, port_metrics:{}
});
const edges = [
  physical('cable-a','Gi1/0/1','Gi1/0/1'),
  physical('cable-b','Gi1/0/2','Gi1/0/2'),
  {id:'fdb-pc',from:'sw-a',to:'pc',match:'fdb',src:['fdb'],confirmed:false,
   confidence:55,ports:{'sw-a':'Gi1/0/8'},fdb_shared_port:true},
  {id:'lldp-portid-phone',from:'sw-b',to:'phone',match:'lldp_portid',
   src:['lldp'],reporters:['sw-b'],confirmed:false,confidence:70,
   evidence:['lldp_portid','arp','fdb'],ports:{'sw-b':'Gi1/0/7'},
   fdb_shared_port:true},
  {id:'lldp-ap',from:'sw-b',to:'ap',match:'exact',src:['lldp'],
   reporters:['sw-b','ap'],confirmed:true,confidence:100,
   ports:{'sw-b':'Gi1/0/24',ap:'eth0'},port_metrics:{}},
  {id:'wifi-client',from:'ap',to:'client',match:'wifi',src:['wifi'],
   confirmed:false,confidence:90,signal_dbm:-61,port_idx:{ap:'3'}}
];
const physicalEdges = buildEdgeElements(edges,nodes);
// Synthetic, server-validated example: operational dependency is NOT a cable.
const dependencyEdges = buildDependencyEdgeElements([
  {upstream:'sw-b',downstream:'pc',type:'connectivity',source:'manual-confirmed',confirmed:true}
],nodes);
const graphEdges = physicalEdges.concat(dependencyEdges);
const typeCount = kind => graphEdges.filter(e => e.data.kind === kind).length;
const bundle = graphEdges.filter(e =>
  e.data.source === 'sw-a' && e.data.target === 'sw-b' &&
  e.data.isLLDP === true && e.data.kind !== 'lldp_portid');
if (typeCount('lldp_portid') !== 1 || typeCount('fdb') !== 1 ||
    typeCount('wifi') !== 1 || bundle.length !== 2 ||
    bundle.some(e => e.data.bundleSize !== 2)) {
  throw new Error('Fixture failed invariants: source module output changed');
}
const details = document.querySelector('#details');
const safe = v => v === undefined || v === null || v === '' ? 'Non disponibile' : String(v);
function show(d) {
  details.replaceChildren();
  if (d.kind === 'dependency') {
    const title=document.createElement('p');
    const strong=document.createElement('strong');
    strong.textContent='Dipendenza direzionale: ';
    title.append(strong,document.createTextNode(
      String(d.source) + ' → ' + String(d.target)));
    details.appendChild(title);
    const info=document.createElement('p');
    info.textContent='Conferma manuale. Relazione operativa, non collegamento fisico; nessuna porta o metrica per-link dichiarata.';
    details.appendChild(info);
    return;
  }
  for (const [name,value] of [
    ['Tipo', d.kind || 'LLDP/CDP fisico'],
    ['Origine', (d.src || []).join(', ')],
    ['Confermato', d.confirmed === true ? 'Sì' : 'No (inferito)'],
    ['Confidenza', d.confidence === null || d.confidence === undefined ? null : d.confidence + '%'],
    ['Porta sorgente', d.portSrc],['Porta destinazione',d.portTgt],
    ['Evidenze',Array.isArray(d.evidence) ? d.evidence.join(', ') : null],
    ['Porta FDB condivisa',d.fdbSharedPort === true ? 'Sì' : 'No'],
    ['Segnale Wi-Fi (dBm)',d.signalDbm],['Membri fisici',d.bundleSize],
    ['Traffico per collegamento misurato', d.perLink === true ? 'Sì' : 'No']
  ]) {
    const p=document.createElement('p');
    const b=document.createElement('strong'); b.textContent=name+': ';
    p.append(b,document.createTextNode(safe(value)));details.appendChild(p);
  }
}
const elements = [
  ...nodes.map(n=>({data:{id:n.id,label:n.label,type:'switch',isGroup:false},
                     position:positions[n.id]})),
  ...graphEdges
];
const cy=window.cytoscape({
 container:document.getElementById('graph'),
 elements,
 layout:{name:'preset',fit:true,padding:55},
 style:[
  ...buildCytoscapeStyle(false,nodes.length),
  ...dependencyEdgeStyle(false),
  // Preview-only node fallback: production icons depend on Zabbix metadata.
  {selector:'node',style:{'background-color':'#e2e8f0','background-opacity':1,
    'border-width':2,'border-color':'#64748b','shape':'round-rectangle',
    'width':108,'height':64,'background-image':'none','label':'data(label)',
    'color':'#0f172a','font-size':12,'text-valign':'center',
    'text-margin-y':0,'text-background-opacity':0}}
 ]
});
cy.on('tap','edge',evt=>show(evt.target.data()));
document.getElementById('fit').addEventListener('click',()=>cy.fit(undefined,55));
// Offline interaction: simulate scope loss and returning dependencies without
// touching the physical cables or relying on Zabbix. Not production toggles.
let dependenciesVisible = true;
let inRestrictedScope = false;
function reconcileDependencyPreview() {
  cy.batch(() => {
    cy.edges('[kind = "dependency"]').remove();
    if (dependenciesVisible && !inRestrictedScope) cy.add(dependencyEdges);
  });
  const depButton = document.getElementById('dependencies');
  depButton.setAttribute('aria-pressed', String(dependenciesVisible));
  depButton.textContent = dependenciesVisible
    ? 'Dipendenze: visibili' : 'Dipendenze: nascoste';
  const scopeButton = document.getElementById('scope');
  scopeButton.setAttribute('aria-pressed', String(inRestrictedScope));
  scopeButton.textContent = inRestrictedScope
    ? 'Ambito ristretto (relazione assente)' : 'Simula cambio ambito';
}
document.getElementById('dependencies').addEventListener('click', () => {
  dependenciesVisible = !dependenciesVisible;
  reconcileDependencyPreview();
});
document.getElementById('scope').addEventListener('click', () => {
  inRestrictedScope = !inRestrictedScope;
  reconcileDependencyPreview();
});

document.getElementById('summary').textContent =
  'Fixture sintetica • 6 nodi • 2 cavi LLDP paralleli (x2) • 1 LLDP AP • '+
  '1 FDB • 1 LLDP Port-ID • 1 Wi-Fi • 1 dipendenza diretta (non fisica). Clicca un arco.';
show(graphEdges.find(e=>e.data.kind==='lldp_portid').data);
