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
