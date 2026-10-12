// quality.js — EINE Aussage über die Güte eines Messwerts, überall gleich.
//
// Das Modul zeigt Zahlen aus vielen Quellen: am Port gemessen, aus Host-Summen
// geschätzt, vor einer Weile zuletzt gesehen, oder gar nicht erfasst. Bisher
// stand diese Unterscheidung an jeder Stelle einzeln (edge-detail: "gemessen"
// gegen "geschätzt"; detail-panel: Durchstreichen + "(stale)"; eine fehlende
// Zahl wurde als "—" gezeigt). Ein nacktes "—" liest sich aber wie ein
// gesunder Nullwert — genau der Fehler, den ci:ghost beim Knotenstatus schon
// verhindert: Nichtwissen darf nicht wie "in Ordnung" aussehen.
//
// Deshalb hier EIN Vokabular mit vier Zuständen und ein Glyph/Farbe/Schlüssel
// je Zustand. i18n-FREI wie severity.js: der ANZEIGETEXT kommt vom Aufrufer
// über t(key), damit dieses Modul auch dort importierbar bleibt, wo es kein
// t() gibt. Die Farben sind Theme-Token (var(--nt-*)) — alle Aufrufer rendern
// ins DOM/HTML, nicht auf Canvas, also lösen sie dort auf.

export const QUALITY = {
    MEASURED:  'measured',   // am Wert selbst erhoben
    ESTIMATED: 'estimated',  // aus etwas anderem hochgerechnet
    STALE:     'veraltet',   // vorhanden, aber zu alt
    UNKNOWN:   'unknown',    // nicht erfasst — NICHT dasselbe wie 0
};

// Glyph + Farb-Token + i18n-Schlüssel je Zustand.
//   measured  bewusst unauffällig (grün, kleiner Punkt) — der Normalfall soll
//             nicht zukleistern; auffällig werden nur stale und unknown.
const META = {
    measured:  { glyph: '●', cssVar: 'var(--nt-ok-text)',   key: 'quality.measured'  },
    estimated: { glyph: '◐', cssVar: 'var(--nt-muted)',     key: 'quality.estimated' },
    veraltet:  { glyph: '⚠', cssVar: 'var(--nt-warn-text)', key: 'quality.stale'     },
    unknown:   { glyph: '?', cssVar: 'var(--nt-muted)',     key: 'quality.unknown'   },
};

/** Glyph, Farb-Token und i18n-Schlüssel zu einem Zustand. */
export function meta(state) {
    return META[state] || META.unknown;
}

/**
 * Güte eines Werts bestimmen.
 *
 * @param {*}      value  der Messwert — null/undefined/'' oder ein nicht
 *                        endlicher Zahlenwert gilt als NICHT VORHANDEN
 *                        (→ unknown). Eine echte 0 ist vorhanden.
 * @param {object} [opts] { estimated?:boolean, stale?:boolean }
 * @returns {string} einer der QUALITY-Werte
 */
export function classify(value, opts) {
    opts = opts || {};
    const fehlt = value == null || value === ''
        || (typeof value === 'number' && !isFinite(value));
    if (fehlt) {
        return QUALITY.UNKNOWN;
    }
    // Reihenfolge: veraltet schlägt geschätzt. Ein alter Schätzwert ist vor
    // allem alt — die Frische ist die wichtigere Warnung.
    if (opts.stale) {
        return QUALITY.STALE;
    }
    if (opts.estimated) {
        return QUALITY.ESTIMATED;
    }
    return QUALITY.MEASURED;
}
