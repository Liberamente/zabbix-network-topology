# Upstream integration roadmap — 2026-11-26

Status: approved planning direction, not authorization to merge or deploy. One portable product. This public document intentionally excludes customer identifiers, network data and secrets.

## Baseline
- Production reference: production/v5.5.27, e5817f2558e60df60b5396580b0178edf835e523, P4. Runtime not rechecked in this planning session.
- Upstream: linuser/zabbix-network-topology v5.6.13, 96f5f0a7f7b70677f73f746c8d22c3e45baf58ef, released 2026-11-18.
- Divergence: 15 upstream-only commits, 140 fork-only commits versus P4. Default main is not the production branch.
- Existing audit: docs/UPSTREAM_5_6_13_INTEGRATION_AUDIT.md.
- Existing hardening candidate: PR #25, draft/open, head e21203293dadce22b4c4e9f880571c4d62bcae1a, base integration/upstream-5.6.13-audit-20261125. Four check runs at this head verified successful 2026-11-26 (PHP/unit, XSS/shellcheck, frontend/parity/templates/package/layers/i18n/ghost/pipeline/bundle, isolated ZIP). Not merged or deployed. See docs/UPSTREAM_5_6_13_HARDENING_A.md.

## Sequenced merge plan
| ID | Scope | Priority / dependency | Acceptance |
|---|---|---|---|
| A | Complete PR #25: host creation UI/backend role rights, cache scope, secure sender; verify source/bundle parity. Separately assess rollback-safe deployment. | P0, first | Exact HEAD CI, independent review, permission regression and staging. Review target branch; merge into audit branch alone is not a production release. |
| T | Read-only inventory of actual device/proxy templates and item coverage; compare upstream YAML UUIDs, keys, LLD, preprocessing, retention, intervals and vendor support. | P1, may run alongside A | Matrix per vendor/model and template; identify missing/duplicate/unsupported items, safe import and export/restore plan. No import before approval. |
| B | Port identity and parallel links: upstream 5.6.7, 5.6.8, 5.6.10; optional local-port table; evaluate 5.6.6 multiple nt:uplink tags. | P1, T and A gates | Unique proven ifIndex, conflicting evidence unresolved, reciprocal LLDP/CDP, shared port, zero port, opaque IDs, parallel/LAG, VoIP and group/proxy regressions. |
| C | Live refresh of existing link status/traffic/errors (5.6.9); cache single-flight and stale snapshot (5.6.11/12) as separately reviewed changes. | P1, B; cache rights gate | Stable per-member identity, no edge promotion, current host visibility recheck and concurrent load tests. |
| D1 | NT Faceplate: FaceplateBuilder, action, SVG, widget, 5.6.13 port naming/layout fixes. Basic IF-MIB first. | P1 product feature, B and T | Validate physical slot mapping for 8/16/24/48-port layouts and actual vendors; gaps remain gaps, unknown stays unknown; permission checks; show measured vs inferred neighbors separately. |
| D2 | STP ring and self-loop detection, nt_stp_snmp.yaml, broadcast and optional MAC flap evidence. | P1, B/T; enrich D1 | Rings only on verified cable graph; LAG not a ring; STP missing is unknown; no inferred endpoint edge counts as cable. |
| D3 | PoE and VLAN optional collectors and UI; nt_poe_snmp.yaml, nt_vlan_snmp.yaml; PoE budget/trend. | P1, T/D1 | Vendor capability probes, no guessed values, preserve unknown VLAN membership, no excessive polling. |
| E | Expected topology nt:expect, link quality, Plan/WLAN widgets, layout undo/redo, filter recovery, resource metric fixes. Split into small PRs. | P2, core stable | Expected links remain intent only; no scope leaks; widget parity; preserve fork Wi-Fi evidence. |
| R | zabbix-tools API resilience: DNS temporary error retry, bounded backoff (proposed 2/5/15s), safe diagnostic categories, no automatic write retries. | P1 parallel work in separate repository | Synthetic DNS/timeout/TLS/permanent error and write-no-retry tests; service deadline budget and natural recovery validation. |
| I | Standard installer, operational proxy template coverage, immutable release and inventory management. | P1 parallel work in zabbix-tools | SSH Git access, pre/post checks, tested rollback, least privilege, no secrets in public repos. |

## Template scope
Review existing nt_lldp_snmp_template.yaml before changing it. Add optional NT LLDP Local Ports, NT Spanning-Tree, NT PoE, NT VLAN only with vendor support. Keep existing FDB/ARP/Wi-Fi data contracts and standard vendor SNMP templates. Health score/topology change templates are separate optional features. Proxy service health templates are distinct from device collectors. Do not overwrite stable LLDP identities or remove existing items by default. Verify Zabbix 7.0 compatibility and UUID/key conflicts, export before import.

## Merge and release rules
No blanket upstream merge, no file replacement of NetworkTopologyData or LldpEdgeBuilder, no blind cherry-pick. Pin upstream and starting SHA per PR; preserve AGPL notices and attribution. Keep verified LLDP/CDP, inferred FDB/ARP, wireless and logical links distinct; no fabricated physical edges. Preserve provenance, freshness, confidence, port identity, multiple physical members and manual overrides. Enforce host/group and configured proxy visibility before correlation and rendering. Synthetic fixtures only. Rebuild JS bundle and run full CI matrix from audit, compare same artifact in three validation environments. Record before/after counts, port correctness, permissions, performance, browser/PHP logs, template changes, rollback. Merge, tag and deployment are separate approval gates; backup exact runtime and prove restore outside production. No production changes in this roadmap.

## Next session
Complete review of PR #25 and run read-only template inventory; then prepare isolated B and D1 candidates in order. Keep private project state/backlog aligned with this document and track operational consolidation separately. Roadmap is a plan, not evidence of implementation or production readiness.
