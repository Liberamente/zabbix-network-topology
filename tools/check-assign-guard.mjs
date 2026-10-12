#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
//
// check-assign-guard — prueft nt-assign-guard.js gegen den Zabbix-8-xor-Konflikt.
//
// ANLASS
// ------
// Zabbix 8 legt Array.prototype.xor per Object.defineProperty an (js/common.js),
// ohne writable/configurable — also beides false. Cytoscape baut seinen
// Collection-Prototyp mit Object.create(Array.prototype) und mergt sein eigenes
// xor() per Object.assign darauf; das [[Set]] prallt an der geerbten, nicht
// beschreibbaren Eigenschaft ab und wirft, sodass Cytoscape beim Laden abbricht
// und jeder Graph-Tab leer bleibt. nt-assign-guard.js tauscht Object.assign nur
// waehrend des Cytoscape-Ladens gegen eine defineProperty-Fallback-Variante.
//
// Der Fix hatte bis hier keinen eigenen Gate (nur von Hand nachgestellt). Das
// holt dieses Skript nach: es prueft die beiden Pfade, die den Guard ausmachen,
// und die Spec-Konformitaet des Ersatz-assign.
//
// Aufruf: node tools/check-assign-guard.mjs

import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const GUARD = join(ROOT, 'assets/js/nt-assign-guard.js');
const src = readFileSync(GUARD, 'utf8');

let fails = 0;
const ok = (cond, msg) => {
    console.log(`  [${cond ? 'PASS' : 'FAIL'}] ${msg}`);
    if (!cond) fails++;
};

function loadGuard() {
    globalThis.window = globalThis;
    new Function(src)();                      // IIFE haengt NT_ASSIGN_GUARD an window
    return globalThis.NT_ASSIGN_GUARD;
}

// ── Szenario 8.0: xor als nicht beschreibbare Eigenschaft → Guard greift ──────
// Nachgestellt wie Zabbix 8: defineProperty ohne writable/configurable. Das
// poisont den Prozess dauerhaft (non-configurable), deshalb laeuft der 7.x-Fall
// unten in einem eigenen Prozess.
console.log('\n  Zabbix 8 — Array.prototype.xor readonly, Guard muss greifen\n');
Object.defineProperty(Array.prototype, 'xor', {
    value: function () { return 'zbx-helper'; },
    enumerable: false
});
{
    const G = loadGuard();
    ok(!!G && typeof G.on === 'function', 'Guard registriert (window.NT_ASSIGN_GUARD)');
    ok(G.applies() === true, 'applies(): erkennt den kaputten Deskriptor');

    const orig = Object.assign;
    G.on();
    ok(Object.assign !== orig, 'on(): Object.assign ersetzt');

    // Der Cytoscape-Fall: Ziel erbt von Array.prototype, Merge enthaelt ein xor.
    const proto = Object.create(Array.prototype);
    let threw = false, res;
    try { res = Object.assign(proto, { xor: function () { return 42; }, a: 1 }); }
    catch (e) { threw = true; }
    ok(!threw, 'Object.assign(erbt Array.prototype, {xor,...}) wirft NICHT mehr');
    ok(res === proto, 'safeAssign gibt das Ziel zurueck');
    ok(typeof proto.xor === 'function' && proto.xor() === 42, 'eigenes xor ueberschattet das readonly');
    ok(proto.a === 1, 'weitere Keys werden kopiert');
    ok([1, 2].xor() === 'zbx-helper', 'Zabbix-xor-Helfer bleibt unangetastet');

    let nullThrew = false;
    try { Object.assign(null, {}); } catch (e) { nullThrew = true; }
    ok(nullThrew, 'null-Ziel wirft (wie die Spec)');

    const sym = Symbol('s');
    ok(Object.assign({}, { [sym]: 9 })[sym] === 9, 'Symbole werden kopiert');

    G.off();
    ok(Object.assign === orig, 'off(): Original wiederhergestellt');

    // Zwei Widgets auf einem Dashboard: verschachtelte on()/off() per Zaehler.
    G.on(); G.on();
    ok(Object.assign !== orig, 'depth: nach 2x on() gepatcht');
    G.off();
    ok(Object.assign !== orig, 'depth: nach 1x off() noch gepatcht (zweites Widget laedt)');
    G.off();
    ok(Object.assign === orig, 'depth: nach 2x off() wiederhergestellt');
}

// ── Szenario 7.x: xor als schlichte Zuweisung (writable) → Guard = No-op ──────
// Zabbix 7.0/7.4 schreibt common.js:114 "Array.prototype.xor = function...",
// also writable/configurable = true. Der Guard darf dann GAR NICHTS anfassen.
// Eigener Prozess, weil der 8.0-Fall oben xor bereits non-configurable gesetzt
// hat und es sich hier nicht mehr auf writable aendern liesse.
console.log('\n  Zabbix 7.x — Array.prototype.xor writable, Guard muss No-op sein\n');
{
    const child = spawnSync(process.execPath, ['--input-type=module', '-e', `
        import { readFileSync } from 'node:fs';
        Array.prototype.xor = function () {};          // 7.x: Zuweisung => writable
        globalThis.window = globalThis;
        new Function(readFileSync(${JSON.stringify(GUARD)}, 'utf8'))();
        const G = globalThis.NT_ASSIGN_GUARD;
        const orig = Object.assign;
        G.on();
        const out = { applies: G.applies(), patched: Object.assign !== orig };
        G.off();
        process.stdout.write(JSON.stringify(out));
    `], { encoding: 'utf8' });

    let r = {};
    try { r = JSON.parse((child.stdout || '').trim()); }
    catch (e) { /* r bleibt leer → Checks unten schlagen fehl */ }
    ok(r.applies === false, 'applies(): false bei writable xor');
    ok(r.patched === false, 'on(): Object.assign bleibt unangetastet (echter No-op)');
}

console.log(fails === 0 ? '\n  alle Guard-Checks gruen\n' : `\n  ${fails} Guard-Check(s) FEHLGESCHLAGEN\n`);
process.exit(fails ? 1 : 0);
