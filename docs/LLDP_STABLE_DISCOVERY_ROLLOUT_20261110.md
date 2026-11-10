# LLDP stable discovery consolidation — release and rollback gate

Source: `production/v5.5.27` @ `f9f4f2999d0e21d56573c02ba98e04408ee78a0d`.
Consolidation: draft PR #23, incorporating closed unmerged PR #21 and safeguards from #10.

## Scope and source compatibility

- One common `templates/nt_lldp_snmp_template.yaml`; preserve all six installed LLDP prototype UUIDs and original `[{#SNMPINDEX}]` prototype keys.
- Installed Zabbix 7.0 export (user supplied) already derives `{#SNMPINDEX}` from `0.LocalPort.FNV32(LocalPort|identity)`, where `identity` is `chassis=<normalized chassis>` when present, otherwise `port=<normalized Port-ID>|name=<normalized SysName>`, otherwise `name=<normalized SysName>`, otherwise `rem=<RemIndex>`. Normalization trims, lowercases, and collapses whitespace; MAC separator variations are **not** normalized away.
- Revised PR #23 uses the **installed normalization and hashing** for the unique-neighbor and selected multiport-primary cases; `{#NT_NBR}` is an informational alias, not a prototype key. `{#NT_PORT}` and `{#NT_REMIDX}` remain current raw-walk selectors.
- For multiple Port-ID observations with one Chassis-ID and local port, lexicographically first Port-ID retains the installed chassis-only identity. Other Port-IDs get distinct `|port=`-suffixed identities. This preserves one pre-existing item identity when a second Port-ID appears but can reassign which Port-ID is primary when the set changes. That risk is unresolved without durable state.
- No automatic physical link is proven by more than one LLDP observation on a local port. Frontend behavior for parallel advertisements must be confirmed before importing.
- CDP discovery and two CDP prototype keys remain unchanged. Other LLDP local-port and remote-evidence templates remain separate optional collectors.

## Compatibility warning — approval mandatory before Zabbix import

**The prototype keys are now unchanged**, addressing the high-impact source of churn in the original PR #21. Tests on synthetic inputs compare candidate stable identities with the exported installed algorithm for a unique chassis and the selected primary multiport observation.

This is NOT a global no-churn guarantee: a multi-Port-ID set can reassign the primary identity when its sorted membership changes, missing or reformatted chassis identifiers can change keys, hash collisions fail closed, and earlier device-level installed variants are not all independently verified. Changes in observed logical-port count can create new discovered items on some hosts. The 245 hosts with inherited LLDP rules must not be imported automatically.

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

**Resolution in the current candidate:** item prototype keys remain `{#SNMPINDEX}` and the installed hash algorithm is reproduced for unique chassis neighbors. For multiple logical Port-IDs, one deterministic primary observation keeps the installed chassis-only key, while additional variants receive separate keys. The selection of a primary Port-ID remains a potentially disruptive edge case and requires read-only replay before rollout.

The export proves the template content but not its deployment scope; the connected Zabbix API does not expose `template.get` for the current identity. The installed export must remain private and is not included in the public repo.

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
