# LLDP stable discovery consolidation — release and rollback gate

Source: `production/v5.5.27` @ `f9f4f2999d0e21d56573c02ba98e04408ee78a0d`.
Consolidation: draft PR #23, incorporating closed unmerged PR #21 and safeguards from #10.

## Scope

- One generic `templates/nt_lldp_snmp_template.yaml` shared across sites.
- Preserve the six existing LLDP prototype UUIDs, CDP discovery, and existing SNMP walk regex lookup.
- `{#NT_NBR}` derives its stable neighbor identity from local port, chassis and remote Port-ID. `{#SNMPINDEX}` still represents current local port/RemIndex for diagnostics; `{#NT_PORT}` and `{#NT_REMIDX}` select raw walk rows.
- Multiple LLDP Port-ID advertisements may indicate logical interfaces or a shared segment; they DO NOT imply separate verified physical cables.
- Non-LLDP FDB/ARP inferred, Wi-Fi and trunk/LAG semantics are outside the scope of this change.

## Compatibility warning — mandatory approval before import

**The six item prototype keys change their discovery token from `{#SNMPINDEX}` to `{#NT_NBR}`.** Keeping their UUIDs does not preserve discovered item IDs or historical continuity: existing discovered items may be marked lost and replacements may be created, including across all linked tenants, groups and proxies. The installed Zabbix template and the reported 244 linked hosts have not yet been queried or changed as part of this PR.

The standalone `NT - LLDP Local Ports by SNMP` and `NT - LLDP Remote Evidence by SNMP` templates in the exported three-template bundle serve different evidence-collection roles and are not replaced by this discovery template.

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

## Controlled pilot and rollback (not executed)

- Import a separately named pilot only into a test host, never onto the globally linked template; capture template/item count diffs and LLD results, then restore the isolated test host.
- Obtain explicit approval for global import only after pilot results and inventory are reviewed.
- During any authorized import: retain the before-export, record start time, templates, host linkage, lost/discovered items and item statuses. Verify CDP and LLDP separately, and review topology semantics in Techsigno, Cardinale and SOA.
- For failure: stop rollout, recover template definition from the before-export and compare LLD-discovered resources; note that merely reimporting a template does not automatically recover deleted historical items or item IDs. Restore from a verified Zabbix database backup only under a separately approved recovery procedure.

## Publication gate

- GitHub Actions `gates` and packaging must pass on the exact release commit.
- No customer names, addresses, MACs, credentials, or real network captures in public fixtures.
- Merge, tag and GitHub release must reference the tested commit; no production deployment is implied.
- Canonical project checkpoint documents should be updated from the final tested commit. Those files were not available at the repository root during this review.
