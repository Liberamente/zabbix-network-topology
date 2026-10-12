#!/usr/bin/env node
// check-quality.mjs — eine Güte-Aussage, an einem Ort, vollständig.
//
// WARUM ES DIESES GATE GIBT
// -------------------------
// Seit 5.5.16/17 trägt das Modul EIN Vokabular für die Güte eines Messwerts —
// measured / estimated / stale / unknown — in `quality.js`, mit Glyph, Farbe
// und i18n-Schlüssel je Zustand. Der fehlende Wert wird als „nicht erfasst"
// gezeigt, nicht als harmloser Strich (dieselbe Idee wie ci:ghost beim
// Knotenstatus: Nichtwissen darf nicht wie „in Ordnung" aussehen).
//
// Die Erfahrung mit ci:ghost war: eine solche Entscheidung wird an jeder neuen
// Stelle NEU getroffen und läuft auseinander. Dieses Gate hält sie in
// quality.js und stellt sicher, dass sie VOLLSTÄNDIG ist.
//
// WAS ES PRUEFT (alles grep-/parse-basiert, ohne Fehlalarm)
// ---------------------------------------------------------
// 1. Kein anderes Modul definiert ein eigenes `QUALITY` — ein zweites wäre der
//    Anfang der Drift.
// 2. Jeder Zustand in QUALITY hat einen META-Eintrag (Glyph/Farbe/Key) — ein
//    Zustand ohne Darstellung ist ein halber Zustand.
// 3. Jeder META-i18n-Schlüssel existiert in de.js UND en.js — ein Zustand ohne
//    Text fällt sonst durch kein anderes Gate (ci:i18n prüft Paritaet der
//    vorhandenen Schlüssel, nicht ob quality.js referenzierte existieren).

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const DIR  = 'assets/js/modules';
const HEIM = 'quality.js';
const src  = readFileSync(join(DIR, HEIM), 'utf8');
const de   = readFileSync(join(DIR, 'i18n', 'de.js'), 'utf8');
const en   = readFileSync(join(DIR, 'i18n', 'en.js'), 'utf8');

const befunde = [];

// QUALITY-Werte: die Zeichenketten im `export const QUALITY = { … }`-Block.
const qBlock = (src.match(/export const QUALITY\s*=\s*\{([\s\S]*?)\n\};/) || [])[1] || '';
const qValues = [...qBlock.matchAll(/:\s*'([^']+)'/g)].map((m) => m[1]);

// META: Zustand → i18n-Schlüssel, aus dem `const META = { … }`-Block.
const mBlock = (src.match(/const META\s*=\s*\{([\s\S]*?)\n\};/) || [])[1] || '';
const metaEntries = [...mBlock.matchAll(/(\w+):\s*\{[^}]*key:\s*'([^']+)'[^}]*\}/g)]
    .map((m) => ({ state: m[1], key: m[2] }));
const metaStates = metaEntries.map((e) => e.state);

if (!qValues.length) befunde.push('QUALITY-Block nicht gefunden/leer in quality.js');
if (!metaEntries.length) befunde.push('META-Block nicht gefunden/leer in quality.js');

// 2. Jeder QUALITY-Wert hat einen META-Eintrag.
for (const v of qValues) {
    if (!metaStates.includes(v)) {
        befunde.push(`QUALITY-Zustand '${v}' hat keinen META-Eintrag (Glyph/Farbe/Key)`);
    }
}

// 3. Jeder META-Schlüssel existiert in de.js UND en.js.
for (const e of metaEntries) {
    const token = "'" + e.key + "'";
    if (!de.includes(token)) befunde.push(`i18n/de.js fehlt der Schlüssel ${e.key} (Zustand '${e.state}')`);
    if (!en.includes(token)) befunde.push(`i18n/en.js fehlt der Schlüssel ${e.key} (Zustand '${e.state}')`);
}

// 1. Kein anderes Modul definiert ein eigenes QUALITY.
const EIGENE = /^\s*(?:export\s+)?(?:const|let|var)\s+QUALITY\s*=/;
let geprueft = 0;
for (const datei of readdirSync(DIR).filter((f) => f.endsWith('.js')).sort()) {
    if (datei === HEIM) continue;
    geprueft++;
    readFileSync(join(DIR, datei), 'utf8').split('\n').forEach((zeile, i) => {
        const nackt = zeile.replace(/^\s*(\/\/|\*|\/\*).*$/, '');
        if (EIGENE.test(nackt)) {
            befunde.push(`${datei}:${i + 1} eigenes QUALITY — Vokabular gehört in quality.js`);
        }
    });
}

console.log('\ncheck-quality: eine Güte-Aussage, vollständig\n');

if (befunde.length) {
    for (const b of befunde) console.error('  ✖ ' + b);
    console.error(`\n✖ ${befunde.length} Befund(e). quality.js ist die eine Quelle.`);
    process.exit(1);
}

console.log(`✓ ${qValues.length} Zustände, je Glyph + de/en-Text (${geprueft} Module geprüft).`);
