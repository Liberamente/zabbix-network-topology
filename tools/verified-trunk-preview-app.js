// SPDX-License-Identifier: AGPL-3.0-or-later
/* Browser-only, air-gapped Cytoscape viewer. Data is loaded by local File API;
 * no fetch(), API, external fonts, telemetry, localStorage or upload paths.
 * Preview shows VERIFIED TOPOLOGICAL STRUCTURE only: no live health claims.
 */
(function () {
    'use strict';
    const input = document.getElementById('report-file');
    const graph = document.getElementById('nt-preview-graph');
    const details = document.getElementById('nt-preview-detail');
    const error = document.getElementById('nt-preview-error');
    const counts = document.getElementById('nt-preview-counts');
    const fit = document.getElementById('nt-preview-fit');
    const help = document.getElementById('nt-preview-help');
    let cy = null;
    let current = null;

    function element(tag, className, value) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (value !== undefined) node.textContent = String(value);
        return node;
    }

    function clear(node) {
        node.replaceChildren();
    }

    function line(container, label, value) {
        const d = element('div', 'nt-kv');
        d.appendChild(element('span', 'nt-k', label));
        d.appendChild(element('span', 'nt-v', value));
        container.appendChild(d);
    }

    function heading(container, title, subtitle) {
        container.appendChild(element('h2', '', title));
        if (subtitle) container.appendChild(element('p', 'nt-subtitle', subtitle));
    }

    function showIdle() {
        clear(details);
        heading(details, 'Dettaglio collegamenti',
            'Seleziona un arco per le coppie di porte fisiche e gli indici LLDP.');
        const v = element('div', 'nt-note',
            'Struttura verificata offline. Ping, problemi, traffico e stato delle porte non sono dati live.');
        details.appendChild(v);
    }

    function nodeInfo(data) {
        clear(details);
        heading(details, data.label, 'Switch Zabbix · solo snapshot offline');
        line(details, 'Host ID', data.id);
        line(details, 'Stato ping', 'Non disponibile');
        line(details, 'Problemi Zabbix', 'Non disponibili');
        line(details, 'Metriche traffico', 'Non disponibili');
        details.appendChild(element('div', 'nt-note',
            'Questi campi saranno associati a Zabbix live soltanto nella futura integrazione controllata.'));
    }

    function edgeInfo(data) {
        if (!current) return;
        const a = current.nodes.find(function (n) { return n.id === data.source; });
        const b = current.nodes.find(function (n) { return n.id === data.target; });
        clear(details);
        heading(details, (a ? a.label : data.source)
            + ' ↔ ' + (b ? b.label : data.target),
            'Collegamento trunk statico HPE, validato LLDP da entrambi i lati');
        line(details, 'Trunk lato A', data.sourceTrunk + ' · ifIndex ' + data.sourceIfindex);
        line(details, 'Trunk lato B', data.targetTrunk + ' · ifIndex ' + data.targetIfindex);
        line(details, 'Coppie fisiche', data.memberCount);
        line(details, 'Fonte', 'LLDP reciproco + appartenenza ai trunk');
        line(details, 'Stato operativo', 'Non disponibile offline');
        details.appendChild(element('h3', '', 'Porte fisiche accoppiate'));
        (data.members || []).forEach(function (member, index) {
            const memberCard = element('div', 'nt-member');
            memberCard.appendChild(element('div', 'nt-member-title',
                'Coppia ' + (index + 1) + ': ' + member.portA + ' ↔ ' + member.portB));
            line(memberCard, 'ifIndex', member.ifindexA + ' ↔ ' + member.ifindexB);
            line(memberCard, 'Indice LLDP', member.nativeA + ' ↔ ' + member.nativeB);
            line(memberCard, 'Scarto dinamico', member.skew + ' s');
            memberCard.appendChild(element('div', 'nt-member-time',
                'Campione: ' + new Date(member.lastSeen * 1000).toLocaleString('it-IT')));
            details.appendChild(memberCard);
        });
        details.appendChild(element('div', 'nt-note',
            'Un solo arco logico per trunk. Le due coppie fisiche sono evidenze annidate, non due archi aggiuntivi.'));
    }

    const switchSvg = [
        '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="48" viewBox="0 0 150 48">',
        '<rect x="6" y="4" width="138" height="40" rx="8" fill="#153f62" stroke="#61b6ec" stroke-width="2"/>',
        '<rect x="14" y="11" width="23" height="25" rx="3" fill="#0e263c" stroke="#97c9ec"/>',
        '<circle cx="25" cy="24" r="3" fill="#7ab2d6"/>',
        '<g fill="#97c9ec">',
        '<rect x="46" y="13" width="11" height="7" rx="1"/>',
        '<rect x="62" y="13" width="11" height="7" rx="1"/>',
        '<rect x="78" y="13" width="11" height="7" rx="1"/>',
        '<rect x="94" y="13" width="11" height="7" rx="1"/>',
        '<rect x="110" y="13" width="11" height="7" rx="1"/>',
        '<rect x="46" y="27" width="11" height="7" rx="1"/>',
        '<rect x="62" y="27" width="11" height="7" rx="1"/>',
        '<rect x="78" y="27" width="11" height="7" rx="1"/>',
        '<rect x="94" y="27" width="11" height="7" rx="1"/>',
        '<rect x="110" y="27" width="11" height="7" rx="1"/>',
        '</g></svg>'
    ].join('');
    const switchIcon = 'data:image/svg+xml;charset=utf-8,'
        + encodeURIComponent(switchSvg);

    function draw(model) {
        if (!window.cytoscape) {
            throw new Error('Libreria Cytoscape non caricata: verifica assets/js/cytoscape.min.js.');
        }
        if (cy) {
            cy.destroy();
            cy = null;
        }
        const elements = model.nodes.map(function (node) {
            return {
                data: { id: node.id, label: node.label, type: 'switch' },
                position: model.positions[node.id]
            };
        });
        for (const e of model.edges) {
            elements.push({
                data: {
                    id: e.id, source: e.source, target: e.target,
                    label: e.label, sourceTrunk: e.sourceTrunk,
                    targetTrunk: e.targetTrunk,
                    sourceIfindex: e.sourceIfindex, targetIfindex: e.targetIfindex,
                    memberCount: e.memberCount, members: e.members
                }
            });
        }
        cy = window.cytoscape({
            container: graph,
            elements: elements,
            layout: { name: 'preset', fit: true, padding: 75 },
            minZoom: 0.25, maxZoom: 2.6,
            selectionType: 'single',
            boxSelectionEnabled: false,
            style: [
                { selector: 'node', style: {
                    'shape': 'round-rectangle',
                    'background-color': '#142c46', 'border-width': 2,
                    'border-color': '#729bbc', 'width': 194, 'height': 88,
                    'background-image': switchIcon,
                    'background-width': 122, 'background-height': 40,
                    'background-fit': 'none', 'background-position-y': '11%',
                    'background-position-x': '50%',
                    'label': 'data(label)', 'color': '#e4f2ff',
                    'font-size': 12, 'font-weight': 600,
                    'text-valign': 'bottom', 'text-halign': 'center',
                    'text-margin-y': -13, 'text-wrap': 'wrap',
                    'text-max-width': 180
                }},
                { selector: 'edge', style: {
                    'curve-style': 'unbundled-bezier',
                    'control-point-distances': 60,
                    'control-point-weights': 0.5,
                    'width': 4.5, 'line-color': '#3298d6',
                    'line-cap': 'round',
                    'label': 'data(memberCount)',
                    'font-size': 13, 'font-weight': 700,
                    'color': '#e4f2ff',
                    'text-background-color': '#123049',
                    'text-background-opacity': 1,
                    'text-background-padding': 5,
                    'source-label': 'data(sourceTrunk)',
                    'target-label': 'data(targetTrunk)',
                    'source-text-offset': 36, 'target-text-offset': 36,
                    'source-text-background-color': '#123049',
                    'target-text-background-color': '#123049',
                    'source-text-background-opacity': 1,
                    'target-text-background-opacity': 1,
                    'source-text-background-padding': 3,
                    'target-text-background-padding': 3,
                    'overlay-opacity': 0
                }},
                { selector: 'node:selected', style: {
                    'border-color': '#f0bc63',
                    'border-width': 4
                }},
                { selector: 'edge:selected', style: {
                    'line-color': '#f0bc63', 'width': 7
                }}
            ]
        });
        cy.on('tap', 'node', function (evt) {
            nodeInfo(evt.target.data());
        });
        cy.on('tap', 'edge', function (evt) {
            edgeInfo(evt.target.data());
        });
        cy.on('tap', function (evt) {
            if (evt.target === cy) showIdle();
        });
        cy.fit(cy.elements(), 75);
    }

    input.addEventListener('change', async function () {
        const file = input.files && input.files[0];
        if (!file) return;
        error.textContent = '';
        counts.textContent = '';
        fit.disabled = true;
        if (cy) { cy.destroy(); cy = null; }
        current = null;
        showIdle();
        try {
            if (file.size <= 0 || file.size > 8 * 1024 * 1024) {
                throw new Error('Il JSON deve essere un file non vuoto, massimo 8 MiB.');
            }
            const raw = await file.text();
            const payload = JSON.parse(raw);
            const model = window.NTVerifiedPreview.prepare(payload);
            draw(model);
            current = model;
            counts.textContent = model.summary.nodes + ' switch · '
                + model.summary.links + ' trunk · '
                + model.summary.physicalPairs + ' coppie fisiche';
            help.textContent = 'Gruppo ' + model.summary.groupid
                + ' · snapshot del ' + new Date(model.summary.captured * 1000)
                    .toLocaleString('it-IT')
                + ' · nessuna connessione Zabbix live';
            fit.disabled = false;
        } catch (ex) {
            error.textContent = 'Preview rifiutata: '
                + (ex && ex.message ? ex.message : 'file non valido');
        }
    });

    fit.addEventListener('click', function () {
        if (cy) cy.fit(cy.elements(), 75);
    });
    document.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape' && cy) {
            cy.elements().unselect();
            showIdle();
        }
    });
    showIdle();
})();
