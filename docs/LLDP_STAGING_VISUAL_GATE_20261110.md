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
| Enabled/disabled Port-ID items | 218 / 47 |

These are point-in-time totals. Group membership overlaps; they are **not** an approval to operate on all 245 hosts. `host.get` returned no parentTemplates for the inspected API identity, so template ownership/linkage still needs an authorized `template.get` and export inspection. Discovered item count is not an estimate of how many item IDs will change on import.

## Read-only LLDP identity risk classification (2026-11-10, subsequent check)

This additional aggregate query paired existing `lldpRemPortId` and `lldpRemChassisId` item keys by host and the numeric **local port** in `0.LocalPort.StableId`, without writing or publishing any private identifier.

| Measurement | Observed |
| --- | ---: |
| Port-ID / Chassis-ID / SysName discovered items | 265 / 265 / 265 |
| Nonempty Port-ID / Chassis-ID / SysName last values | 234 / 237 / 174 |
| Host+local-port groups with Port-ID items | 204 |
| Groups holding multiple Port-ID items | 22 |
| Groups with multiple distinct nonempty Port-ID values | 15 |
| Groups with different Port-IDs and identical populated Chassis-IDs | **0** |
| Groups with repeated equal Port-ID+Chassis evidence (among distinct item keys) | **3** |

**Interpretation:** zero same-chassis/different-Port-ID groups were observed in this item snapshot, so there is no live evidence here of an active primary-selection collision. However this does **not** predict future observations, prove that all source items are current, or establish that all discovered items are associated with the currently installed template. Repeated equal evidence does not prove repeated physical cables; old/lost LLD items, independent advertisements, or collection quirks remain possible.

**Risk gate:** the previous Zabbix 7.0.32 isolated import showed that a one→many Port-ID transition generates new item IDs and leaves prior items/history accessible during observed lost-resource retention. Before any production template import, compare **actual live keys and LLD state** host-by-host against the candidate algorithm using only private, authorized data; record exact projected additions, retained keys, retiring keys, and item history policies. Do not infer a zero-churn migration from the aggregate 0 count above.

## Additional model-vs-installed-key comparison — 2026-11-10

**Read-only API method:** correlate `lldpRemPortId`, `lldpRemChassisId`, and `lldpRemSysName` discovered items by the same host and already stored `0.LocalPort.StableId` index. Reconstruct the candidate's current single-neighbor FNV-1a 32-bit key using the normalized stored Chassis-ID (or the documented Port-ID/SysName fallbacks); compare only key *equality*, never publish actual LLDP values, MACs, host IDs or port labels.

| Aggregated check | Result |
| --- | ---: |
| Existing Port-ID item records considered | 265 |
| Records with at least one identity observation | 237 |
| Records lacking identity values needed by the projection | 28 |
| Matching existing key and modeled candidate key | **221** |
| Different existing key versus modeled candidate key | **16** |
| Distinct hosts accounting for all 16 differences | **1** |
| Distinct local ports accounting for those 16 differences | **2** |
| Distinct same-chassis/multi-Port-ID modeled records | **0** |

All 16 mismatching items had populated Chassis-ID, Port-ID and SysName values. The Chassis-ID and Port-ID values were each 17 characters long in these records, with separators in the chassis representation. Their Port-ID, Chassis-ID and SysName timestamps coincided at the API precision reported. None of the 16 existing keys could be reproduced by the small set of alternative legacy identity recipes checked (raw/stripped/separator-swapped Chassis-ID, port+name, name-only, port-only, chassis+port, chassis+name).

**Interpretation and blocker:** the 16 differences are a **potential compatibility problem** on one host and require a private targeted check of its installed discovery preprocessing and item-discovery metadata before any global import. They are **not** proof that 16 item IDs would be replaced: the analysis does not have the current raw SNMP walk, full historical LLD state or guaranteed same-epoch preprocessing semantics. Also, a matching item key across a mixed historical item snapshot does not prove it is currently discovered, supported or visible on the map.

**Critical data limitation:** `nt.lldp.walk` exists on 245 hosts but `item.get` reported no nonempty `lastvalue` (and no nonzero `lastclock`) for these master items; the template sets master walk `history: '0'`. Therefore a direct current-walk replay of all 245 hosts cannot be honestly claimed from this read-only API snapshot. The 16-item discrepancy must be resolved or explicitly accepted through an approved, host-scoped migration plan. Do not silently normalize or rewrite existing keys.

## LLD resource-state reconciliation and installed preprocessing — 2026-11-10

The 16 key differences above were examined with an additional **read-only** `item.get` including `selectItemDiscovery: "extend"` for only the discrepant item IDs:

| Status of the 16 discrepant item records | Count |
| --- | ---: |
| `itemDiscovery.status = 1`: no longer discovered, retained by LLD | **14** |
| `itemDiscovery.status = 0`: currently discovered | **2** |

All sixteen still had an LLD prototype parent; the two current items were observed on two distinct local ports of **one** host. The 14 lost resources should **not** be deleted or treated as current cable evidence merely to make a migration diff look clean. This materially reduces the currently visible mismatch scope from 16 records to **two active LLD resources**, but is not proof of safe global rollout.

The effective `nt.lldp.rem.discovery` preprocessing on the affected host was then inspected via `discoveryrule.get` with `selectPreprocessing: "extend"` (no write). It contains the installed trim/lowercase/whitespace normalization, chassis-first `0.LocalPort.FNV-1a-32(LocalPort|identity)` structure, raw RemIndex selector, and `{#NT_PORTID}` discovery macro. The PR #23 candidate uses the same installed single-neighbor identity family and obtains remote Port-ID from the same OID via its `{#NT_REMPORT}` macro, while adding explicit multiport disambiguation. The installed script **does not** contain the PR's multiport `|port=` branch, as expected for a not-yet-imported candidate.

**Open targeted question:** why the two currently discovered item keys cannot be regenerated from the corresponding **stored** Chassis-ID values using the visible installed identity recipe. Their item last values are not necessarily the original SNMP_WALK_TO_JSON macro values at the moment the LLD identity was generated. An original, authorized read-only raw LLDP walk plus the corresponding discovery macros/timestamps, collected privately, is required to resolve this without risking history. No speculative key rewrite, item cleanup, or template import has been performed.

**Release gate:** two currently discovered key mismatches remain **unresolved** pending scoped raw-walk correlation; do not declare zero-churn or release-ready. Full production template ownership and visual staging QA are separately pending.

## Active LLDP mismatch historical-data diagnostic — 2026-11-10

This check did **not** perform SNMP polling, production changes or Zabbix API writes. The two active item key mismatches from the preceding read-only inventory were cross-referenced with their matching `lldpRemChassisId` and `lldpRemSysName` item IDs, and Zabbix `history.get` was used with `history=1` (CHAR) on those items.

| Privacy-safe result | Observation |
| --- | ---: |
| Currently discovered discrepant Port-ID items | 2 |
| Hosts and local ports involved | 1 host / 2 ports |
| Chassis-ID history samples available for each | 1 |
| Distinct historic Chassis-ID values for each | 1 |
| Historic Chassis-ID values reproducing stored item key | **0** |
| Time difference: LLD `itemDiscovery.lastcheck` minus corresponding Chassis-ID `lastclock` | **18,001 seconds** for each |

The older 14 mismatches remain `itemDiscovery.status=1` (lost-resource retention). The two current items are still `itemDiscovery.status=0`, so the mismatch cannot be dismissed entirely as retired history.

The affected host's `nt.lldp.walk` master item is enabled and in supported state, with no reported error, but has `history=0` and no accessible last-value timestamp. Its dependent LLDP discovery rule is also enabled/supported without error; it is template-inherited and uses `lifetime=7d` for lost resources. This **does not** establish that its current walk contains or lacks the relevant rows. The observed five-hour item/discovery timestamp gap is evidence of differing update epochs, not proof of the underlying reason.

**Diagnostic conclusion:** no available historic Chassis-ID sample reconstructs either current item key. The current installed preprocessing was previously confirmed chassis-first FNV-1a with remote Port-ID fallback, but the actual SNMP_WALK_TO_JSON macro value used at key creation cannot be reconstructed from the stored dependent-item value alone. Potential format or time-epoch differences remain **unverified hypotheses**. Do not rewrite hashes, clear LLD resources, or treat these two keys as a known migration until a private, authorized raw-walk capture is paired with the discovery macro output. This remains separate from the pending visual staging gate.

## Historic chassis correlation for two active key mismatches — 2026-11-10

A further **read-only Zabbix API** check compared the existing `0.LocalPort.StableId` hashes on the two currently discovered but model-mismatched `lldpRemPortId` items to normalized Chassis-ID values already stored under other item keys **on the same host and local port**. The exact installed FNV-1a-32 identity was used for each candidate; no raw identifiers were published.

| Observation for each of the two affected local ports | Count |
| --- | ---: |
| Currently discovered Chassis-ID item records | 1 |
| No-longer-discovered, retained Chassis-ID records | 10 |
| Retained Chassis-ID records whose value reproduces the current Port-ID item's stored hash | **5** |
| Distinct matching retained Chassis-ID values | **1** |
| Currently discovered Chassis-ID item that carries the matching historic value | **0** |

**Finding:** both existing active Port-ID hashes correspond **exactly** to a Chassis-ID value still present in historic/lost LLD Chassis-ID records from the **same local port**. For both, the Chassis-ID value in the currently discovered companion item differs from the older value that recreates the key. This is strong evidence of a **historical identity/value mismatch**, not an unexplained hash collision.

**Not established:** the original macro values at the instant of discovery, whether the different Chassis-ID reflects a genuine peer replacement, stale SNMP agent data, a different subtype/representation, or a changed dependency during item lifecycle. The master walk uses `history=0`, and these observations are from retained dependent item data. Do not claim that the current key is wrong or rewrite it without an authorized same-epoch raw-walk/LLD macro comparison.

**Next bounded action:** on the affected device only, capture the six LLDP-MIB remote-table columns from an authorized read-only SNMP walk **privately** and compare their TimeMark.LocalPort.RemIndex, Chassis-ID and remote Port-ID with the installed discovery output and the two current item keys. Use the existing authorized SNMP configuration, without echoing secrets, network addresses, MACs, or customer names in logs, tickets or the public repository. Store only aggregate pass/fail counts in the public checkpoint. This collection has **not** been executed in this session.

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
