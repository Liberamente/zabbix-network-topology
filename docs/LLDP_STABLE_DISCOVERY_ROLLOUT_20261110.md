# LLDP stable discovery consolidation — release and rollback gate

Source: `production/v5.5.27` @ `f9f4f2999d0e21d56573c02ba98e04408ee78a0d`.
Consolidation: draft PR #23, incorporating closed unmerged PR #21 and safeguards from #10.

## Scope and source compatibility

- One common `templates/nt_lldp_snmp_template.yaml`; preserve all six installed LLDP prototype UUIDs and original `[{#SNMPINDEX}]` prototype keys.
- Installed Zabbix 7.0 export (user supplied) already derives `{#SNMPINDEX}` from `0.LocalPort.FNV32(LocalPort|identity)`, where `identity` is `chassis=<normalized chassis>` when present, otherwise `port=<normalized Port-ID>|name=<normalized SysName>`, otherwise `name=<normalized SysName>`, otherwise `rem=<RemIndex>`. Normalization trims, lowercases, and collapses whitespace; MAC separator variations are **not** normalized away.
- Revised PR #23 uses the **installed normalization and hashing** for the unique-neighbor and selected multiport-primary cases; `{#NT_NBR}` is an informational alias, not a prototype key. `{#NT_PORT}` and `{#NT_REMIDX}` remain current raw-walk selectors.
- For multiple Port-ID observations with one Chassis-ID and local port, **every** observation gets a distinct `|port=`-suffixed identity. No record inherits the legacy chassis-only identity while the group is ambiguous. This deliberately avoids silently reassigning an existing item's historical data to a different Port-ID. Going from one to multiple Port-IDs (or back) is an explicit key migration: LLD can mark historical items lost and create new ones.
- No automatic physical link is proven by more than one LLDP observation on a local port. Frontend behavior for parallel advertisements must be confirmed before importing.
- CDP discovery and two CDP prototype keys remain unchanged. Other LLDP local-port and remote-evidence templates remain separate optional collectors.

## Compatibility warning — approval mandatory before Zabbix import

**The prototype keys are now unchanged**, addressing the high-impact source of churn in the original PR #21. Tests on synthetic inputs compare candidate stable identities with the exported installed algorithm for a unique chassis and the selected primary multiport observation.

This is NOT a no-churn guarantee: transitions between one and multiple Port-IDs explicitly change item keys; missing or reformatted chassis identifiers can also change identities, hash collisions fail closed, and earlier device-level installed variants are not all independently verified. A key transition must never be mistaken for loss of the underlying physical neighbor. The 245 hosts with inherited LLDP rules must not be updated automatically.

The template export alone does not prove that every inherited host uses the same revision. Keep the source PR separate from any production rollout.

## Required preflight (read-only)

1. Identify the actual installed template UUID, templateid, discovery rule ID, six prototype UUIDs, linked host IDs and group/proxy distribution.
2. Export the installed template from Zabbix 7.0; retain backup, SHA-256 and an item inventory of keys, itemids, status, history and `lldpRem*` dependent items.
3. Compare exported template with source YAML by UUID/key, discovery and preprocessing. Inventory other templates that may publish conflicting keys.
4. Verify existing LLDP and CDP observations and one-to-many Port-ID cases using sanitized offline snapshots. Include reassociation after RemIndex/TimeMark changes and a no-identity fallback.
5. Record host-by-host expected item losses/recreations; determine whether history retention and key migration are acceptable.

## Read-only Zabbix 7.0.32 evidence — 2026-11-10

Read-only queries against the connected Zabbix API returned:
- 245 inherited `nt.lldp.rem.discovery` rules, distributed across 245 unique hosts.
- 245 inherited `nt.cdp.cache.discovery` rules, across 245 unique hosts.
- 245 inherited `lldpRemSysName[{#SNMPINDEX}]` item prototypes, across 245 unique hosts.
- 266 existing `lldpRemSysName[...]` discovered items, distributed across 22 hosts: 220 enabled, 46 disabled.
- `template.get` returned an empty list with the current API access; therefore the installed template identity, exact template link relationships and full template export have NOT been independently verified.
- The earlier planning figure of 244 linked hosts is superseded by the observed 245 inherited LLDP rules; this is not necessarily a complete template-link inventory.
- These figures are a point-in-time aggregate, not proof that importing the template will modify exactly 266 item IDs. Relearning, disabled/discovered state, inheritance and LLD lifetime may change outcomes.

**Release blocker for live template import:** inventory host linkage via an account authorized for `template.get`, collect a backup export and plan key migration. The CI result only validates repository code, not live Zabbix item/history continuity.

## Installed template export comparison (2026-11-10)

User-supplied Zabbix 7.0 export `zbx_export_templates (2).yaml` has been inspected **outside GitHub** (do not commit customer exports or network data). It contains `NT LLDP Neighbors by SNMP` UUID `38caefc2777a4ea5a8e2f9a2060c0660`, a six-column `nt.lldp.walk`, one LLDP discovery and one Cisco CDP discovery, six LLDP item prototypes and two CDP prototypes.

**Resolution in the current candidate:** item prototype keys remain `{#SNMPINDEX}` and the installed hash algorithm is reproduced for unique chassis neighbors. For multiple logical Port-IDs, each observation receives its own deterministic port-specific key. The chassis-only legacy key is not assigned to any one port during ambiguity. This prevents silent historical key-to-port reassignment but requires explicit one-to-many/many-to-one migration review.

The export proves the template content but not its deployment scope; the connected Zabbix API does not expose `template.get` for the current identity. The installed export must remain private and is not included in the public repo.

## Isolated synthetic migration replay — 2026-11-10

GitHub head `862c6d3173d6eaa54345ffafdc9c7d0e224fb766` passed `gates` run #68 and `candidate-package-p3-rc1` run #45. The `tools/check-template-logic.mjs` suite now explicitly replays changes in advertised Port-ID membership using fixture-only observations; no Zabbix API writes, uploads or customer network identifiers.

- One neighbor with a changed TimeMark/RemIndex retains its installed stable key.
- A newly added lexicographically later Port-ID creates one additional logical observation while retaining the original port's key.
- Reordering SNMP walk rows does not alter identities.
- Adding a lexicographically **earlier** Port-ID leaves all existing port-specific keys stable once the group is already multiport; the original chassis-only key is never reassigned.
- The previous implementation could silently reassign an unchanged historical key to a different Port-ID. The revised implementation prevents that by retiring the legacy chassis-only key in multiport state. **The new risk is visible item-key churn**, not silent misattribution, during transitions.
- Six LLDP prototype item key patterns and both CDP item key patterns are preserved.

**Safety gate: unresolved for live import.** The deterministic no-reassignment policy is implemented and covered by synthetic tests, but host-level item churn, Zabbix 7.0 LLD lifecycle, evidence-to-edge rendering, and rollback need isolated validation before importing a globally linked template. Passing CI does NOT certify history continuity, UI semantics, or safe production rollout.

The synthetic replay is distinct from the yet-unperformed isolated Zabbix 7.0 instance import, LLD item-ID reconciliation, frontend visual inspection, and host/proxy scope validation.

## No-silent-reassignment policy — decision record

- **Single advertised Port-ID per chassis/local port:** continue using the exact installed chassis-only StableId, preserving existing key and per-neighbor history as long as Chassis-ID representation remains unchanged.
- **Two or more distinct advertised Port-IDs:** use `chassis=<id>|port=<port-id>` in the existing FNV32 identity formula for **all** observations. Never select a primary based on string ordering. This gives independent and order-insensitive port-specific evidence identities.
- **One → many:** the old chassis-only key is not reused by any new Port-ID. The original aggregate item may become lost; new LLDP discoveries create port-specific items. The change must be counted, previewed and approved before production import.
- **Many → more (or fewer, remaining many):** existing port-specific keys stay stable regardless of which Port-ID sorts first.
- **Many → one:** the installed chassis-only key returns, with another explicit LLD identity transition.
- **No Chassis-ID:** installed Port-ID/SysName/RemIndex fallbacks remain; these may be less stable. A collision between distinct identity strings fails closed rather than joining evidence.
- **Physical topology:** multiple logical port observations are not proof of multiple physical cables or verified separate physical edges. This template only records evidence; module rendering must be visually checked in an isolated replay.
- **Privacy:** synthetic fixtures only in the public repository; customer exports and real MAC/IP addresses remain out of Git.

The above is a **source-level compatibility policy**, not proof that Zabbix's installed LLD will retain all historical item IDs. No live import has been executed.

## Additional isolated logic/UI replay and live read-only inventory — 2026-11-10

GitHub source-test commit `4f375e7c97d6335d751091b21a49269f9a7717ae` passed `gates` #74 and `candidate-package-p3-rc1` #51.

**Synthetic logic replay** (`tools/check-template-logic.mjs`):
- Exercise all six LLDP remote-walk columns for two Port-ID advertisements on one local SNMP port. Assert correct raw RemIndex value binding, distinct stable item keys, stable association across subsequent TimeMark/RemIndex changes.
- RemIndex selection is evidence retrieval, not physical topology confirmation.

**Synthetic frontend replay** (`tests/LldpPortIdUiTest.mjs`):
- Feed two separate, explicitly *inferred* LLDP Port-ID associations on one local physical port to the existing build-elements renderer.
- Both must remain inferred, preserve separate targets, have no per-link metrics and not contribute to physical trunk count.
- This test checks the renderer's existing `match=lldp_portid` path; it does **not** assert that arbitrary backend multiple-LLDP observations are automatically classified as inferred. The backend's edge creation and real Zabbix UI still need separate verification.

**Read-only Zabbix API aggregation** on current item last values:
- 265 `lldpRemPortId[...]` items across 22 hosts; 220 enabled, 234 with nonempty last values.
- 265 `lldpRemChassisId[...]` items across 22 hosts; 220 enabled, 237 with nonempty last values.
- 204 host+local-port groups with Port-ID items; 22 groups with multiple observations; 15 groups with multiple *distinct* nonempty Port-ID values across four hosts.
- **0** groups among those 15 had at least two populated identical Chassis-ID values across the observations.
- This is an aggregate of existing item `lastvalue`, not a fresh LLDP walk or current topology proof. Stale/disabled items, missing Chassis-IDs, or differing chassis formatting limit completeness. These counts do not demonstrate a zero-risk import, nor imply that multiple logical observations equal separate cables.
- No hostnames, IPs, MACs or other private identifiers were committed to this public repository.

**Current gate:** GitHub tests complete; an actual isolated Zabbix 7.0 installation/template import, reconciliation of item IDs/history, and visual frontend/backend topology validation remain **NOT EXECUTED**. The available Zabbix connector is read-only and must not be used to mutate a production template.

## PHP backend evidence-to-edge replay — 2026-11-10

Source-level replay `tests/LldpMultiportEvidenceReplayTest.php` invokes the **actual** `LldpEdgeBuilder::build()` with synthetic host/neighbor/port data (not an approximation of the backend). The test is included automatically in the existing PHP CI test loop. Exact code commit `b33fb6943e54dc11c5b5ba5e9a83b901b8b51bcb` passed GitHub `gates` run #77 and packaging #54.

- Two distinct LLDP Port-ID rows from one reporter, on one local physical port, toward **the same monitored host** yield **one** backend host-pair edge, not two falsely distinct physical trunk members. Neither observation alone confirms the edge.
- Reversing observation order preserves edge count and the unconfirmed result.
- Two LLDP reports on one local port targeting **different monitored hosts** yield two distinct reported associations; neither is confirmed by the single reporter. These are observations rather than proof of two distinct cables.
- Two distinct local reporter ports toward the same neighbor preserve two independent edge members; unilateral LLDP does not confirm them.
- Reporter list and local-port identity remain attached to the resulting edges.
- The backend's confirmed bit is derived from **two distinct reporters**, not the number of neighbor observations or available port labels.

**Important limitation:** `LldpEdgeBuilder` merges multiple same-pair/same-local-port reports into one edge; the full list of distinct remote Port-ID observations remains in Zabbix items, but is **not represented as a per-observation port list** on that merged backend edge. The tests do not establish that the current UI can display or drill down into every individual Port-ID when multiple logical interfaces share a physical uplink. Do not represent one merged edge as proof that all remote port evidence was retained on the graphic.

**Not an isolated Zabbix deployment test.** The CI PHP runner exercises real backend code in isolation, but does not start a Zabbix 7.0 database/frontend or import/update the template. Item-history migration and visual graph inspection still need explicit isolated-environment validation.

## Actual isolated Zabbix 7.0.32 API/LLD integration test — 2026-11-10

**Verified SUCCESS** at PR #23 code commit `2bbd9f3399da1f0b2dc894831a31458a074a588f`, GitHub Actions:
- `isolated-zabbix70-lldp-import` run #8: **PASS**
- `gates` run #88: **PASS**
- `candidate-package-p3-rc1` run #65: **PASS**

The new reproducible harness runs a **disposable** PostgreSQL 16, Zabbix 7.0.32 server and frontend on a GitHub-hosted runner with loopback-only API and sender endpoints. Test-only synthetic credentials, synthetic host names and synthetic LLDP identifiers are used. A cleanup step removes all containers, the ephemeral database and network even on failure.

**Real Zabbix API checks:**
1. Import the **unmodified repository YAML** `templates/nt_lldp_snmp_template.yaml` through `configuration.import`; verify the expected template UUID and all six original `lldpRem*[{#SNMPINDEX}]` item prototypes.
2. Import the unmodified YAML **a second time**; verify that the template ID and all six prototype item IDs are unchanged (import idempotence).
3. **Isolated test adaptation only:** change the `nt.lldp.walk` master item from `SNMP_AGENT` to `TRAP` **inside the disposable instance**. The LLDP discovery, its preprocessing and all six dependent item prototype definitions remain unchanged. Verify the prototype item IDs survive this transport-only test adaptation and that the CDP discovery remains inherited.
4. Create a synthetic host linked to the isolated template and transmit complete six-column LLDP walk payloads through Zabbix's native sender protocol, with retries for server configuration cache synchronization and a second value after LLD creation.
5. Verify an initial Port-ID `eth0` produces its discovered item; verify the real one→two-Port-ID transition creates distinct `eth0`/`eth1` stable keys and **new item IDs**, while the previous chassis-only item remains in Zabbix with its old ID and last value during LLD lost-resource retention.
6. Add a third alphabetically earlier Port-ID `aaa`; verify that the two already discovered port-specific keys **and their item IDs** remain unchanged, with no reattribution of a historical key. The final query returned 24 LLDP items, including retained historical/lost LLD resources.
7. Confirm the CI cleanup destroys the database and all containers.

**Scope and limitations:**
- This is a real Zabbix 7.0.32 API/import/discovery/item-ID lifecycle test, **not** a test of physical SNMP connectivity or the actual configured customer network.
- The import test uses the exact common YAML. To drive discovery with synthetic payloads the *temporary test instance alone* changes the master transport to `TRAP`; production template source remains `SNMP_AGENT`.
- The test validates retention of the prior item's ID and **last value** during the observed transition. It does not query `history.get`, audit history retention over the configured LLD expiry period, or guarantee restoration after deletion.
- A 1→many transition is **intentionally a migration** with new discovered item IDs. Any wide deployment requires a host-by-host diff, backup, LLD lost-resource retention policy review and explicit change approval.
- The backend/JS evidence tests remain separate. A genuine frontend screenshot/replay against the isolated instance, plus end-to-end validation of multiple Port-ID observation drill-down, has **not** been performed.

**Updated release gate:** isolated Zabbix import and the synthetic LLD key-to-port association checks are now PASS. Do not equate these results with production deployment authorization. Leave PR #23 draft until an authorized staging frontend/visual inspection and migration impact review are complete.

## Controlled pilot and rollback (not executed)

- Import a separately named pilot only into a test host, never onto the globally linked template; capture template/item count diffs and LLD results, then restore the isolated test host.
- Obtain explicit approval for global import only after pilot results and inventory are reviewed.
- During any authorized import: retain the before-export, record start time, templates, host linkage, lost/discovered items and item statuses. Verify CDP and LLDP separately, and review topology semantics in the authorized validation groups and proxies.
- For failure: stop rollout, recover template definition from the before-export and compare LLD-discovered resources; note that merely reimporting a template does not automatically recover deleted historical items or item IDs. Restore from a verified Zabbix database backup only under a separately approved recovery procedure.

## Publication gate

- GitHub Actions `gates` and packaging must pass on the exact release commit.
- No customer names, addresses, MACs, credentials, or real network captures in public fixtures.
- Merge, tag and GitHub release must reference the tested commit; no production deployment is implied.
- Canonical project checkpoint documents should be updated from the final tested commit. Those files were not available at the repository root during this review.
