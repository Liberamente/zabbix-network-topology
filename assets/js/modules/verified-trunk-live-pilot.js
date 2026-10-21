// SPDX-License-Identifier: AGPL-3.0-or-later
// Transient, SUPER-ADMIN-ONLY pilot: a locally selected, independently
// verified snapshot's four logical trunks on top of fresh Zabbix host nodes.
// No API writes, uploads, cookies, storage, persistence or backend file reads.
// The ordinary hostgroup graph remains untouched when the pilot is off.
let active = null;
let syncControl = null;
let skipNextPositionSave = false;
export function consumeVerifiedPilotPositionGuard() {
    const result = skipNextPositionSave;
    skipNextPositionSave = false;
    return result;
}
const MAX_AGE_SECONDS = 900;
const MAX_BYTES = 8 * 1024 * 1024;

export function canOfferVerifiedTrunkPilot(cfg) {
    return !!(cfg && cfg.is_super_admin === true
        && cfg.wallboard !== true && !cfg.selected_hostid
        && Array.isArray(cfg.selected_groupids)
        && cfg.selected_groupids.length === 1
        && /^[1-9][0-9]*$/.test(String(cfg.selected_groupids[0])));
}

export function clearVerifiedTrunkPilot() {
    if (active) skipNextPositionSave = true;
    active = null;
    if (syncControl) syncControl();
}

export function loadVerifiedTrunkPilot(text, cfg, liveNodes, nowSeconds) {
    if (!canOfferVerifiedTrunkPilot(cfg)) {
        throw new Error('La preview richiede un Super Admin e un solo gruppo di host.');
    }
    if (typeof text !== 'string' || text.length === 0 || text.length > MAX_BYTES) {
        throw new Error('Il JSON locale è vuoto o troppo grande.');
    }
    // The other script is vendored as a plain deferred <script> in the view,
    // before the main bundle. It is also used by the standalone file:// demo.
    // Model validation is strict and throws on malformed input.
    const validate = typeof window !== 'undefined'
        && window.NTVerifiedPreview && window.NTVerifiedPreview.prepare;
    if (typeof validate !== 'function') {
        throw new Error('Validatore di preview non caricato.');
    }
    let payload;
    try { payload = JSON.parse(text); }
    catch (_) { throw new Error('JSON non valido.'); }
    const model = validate(payload);
    if (String(cfg.selected_groupids[0]) !== model.summary.groupid) {
        throw new Error('Il report appartiene a un gruppo diverso da quello selezionato.');
    }
    if (model.summary.nodes !== 5 || model.summary.links !== 4
            || model.summary.physicalPairs !== 8) {
        throw new Error('Il pilot richiede esattamente 5 switch, 4 trunk e 8 coppie fisiche.');
    }
    const now = Number.isSafeInteger(nowSeconds) ? nowSeconds
        : Math.floor(Date.now() / 1000);
    if (model.summary.captured > now + 30
            || model.summary.captured < now - MAX_AGE_SECONDS) {
        throw new Error('Report LLDP scaduto o con orario futuro: rigenerare lo snapshot.');
    }
    const live = Array.isArray(liveNodes) ? liveNodes : [];
    const available = new Map(live.map(function (n) { return [String(n.id), n]; }));
    if (!model.nodes.every(function (node) {
        return available.has(node.id)
            && available.get(node.id).id !== undefined
            && available.get(node.id).id !== null;
    })) {
        throw new Error('Uno o più switch del report non sono visibili in Zabbix live.');
    }
    skipNextPositionSave = true;
    active = model;  // only after every validation passed
    return model.summary;
}

export function applyVerifiedTrunkPilot(nodes, edges, cfg, nowSeconds) {
    if (!active) {
        return {active: false, nodes: nodes, edges: edges};
    }
    const now = Number.isSafeInteger(nowSeconds) ? nowSeconds
        : Math.floor(Date.now() / 1000);
    if (!canOfferVerifiedTrunkPilot(cfg)
        || String(cfg.selected_groupids[0]) !== active.summary.groupid
        || active.summary.captured > now + 30
        || active.summary.captured < now - MAX_AGE_SECONDS) {
        clearVerifiedTrunkPilot();
        return {active: false, expired: true, nodes: nodes, edges: edges};
    }
    const byId = new Map((nodes || []).map(function (n) {
        return [String(n.id), n];
    }));
    if (!active.nodes.every(function (n) { return byId.has(n.id); })) {
        clearVerifiedTrunkPilot();  // permission or monitor state changed
        return {active: false, expired: true, nodes: nodes, edges: edges};
    }
    // Live state (ping/severity/problems/CPU/availability) is EXCLUSIVELY
    // inherited from the normal permission-filtered Zabbix NodeBuilder.
    const liveNodes = active.nodes.map(function (n) {
        return Object.assign({}, byId.get(n.id), {nt_verified_trunk_pilot: true});
    });
    // Four edges replace the entire original graph in this isolated pilot
    // view. NO blind merge with eight LLDP physical members -> no duplicates.
    const pilotEdges = active.edges.map(function (e) {
        return {
            id: e.id, from: e.source, to: e.target,
            src: ['lldp'], reporters: [e.source, e.target],
            confirmed: true, match: 'exact', port_match: 'exact',
            ports: {[e.source]: e.sourceTrunk, [e.target]: e.targetTrunk},
            port_idx: {[e.source]: e.sourceIfindex,
                       [e.target]: e.targetIfindex},
            nt_verified_static_trunk: true,
            nt_offline_preview: true,
            nt_members: e.members.map(function (m) { return Object.assign({}, m); }),
            nt_member_count: e.memberCount,
            port_metrics: {}
        };
    });
    return {active: true, nodes: liveNodes, edges: pilotEdges,
            summary: active.summary};
}

// The control lives in the persistent topbar, outside the rendered canvas.
// A reload clears the snapshot and restores the unchanged standard graph.
export function installVerifiedTrunkPilot(cfg, getLive, redraw, notify) {
    if (!canOfferVerifiedTrunkPilot(cfg)) return;
    const topbar = document.querySelector('#nt-root .nt-topbar__actions');
    if (!topbar || document.getElementById('nt-verified-pilot-control')) return;
    const host = document.createElement('span');
    host.id = 'nt-verified-pilot-control';
    host.style.cssText = 'display:inline-flex;gap:6px;align-items:center;'
        + 'border:1px solid #547d9b;border-radius:6px;padding:3px 8px;'
        + 'font-size:11px;background:var(--nt-surface,#fff);color:var(--nt-text,#111)';
    const title = document.createElement('span');
    title.textContent = 'TEST TRUNK';
    title.style.fontWeight = '700';
    host.appendChild(title);
    const load = document.createElement('label');
    load.style.cssText = 'cursor:pointer;text-decoration:underline';
    load.textContent = 'Apri JSON locale';
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json,application/json';
    input.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0';
    input.setAttribute('aria-label', 'Seleziona il payload locale dei trunk verificati');
    load.appendChild(input);
    host.appendChild(load);
    const off = document.createElement('button');
    off.type = 'button';
    off.textContent = 'Disattiva';
    off.style.cssText = 'font-size:11px;min-height:22px';
    off.disabled = true;
    host.appendChild(off);
    const badge = document.createElement('span');
    badge.style.color = 'var(--nt-sub,#64748b)';
    badge.textContent = 'OFF';
    host.appendChild(badge);
    const sync = function () {
        off.disabled = !active;
        badge.textContent = active ? '5 switch · 4 trunk · LIVE NODES' : 'OFF';
    };
    input.addEventListener('change', async function () {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (file.size <= 0 || file.size > MAX_BYTES) {
            notify('Il JSON è vuoto o supera 8 MiB.', 'error');
            return;
        }
        const hadPilot = active !== null;
        try {
            const raw = await file.text();
            const d = getLive();
            loadVerifiedTrunkPilot(raw, cfg, d.nodes || []);
            sync();
            notify('Pilot LLDP attivo: 5 switch, 4 trunk, ping e problemi da Zabbix live.', 'success');
            redraw();
        } catch (error) {
            clearVerifiedTrunkPilot();
            sync();
            if (hadPilot) redraw(); // never leave a stale graph with an OFF badge
            notify(error.message || 'Preview non valida', 'error');
        }
    });
    off.addEventListener('click', function () {
        skipNextPositionSave = true;
        clearVerifiedTrunkPilot();
        sync();
        redraw();
    });
    topbar.appendChild(host);
    syncControl = sync;
    // A changing backend scope or an expired raw report must never leave an
    // old UI badge claiming the pilot is still active.
    return sync;
}
