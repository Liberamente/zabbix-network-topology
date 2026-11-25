# Upstream 5.6.13 integration audit (read-only baseline)

Audit date: 2026-11-25. Status: **AUDIT / DESIGN ONLY**. No runtime, Zabbix, proxy, template or frontend changes.

## Immutable baseline

- Product/fork: `Liberamente/zabbix-network-topology`.
- Target branch created from `production/v5.5.27` commit `e5817f2558e60df60b5396580b0178edf835e523` (Liberamente P4).
- Upstream reference: `linuser/zabbix-network-topology` main commit `96f5f0a7f7b70677f73f746c8d22c3e45baf58ef`, manifest `5.6.13`.
- GitHub comparison: divergent; upstream has 15 commits not in P4, fork has 140 commits not upstream; 165 changed paths (70 added, 85 modified, 7 removed, 3 renamed) in the comparison.
- GitHub source presence and differences are verified; deployed filesystem and Docker mount are **NOT** reverified by this audit. A branch or commit is not proof of a deployment.
- Public sources: `https://github.com/linuser/zabbix-network-topology` and `https://github.com/Liberamente/zabbix-network-topology`.

## Invariants: never replace these with upstream defaults

1. One portable module for all installations; validation environments are not independent forks.
2. Keep verified LLDP/CDP cable edges distinct from inferred FDB/ARP associations and from Wi-Fi associations. MAC learning alone is not proof of physical cabling.
3. Do not fabricate edges to improve visual completeness. Preserve evidence provenance, confidence, age and port identity.
4. Preserve parallel physical members while avoiding duplicate logical trunk/LAG totals. Preserve known reciprocal links and shared-port/Port-ID diagnostics.
5. Preserve manually curated `nt:uplink` and `nt:lldp`, pass-through logic and the optional ping-positive view.
6. Enforce per-user group/host visibility and, where configured, proxy boundaries before correlation, caching and rendering.
7. No Zabbix writes during graph rendering, no production deployment without explicit GO, and no customer-specific fixtures, network identifiers or secrets in this public repository.

## Confirmed source-level gaps and suggested priority

| Priority | Upstream behavior | Fork P4 source observation | Integration rule |
|---|---|---|---|
| P0 | Host creation requires `CRoleHelper::UI_CONFIGURATION_HOSTS` in addition to Admin type | `actions/NetworkTopologyCreateHost.php` checks Admin type only | Add role check together with UI capability flag; test denial/allowing. Do not claim current exploitability without runtime assessment. |
| P0 | Sender verifies HTTPS certificates and does not place passwords/full responses in process arguments | P4 `tools/topo-change-sender.sh` uses `curl -k` and passes the password via command argument | Adapt the sender and test with dummy credentials; do not deploy/unblind secrets. |
| P0 | Deployment performs backed-up replace/rollback | P4 `deploy.sh` contains destructive delete-before-move paths | Design a Docker/mount-aware rollback; never wholesale-copy upstream installer or sync-delete deployed tree. |
| P0/P1 | Cache keys include role/user type and an optional single-flight/stale snapshot validates current host rights | P4 `actions/NtCache.php` keys by uid only and has no lock(); P4 Data action has no stale-while-rebuild mechanism | Port role/type cache-scope hardening first; single-flight is separate and must preserve per-group/proxy/feature-flag keys and right checks. |
| P1 | Proven ifIndex guard avoids mis-pairing parallel CDP/LLDP links (v5.6.7) | P4 `LldpEdgeBuilder::findMember()` has older unguarded reporter fallback | Integrate a proven-port invariant, preserving P4's zero-port handling, explicit chassis alias and shared-port diagnostics. Never promote an unproven LLDP local number to real ifIndex. |
| P1 | Optional LLDP local-port table maps LLDP numbering to IF-MIB ifIndex (v5.6.10) | No equivalent `localIfIndex()` adapter in P4 builder | Optional collector and strict unique match only, preserving conservative fallback and all P4 edge kinds. |
| P1 | Cisco stock-interface item-name fallback for port labels (v5.6.8) | Not established as equivalent in P4; further PortNameParser review required | Add as lowest-priority source after measured ifName/ifDescr/ifAlias, reject ambiguities. |
| P1 | Refresh updates measured per-edge state/traffic/STP without full rerender (v5.6.9) | `measuredEdgeStatus`/`edgeStatusKey` helpers absent from P4 `build-elements.js` | Adapt to fork edge kinds including inferred, Wi-Fi, parallel, Port-ID and synthetic edges; never change edge existence without full rebuild. |
| P1/P2 | Several `nt:uplink` tags per host (v5.6.6) | P4 `HostTagParser.php` stores only first valid uplink | Consider list-of-uplinks with duplicate guard and explicit scope; reconcile with existing verified LAG/members. |
| P2 | STP cycles and loop classification; VLAN Q-BRIDGE filter; link quality; PoE and switch faceplate | New optional parser/UI/templates across many files | Isolate behind feature flags; STP rings only on evidence-backed physical network, never on inferred endpoint links; missing data stays unknown. |
| P3 | Floor-plan/Plan view, undo-redo layout, persisted severity-filter recovery (v5.6.0/5.6.13) | Not fully equivalent in P4 source | Separate UX patchset. Ensure shared layout permissions and user scopes. |

## Known collisions / no blind cherry-pick

- `actions/NetworkTopologyData.php`: large conflict area (P4 AutoFdbEdges, AutoWifiEdges, Port-ID pilot scoping, pass-through, host/proxy visibility and response/baseline cache). Apply minimal scoped changes with source-level review.
- `topology/LldpEdgeBuilder.php`: both sides independently changed edge matching; do not replace the file. Port-ID=0, opaque CDP IDs, declared chassis aliases, multiport and shared-port invariants must pass.
- `assets/js/modules/build-elements.js`, `render-tech.js`, `toolbar.js` and bundled JS: fork contains additional edge kinds; rebuild bundle from source and check parity/drift.
- `HostTagParser.php`: moving from one uplink tag to many changes output shape. Update every consumer/test together, not the parser alone.
- `deploy.sh`: production module may contain local non-release files and Docker bind mounts; never run upstream destructive directory swap on the active tree.
- New `nt:expect` expected topology overlay is **intent**, not cable evidence. Its dashed edges must not enter verified physical topology counts or STP cycle detection.

## Suggested incremental change sets

1. `PR-A` hardening: role-right check, sensitive sender handling and cache permission signature, plus tests; deployment script changes are a separate manually reviewed runbook.
2. `PR-B` correct cable membership: proven-ifIndex guard, optional LLDP local-port bridge and port label fallback; synthetic fixtures for conflicting/parallel/local=0/shared-port/opaque IDs.
3. `PR-C` refresh of measured edge fields only, keyed by unambiguous edge identity; ensure edge types, live status and no synthetic link promotion.
4. `PR-D` additive STP/VLAN/quality/faceplate/PoE; independent opt-in templates and vendor availability checks.
5. `PR-E` Plan/UX and layout features, after security and topology correctness gates.

## Test matrix / acceptance gates (NOT executed in this audit)

Run in isolated checkout with synthetic fixture data:

```sh
npm ci
npm run ci:lint-php
npm run ci:test
npm run ci:frontend
npm run ci:eslint
npm run ci:xss
npm run ci:templates
npm run ci:parity
npm run ci:bundle-drift
npm run ci:package
npm run ci:layers
npm run ci:i18n
npm run ci:ghost
npm run ci:quality
npm run ci:shellcheck
npm run ci:pipeline
```

Add independent synthetic regression tests for: reciprocal LLDP, CDP+LLDP on parallel physical cables, opaque CDP IDs, LLDP local port 0 and non-ifIndex numbering, LLDP shared ports, LAG member-vs-logical representation, VoIP pass-through, manual overrides, inferred FDB/ARP, Wi-Fi, ping-positive filtering, group/proxy isolation, permission change after cache warmup, stale snapshots and link UP/DOWN refresh.

Required staging evidence: before/after per-edge-kind counts, isolated nodes, distinct physical/logical link members, port identity and counters, warnings, permissions with role/group change, backend latency under concurrent refresh, browser console and PHP fatal logs. Test the **same built artefact** in multiple isolated group/proxy scopes. No host updates/deletes are part of this audit.

## Release and rollback discipline

- No production deployment or tag change from this branch. P4 remains the reference.
- Pre-change verify base SHA and clean worktree; pin upstream SHA; record exact file set.
- Use isolated checkout/build and no `rsync --delete` against active webroot.
- Before any approved deployment: identify the actual Docker bind mount, hash active runtime files, create and test a full restore outside webroot, verify package integrity, agree change window and explicit GO.
- Rollback restores the exact previous runtime tree and cache/opcache state and re-runs host/edge/visibility smoke tests. A Git revert alone does not revert a deployed overlay.
- Documentation-only branch can be abandoned without affecting P4; after confirming no consumers or PRs, an authorized maintainer may remove the branch. The current audit does not delete branches.

## Status

- GitHub audit / file comparison: DONE (source only).
- New feature implementation, unit tests and runtime smoke: NOT PERFORMED.
- Zabbix, proxy, host, template, Docker, package and production modification: NONE.
- ADR: propose a new upstream-integration ADR in canonical project documents; not recorded as accepted.
- Canonical project files (01/03/04/06) are distinct from this repository audit and are **not** automatically updated by this commit.
