// Read-only CDP unmatched evidence presentation.
// Never modifies graph edges, confidence or neighbor identities.

import { esc } from './utils.js';
import { t } from './i18n.js';

const STATUS = {
    associated_inferred: 'cdp.associated',
    ambiguous: 'cdp.ambiguous',
    unmatched: 'cdp.unmatched'
};

export function renderCdpEvidenceTable(records, theme) {
    if (!Array.isArray(records) || records.length === 0) {
        return '';
    }

    const rows = records.map(function(r) {
        const status = t(STATUS[r.status] || STATUS.unmatched);
        const kinds = Array.isArray(r.evidence)
            ? r.evidence.join(', ')
            : '';

        const cells = [
            r.reporter == null ? '' : r.reporter,
            r.port == null ? '' : r.port,
            r.raw == null ? '' : r.raw,
            status,
            r.candidate_count == null ? 0 : r.candidate_count,
            t(r.shared_port === true ? 'cdp.yes' : 'cdp.no'),
            kinds
        ];

        return '<tr>' + cells.map(function(v) {
            return '<td style="padding:6px 10px;border-bottom:1px solid '
                + theme.borderSoft + '">'
                + esc(String(v)) + '</td>';
        }).join('') + '</tr>';
    }).join('');

    const headers = [
        t('cdp.reporter'), t('cdp.port'), t('cdp.raw'),
        t('cdp.status'), t('cdp.candidates'),
        t('cdp.shared'), t('cdp.evidence')
    ];

    return '<section style="margin-top:24px">'
        + '<h3 style="font-size:13px;color:' + theme.text + '">'
        + esc(t('cdp.title')) + '</h3>'
        + '<p style="font-size:11px;color:' + theme.sub + '">'
        + esc(t('cdp.note')) + '</p>'
        + '<table style="width:100%;border-collapse:collapse;font-size:12px">'
        + '<thead><tr>'
        + headers.map(function(h) {
            return '<th style="text-align:left;padding:6px 10px">'
                + esc(h) + '</th>';
        }).join('')
        + '</tr></thead><tbody>'
        + rows
        + '</tbody></table></section>';
}
