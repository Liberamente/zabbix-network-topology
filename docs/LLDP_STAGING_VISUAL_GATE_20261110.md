# LLDP Topology staging visual gate and deployment-impact inventory

Status: **NOT RUN on a real frontend staging session** as of 2026-11-10.
Source PR: #23, candidate baseline `14bb5c6e9166df3c1446fc734a8713ca560c466f`.
This checklist describes future authorization gates; it does not authorize a template import, module deployment or production writes.

## Source and reproducibility

- Common module: `Liberamente/zabbix-network-topology`; base `production/v5.5.27` at `f9f4f2999d0e21d56573c02ba98e04408ee78a0d`.
- Candidate branch: `feature/lldp-consolidated-20261110`, PR #23. Pin the **exact latest passing head SHA** before every run, rather than using a floating branch on a staging server.
- Existing automated evidence: `tests/LldpMultiportEvidenceReplayTest.php` exercises actual backend edge creation; `tests/LldpPortIdUiTest.mjs` exercises the frontend element mapping; `tools/check-template-logic.mjs` verifies stable LLD keys; `tools/ci/nt-lldp-zabbix70-import.mjs` verifies a separate Zabbix 7.0.32 import and item-ID/history lifecycle.
- The Zabbix CI changes only the disposable instance's master transport to a TRAP input, not the committed SNMP_AGENT template.
- Never copy a live customer database, Zabbix API token, SNMP secret, private hostname, MAC/IP, screen capture, or customer-specific export into this public repository. Use synthetic fixtures for issues and regression tests.

## Live read-only aggregate preflight (2026-11-10)

Collected using connected Zabbix 7.0.32 API `discoveryrule.get`, `itemprototype.get`, `item.get` and `host.get` (no writes). Aggregates only:

| Measurement | Observation |
| --- | ---: |
| Hosts inheriting `nt.lldp.rem.discovery` | 245 |
| Enabled/disabled hosts among these | 238 / 7 |
| Distinct proxies hosting them | 9 |
| Distinct host groups containing them | 9 |
| Inherited `lldpRemPortId[{#SNMPINDEX}]` item prototypes | 245 |
| Existing `lldpRemPortId[...]` items | 265 |
| Hosts with those discovered Port-ID items | 22 |
| Enabled/disabed Port-ID items | 218 / 47 |

These are point-in-time totals. Group membership overlaps; they are **not** an approval to operate on all 245 hosts. `host.get` returned no parentTemplates for the inspected API identity, so template ownership/linkage still needs an authorized `template.get` and export inspection. Discovered item count is not an estimate of how many item IDs will change on import.

## Mandatory staging scope

1. Choose an **isolated Zabbix 7.0 instance** or staging frontend that is demonstrably not connected to the live Zabbix DB, production API, production server, or production discovery timers. Record its version, URL (privately), exact module SHA, and template-export checksum in an internal checkpoint.
2. Confirm that the staging account and host visibility are confined to the intended validation group and proxy. No cross-group or cross-proxy evidence may enter a candidate edge.
3. Take pre-test screenshots **privately**, plus a redacted counts-only baseline: nodes, edges by type, verified LLDP/CDP edges, inferred LLDP Port-ID/FDB/ARP, Wi-Fi, confirmed parallel/trunk members, ghost and isolated nodes.
4. Import the template in staging only. Record the import diff: template UUID/ID, six prototype UUIDs/IDs and keys, LLDP/CDP rule IDs, number of new/lost/discovered items. Compare with the **prior** installed export to establish that single-port neighbor identities are unchanged.
5. Feed synthetic or authorized sanitized observations; exercise the table below. Take after screenshots from exactly the same map filters, group/proxy scope, layout and node positions. Capture tooltip/side-panel evidence for every critical edge.
6. If any regression is present, **stop**. Preserve staging evidence, revert the staging template to its pre-test export, and document whether LLD-created or lost item IDs/history require restoration. Restoring a YAML template alone cannot guarantee restoration of deleted history.

## Visual acceptance matrix — required observed results

| Synthetic scenario | Expected backend / UI behavior | Gate |
| --- | --- | --- |
| One chassis, one stable LLDP Port-ID, changing TimeMark/RemIndex | One consistent neighbor association, original discovered item key, no edge flapping | Required |
| One chassis/local port advertises two distinct remote Port-IDs for the **same** monitored peer | One host-pair edge, no fabricated second cable, both logical observations retained in Zabbix data; if UI does not expose each Port-ID, explicitly document that visibility gap | Required |
| Same local port advertises two **different** peers | Distinct evidence associations only; no reciprocal confirmation from one reporter, no implication that two direct physical cables exist | Required |
| Two unique local physical ports genuinely report one peer | Parallel members remain separate; do not merge distinct local ports; do not declare reciprocal confirmation from one reporter | Required |
| A newly added earlier-sorting Port-ID | Existing port-specific item keys, IDs and port association unchanged; old single-chassis item is never reassigned | Required |
| A reciprocal LLDP or CDP report with consistent independent port evidence | Confirmation and physical port labels reflect the actual reciprocal reporters, never a guessed FDB match | Required |
| LLDP absent, only FDB/ARP MAC learning | Evidence remains **inferred**, never a verified direct physical cable; confidence and source are shown | Required |
| Wireless client behind an AP | Remains a Wi-Fi association, not a wired uplink | Required |
| Phone-to-PC or multi-device cascade | No invented direct cable from upstream switch to downstream endpoint; retain pass-through provenance and opt-in behavior | Required |
| Verified LAG/trunk with two real members | Members, individual port labels and x2 bundle presentation remain; no duplicate physical capacity or invented per-link metrics | Required |
| Same data viewed through distinct group/proxy scopes | No information or edges leaked across group, user visibility or proxy isolation | Required |

**Important:** a green PHP/JS unit test is not a visual observation. The existing isolated Zabbix CI run confirms API/template import/LLD item/history behavior, **not** this matrix's actual displayed map.

## Acceptance and publication decision

- **PASS** only when all required scenarios have observed and documented expected results on the exact candidate SHA, with zero unreviewed changes to item-history identity or edge provenance.
- **FAIL / BLOCKED** if a logical Port-ID announcement is shown as a verified parallel physical link without independent evidence, if a historic item key is reassigned to another Port-ID, if Wi-Fi/inferred edges are promoted to physical, if group/proxy isolation fails, or if a host migration diff cannot be explained.
- In the production deployment plan, enumerate all linked hosts and their proxy/group scope privately; reconcile the observed 245 inherited discovery rules with a privileged template export; choose a controlled pilot group and document backup, live checks and rollback **before** requesting explicit deployment approval.
- Publishing source to GitHub is distinct from merging the PR and from deploying/importing to production. None is implied by completion of this checklist.

## Checkpoint for canonical project documents

After the staging session, update the private canonical `01_STATO_CORRENTE.md`, `02_ARCHITETTURA.md` (only if a rule changed), `03_DECISIONI.md` (if applicable), `04_GIT_E_RELEASE.md`, the backlog, and `08_CHECKPOINT_MODELLO.md` using the project's actual document locations. Do not create public stand-ins or claim the canonical files were updated when they were not accessible. Record exact SHA, screenshots kept privately, pass/fail matrix, item-ID migration results, known gaps, and rollback outcome.
