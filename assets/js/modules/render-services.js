// render-services.js — Dependencies-Tab: der Zabbix-Services-Baum als Karte.
//
// Fetcht network.topology.services (NetworkTopologyServices -> ServiceTree) und
// zeichnet die Business-Services als GERICHTETEN Graphen: die Webseite oben,
// darunter, woran sie haengt (Webserver, PHP, Datenbank). Eine Kante Eltern→Kind
// heisst "haengt ab von".
//
// Bewusst eine EIGENE, kleine Cytoscape-Instanz (window._ntSvcCy), getrennt von
// der physischen Karte (window._ntCy): die Abhaengigkeitskante ist kein Kabel,
// hat keinen Traffic/Port/Uptime und ist gerichtet. Durch die eigene Instanz
// fasst dieser Tab NICHTS vom physischen Renderpfad an (Weathermap, Pfadsuche,
// Root-Cause, What-if, KPI). Read-only: zeichnen + Status-Farbe + Klick-Detail.
//
// Die Farben kommen aus derselben SEV_COL-Palette wie die Host-Knoten
// (severity.js) — service.status ist schon auf 0..5 normalisiert (ServiceTree),
// -1 (OK) wurde zu 0.

import { mkTabTheme, buildBaseUrl, isDark, clearWrap } from './utils.js';
import { t } from './i18n.js';
import { fetchJson } from './http.js';
import { SEV_COL, SEV_LBL } from './severity.js';

// Severity robust auf 0..5 klemmen (klemme() in severity.js ist nicht
// exportiert; die Normalisierung passiert ohnehin schon im Backend, das hier
// ist nur die Absicherung gegen kaputte Eingaben).
function _clampSev(s) {
    const n = Math.floor(Number(s)) || 0;
    return n < 0 ? 0 : (n > 5 ? 5 : n);
}
function _sevCol(s) { return SEV_COL[_clampSev(s)]; }
function _sevLbl(s) { return SEV_LBL[_clampSev(s)]; }

// Prozent kurz darstellen (99.95 statt 99.9500) — fuer SLA-/SLO-Werte.
function _pct(v) { return Math.round(Number(v) * 100) / 100; }

// Die kurze Web-Zeile (erreichbar/nicht erreichbar + Antwortzeit) fuers Label
// und das Detail. Gibt '' zurueck, wenn kein Web-Szenario verknuepft ist.
function _webLine(web) {
    if (!web) return '';
    // Tri-State: ok==null heisst "unbekannt" (noch kein fail-Messwert). Ohne
    // jeden Messwert die Zeile weglassen; "down" NUR bei ok===false, sonst
    // "up" (auch wenn ok==null, aber eine Antwortzeit vorliegt — dann hat es
    // ja geantwortet).
    if ((web.ok === null || web.ok === undefined) && web.resp_ms == null) return '';
    let s = (web.ok === false) ? t('services.web.down') : t('services.web.up');
    if (web.resp_ms != null) s += ' · ' + web.resp_ms + ' ms';
    return s;
}

// Die eigene Cytoscape-Instanz dieses Tabs. Auch an window, damit switchTab im
// Hauptmodul sie beim Tabwechsel abraeumen kann (cleanupServices()).
let _svcCy = null;
// Generationszaehler gegen "stale fetch": jedes render/cleanup erhoeht ihn. Ein
// noch laufender Fetch zeichnet nur, wenn seine Generation noch aktuell ist —
// sonst baut er eine Cytoscape-Instanz in einen schon abgebauten Container und
// leakt sie (das Leave-Teardown ist da laengst gelaufen).
let _gen = 0;

// Edit-Modus (nur fuer can_edit-User): Abhaengigkeiten verknuepfen/trennen.
// Modul-weit, damit der Modus einen Re-Render (nach einer Aenderung) ueberlebt.
let _editMode = false;
let _linkFrom = null;     // serviceid des ersten geklickten (Eltern-)Service
let _editHintEl = null;   // Hinweiszeile im Tab (Status/Fehler)
let _editBusy = false;    // ein Schreibvorgang laeuft -> kein zweiter parallel

function _setEditStatus(text) {
    if (_editHintEl) _editHintEl.textContent = text;
}

// Eine Verknuepfung/Trennung schreiben und bei Erfolg neu zeichnen. parent/child
// sind SERVICE-IDs (nicht die 'svc'-praefixierten Knoten-IDs).
function _postServiceEdit(op, parent, child, wrap) {
    if (_editBusy) return;            // Doppel-Submit verhindern
    _editBusy = true;
    // Render-Generation festhalten: wechselt der User den Tab, waehrend der
    // POST laeuft, darf der Callback NICHT renderServices() aufrufen — das wuerde
    // die inzwischen aktive (Tech-)Karte im geteilten Container zerstoeren.
    const myGen = _gen;
    const cfg = window.NT_CONFIG || {};
    const body = new URLSearchParams();
    body.append('op', op);
    body.append('parent', String(parent || ''));
    body.append('child', String(child || ''));
    body.append('nt_csrf', cfg.service_edit_csrf || '');
    _setEditStatus(t('services.edit.saving'));
    fetchJson(buildBaseUrl() + 'zabbix.php?action=network.topology.service_edit', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    }).then(function(r) {
        _editBusy = false;
        if (myGen !== _gen) return;   // Tab gewechselt -> nichts anfassen
        if (r && r.ok) {
            renderServices(wrap);     // Baum neu holen + zeichnen (Edit-Modus bleibt)
        }
        else {
            _setEditStatus(t('services.edit.error', { msg: (r && r.error) || '' }));
        }
    }).catch(function(e) {
        _editBusy = false;
        if (myGen !== _gen) return;
        _setEditStatus(t('services.edit.error', { msg: e && e.message ? e.message : '' }));
    });
}

export function cleanupServices() {
    _gen++;
    if (_svcCy) {
        try { _svcCy.destroy(); } catch (e) {}
        _svcCy = null;
    }
    window._ntSvcCy = null;
}

function _fetchServices() {
    const params = new URLSearchParams();
    params.append('action', 'network.topology.services');
    const url = buildBaseUrl() + 'zabbix.php?' + params.toString();
    return fetchJson(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
}

export function renderServices(wrap) {
    // Beim Betreten die physische Karte abraeumen (wie render-compliance):
    // der Tech-Tab haelt window._ntCy; ohne destroy liefe sein ResizeObserver
    // gegen ein unsichtbares Canvas weiter.
    if (window._ntCy)       { try { window._ntCy.destroy(); } catch (e) {} window._ntCy = null; }
    if (window._ntEdgeAnim) { clearInterval(window._ntEdgeAnim); window._ntEdgeAnim = null; }
    cleanupServices();
    // Diese Render-Generation festhalten (cleanupServices hat _gen gerade erhoeht).
    // Der Fetch unten zeichnet nur, wenn sie beim Eintreffen noch aktuell ist.
    const myGen = _gen;

    const dark  = isDark();
    const theme = mkTabTheme(dark);

    clearWrap(wrap);

    // Wurzel-Container: relativ positioniert, fuellt den Tab. Darin das
    // Cytoscape-Canvas (absolut) + Intro-Zeile + Detail-Box (beide overlay).
    const root = document.createElement('div');
    root.style.cssText = 'position:relative;width:100%;height:100%;overflow:hidden;'
        + 'background:' + theme.bg + ';color:' + theme.text + ';font-family:sans-serif';
    wrap.appendChild(root);

    // Intro per DOM (textContent) statt innerHTML — Service-/Theme-Werte landen
    // nie in einem HTML-String, also kein Escaping noetig und kein no-unsanitized.
    const intro = document.createElement('div');
    intro.style.cssText = 'position:absolute;top:10px;left:12px;z-index:5;max-width:60%;'
        + 'font-size:12px;color:' + theme.sub + ';pointer-events:none';
    const introTitle = document.createElement('div');
    introTitle.style.cssText = 'font-size:14px;font-weight:700;color:' + theme.text;
    introTitle.textContent = t('services.title');
    const introText = document.createElement('div');
    introText.textContent = t('services.intro');
    intro.appendChild(introTitle);
    intro.appendChild(introText);
    root.appendChild(intro);

    const cyDiv = document.createElement('div');
    cyDiv.style.cssText = 'position:absolute;inset:0;width:100%;height:100%';
    root.appendChild(cyDiv);

    const loading = document.createElement('div');
    loading.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);'
        + 'color:' + theme.subSoft + ';font-size:13px';
    loading.textContent = t('services.loading');
    root.appendChild(loading);

    // Edit-Leiste unten rechts — nur fuer can_edit-User. Verknuepfen/Trennen von
    // Abhaengigkeiten; der Server (ADMIN + Service-Rolle) entscheidet endgueltig.
    _linkFrom = null;
    _editHintEl = null;
    const cfg = window.NT_CONFIG || {};
    // Nur Super-Admins: das Ersetzen des parents-Arrays ist nur fuer sie
    // verlustfrei (sie sehen alle Eltern) — siehe NetworkTopologyServiceEdit.
    if (cfg.is_super_admin) {
        const editBar = document.createElement('div');
        editBar.style.cssText = 'position:absolute;bottom:12px;right:12px;z-index:7;'
            + 'display:flex;flex-direction:column;align-items:flex-end;gap:6px';
        const hint = document.createElement('div');
        hint.style.cssText = 'font-size:11px;max-width:280px;padding:4px 8px;border-radius:4px;'
            + 'background:' + theme.surface + ';border:1px solid ' + theme.border + ';'
            + 'color:' + theme.sub + ';display:' + (_editMode ? 'block' : 'none');
        hint.textContent = t('services.edit.hint');
        _editHintEl = hint;
        const editBtn = document.createElement('button');
        editBtn.className = 'btn-alt btn-small';
        editBtn.style.margin = '0';
        editBtn.textContent = t('services.edit.btn');
        const sync = function() {
            editBtn.style.background = _editMode ? '#3b82f6' : '';
            editBtn.style.color      = _editMode ? '#fff'    : '';
            hint.style.display       = _editMode ? 'block'   : 'none';
        };
        editBtn.addEventListener('click', function() {
            _editMode = !_editMode;
            _linkFrom = null;
            if (_svcCy) { try { _svcCy.$(':selected').unselect(); } catch (e) {} }
            hint.textContent = t('services.edit.hint');
            sync();
        });
        sync();
        editBar.appendChild(hint);
        editBar.appendChild(editBtn);
        root.appendChild(editBar);
    }

    _fetchServices()
        .then(function(data) {
            // Tab inzwischen gewechselt/neu gerendert? Ergebnis verwerfen —
            // sonst zeichneten wir in einen abgebauten Container und leakten die
            // Cytoscape-Instanz.
            if (myGen !== _gen) return;
            loading.remove();
            if (!data || data.error) {
                _message(root, theme, t('services.error', { msg: (data && data.error) || '' }), theme.sub);
                return;
            }
            const nodes = (data.nodes || []);
            const edges = (data.edges || []);
            if (!nodes.length) {
                _message(root, theme, t('services.none'), theme.subSoft);
                return;
            }
            _drawGraph(cyDiv, root, theme, dark, nodes, edges, wrap);
        })
        .catch(function(e) {
            if (myGen !== _gen) return;
            loading.remove();
            _message(root, theme, t('services.error', { msg: e && e.message ? e.message : '' }), theme.sub);
        });
}

function _message(root, theme, text, color) {
    const m = document.createElement('div');
    m.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);'
        + 'max-width:70%;text-align:center;font-size:13px;color:' + (color || theme.sub) + '';
    m.textContent = text;
    root.appendChild(m);
}

function _drawGraph(cyDiv, root, theme, dark, nodes, edges, wrap) {
    // Cytoscape-Elemente. Knoten: bg-Farbe aus der Severity vorberechnen (so
    // bleibt der Style schlicht). Kanten: gerichtet, 'se'+i als id.
    const els = [];
    nodes.forEach(function(n) {
        // Mehrzeiliges Label: Name + optional Web-Zeile (erreichbar/ms) +
        // optional SLA-Zeile. Cytoscape rendert \n als Zeilenumbruch (Canvas-
        // Text, also kein XSS-Pfad).
        let lbl = String(n.label != null ? n.label : n.id);
        const wl = _webLine(n.web);
        if (wl) lbl += '\n● ' + wl;
        if (n.sla) lbl += '\n' + t('services.sla', { sli: _pct(n.sla.sli), slo: _pct(n.sla.slo) });

        els.push({ data: {
            id: String(n.id),
            serviceid: String(n.serviceid != null ? n.serviceid : ''),
            label: lbl,
            name: String(n.label != null ? n.label : n.id),
            severity: _clampSev(n.severity),
            problems: Number(n.problems) || 0,
            sla: n.sla || null,
            web: n.web || null,
            bg: _sevCol(n.severity)
        }});
    });
    const incoming = {};
    edges.forEach(function(e, i) {
        els.push({ data: { id: 'se' + i, source: String(e.source), target: String(e.target) } });
        incoming[String(e.target)] = true;
    });
    // Wurzeln = Knoten ohne eingehende Kante (die "Webseiten" oben). Fallback:
    // wenn alles eine Wurzel waere (keine Kanten), laesst breadthfirst sie so.
    const roots = nodes.map(function(n) { return String(n.id); })
                       .filter(function(id) { return !incoming[id]; });

    const edgeCol  = dark ? '#64748b' : '#94a3b8';
    const labelCol = '#ffffff';

    const cy = cytoscape({
        container: cyDiv,
        elements: els,
        style: [
            { selector: 'node', style: {
                'shape': 'round-rectangle',
                'background-color': 'data(bg)',
                'label': 'data(label)',
                'color': labelCol,
                'text-valign': 'center',
                'text-halign': 'center',
                'text-wrap': 'wrap',
                'text-max-width': '150px',
                'text-outline-width': 1,
                'text-outline-color': 'rgba(0,0,0,0.35)',
                'font-size': 12,
                'font-weight': 600,
                'width': 'label',
                'height': 'label',
                'padding': '12px',
                'border-width': 2,
                'border-color': dark ? 'rgba(255,255,255,0.18)' : 'rgba(0,0,0,0.12)'
            }},
            // Gerichtete Abhaengigkeitskante: gestrichelt, Pfeil aufs Kind.
            // Nachbau des vorhandenen edge[kind="hosts"]-Stils der physischen
            // Karte — dort der einzige gerichtete Kantentyp.
            { selector: 'edge', style: {
                'width': 2,
                'line-color': edgeCol,
                'line-style': 'dashed',
                'line-dash-pattern': [6, 4],
                'curve-style': 'bezier',
                'target-arrow-shape': 'triangle',
                'target-arrow-color': edgeCol,
                'target-arrow-fill': 'filled',
                'arrow-scale': 1.0,
                'opacity': 0.9
            }},
            { selector: 'node:selected', style: {
                'border-width': 3,
                'border-color': '#3b82f6'
            }}
        ],
        layout: {
            name: 'breadthfirst',
            directed: true,
            roots: roots.length ? roots : undefined,
            padding: 30,
            spacingFactor: 1.35,
            animate: false
        },
        userZoomingEnabled: true, userPanningEnabled: true, boxSelectionEnabled: false,
        minZoom: 0.2, maxZoom: 3
    });
    _svcCy = cy;
    window._ntSvcCy = cy;

    // Canvas bekommt seine Groesse erst nach dem Layout-Tick zuverlaessig —
    // einmal nachfassen und einpassen (wie render-tech es tut).
    setTimeout(function() {
        if (cy && !cy.destroyed()) { cy.resize(); cy.fit(cy.nodes(), 40); }
    }, 50);

    // Detail-Box (Klick auf einen Service). Ein einzelner, wiederverwendeter
    // Container oben rechts; alle Namen via esc() (Service-Namen kommen aus
    // Zabbix und sind damit nicht vertrauenswuerdig).
    const detail = document.createElement('div');
    detail.style.cssText = 'position:absolute;top:10px;right:12px;z-index:6;display:none;'
        + 'min-width:180px;max-width:260px;padding:10px 12px;border-radius:6px;'
        + 'background:' + theme.surface + ';border:1px solid ' + theme.border + ';'
        + 'box-shadow:0 6px 20px rgba(0,0,0,0.14);font-size:12px;color:' + theme.text;
    root.appendChild(detail);

    cy.on('tap', 'node', function(evt) {
        const node = evt.target;
        // Edit-Modus: erster Klick = Eltern-Service (woran etwas haengt), zweiter
        // Klick = das abhaengige Kind. Gleicher Knoten noch mal = abbrechen.
        if (_editMode) {
            const sid = node.data('serviceid');
            if (!_linkFrom) {
                _linkFrom = sid;
                cy.$(':selected').unselect();
                node.select();
                _setEditStatus(t('services.edit.pick_child'));
                return;
            }
            cy.$(':selected').unselect();
            if (sid === _linkFrom) {
                _linkFrom = null;
                _setEditStatus(t('services.edit.hint'));
                return;
            }
            const parent = _linkFrom;
            _linkFrom = null;
            _postServiceEdit('link', parent, sid, wrap);
            return;
        }

        const d = node.data();
        const sev = _clampSev(d.severity);
        // Per DOM aufbauen (textContent) — der Service-Name kommt aus Zabbix und
        // darf nie in einen HTML-String; textContent ist von sich aus sicher.
        while (detail.firstChild) detail.removeChild(detail.firstChild);

        const dTitle = document.createElement('div');
        dTitle.style.cssText = 'font-weight:700;font-size:13px;margin-bottom:6px';
        dTitle.textContent = d.name || d.label;

        const dStatus = document.createElement('div');
        dStatus.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:3px';
        const dot = document.createElement('span');
        dot.style.cssText = 'width:10px;height:10px;border-radius:50%;flex:none;background:' + _sevCol(sev);
        const dLbl = document.createElement('span');
        dLbl.textContent = _sevLbl(sev);
        dStatus.appendChild(dot);
        dStatus.appendChild(dLbl);

        const dProb = document.createElement('div');
        dProb.style.cssText = 'color:' + theme.sub;
        dProb.textContent = t('services.problems', { n: d.problems || 0 });

        detail.appendChild(dTitle);
        detail.appendChild(dStatus);
        detail.appendChild(dProb);

        // Web-Szenario (erreichbar/Antwortzeit), falls per nt:web verknuepft
        // UND es schon einen Messwert gibt (sonst leer).
        const wl = _webLine(d.web);
        if (wl) {
            const dWeb = document.createElement('div');
            // Rot nur bei echtem down (ok===false); up/unbekannt-mit-Zeit neutral.
            dWeb.style.cssText = 'margin-top:4px;color:' + (d.web.ok === false ? _sevCol(4) : theme.sub);
            dWeb.textContent = wl;
            detail.appendChild(dWeb);
        }
        // SLA-Zeile, rot wenn unter dem Ziel (SLO).
        if (d.sla) {
            const below = Number(d.sla.sli) < Number(d.sla.slo);
            const dSla = document.createElement('div');
            dSla.style.cssText = 'margin-top:2px;color:' + (below ? _sevCol(4) : theme.sub);
            dSla.textContent = t('services.sla', { sli: _pct(d.sla.sli), slo: _pct(d.sla.slo) });
            detail.appendChild(dSla);
        }
        detail.style.display = 'block';
    });
    // Edit-Modus: Klick auf eine Kante trennt die Abhaengigkeit (Eltern→Kind).
    cy.on('tap', 'edge', function(evt) {
        if (!_editMode) return;
        const edge = evt.target;
        // Trennen ist zwar umkehrbar, aber ein Fehlklick auf eine duenne Kante
        // passiert leicht — kurz bestaetigen (wie beim Host-Anlegen).
        if (!confirm(t('services.edit.confirm_unlink', {
            parent: edge.source().data('name'),
            child:  edge.target().data('name')
        }))) return;
        _postServiceEdit('unlink', edge.source().data('serviceid'), edge.target().data('serviceid'), wrap);
    });

    cy.on('tap', function(evt) {
        if (evt.target === cy) detail.style.display = 'none';
    });
}
