# Network Topology 5.5.27 — consolidated release candidate (2026-11-10)

**Status:** source release candidate, **not an installed Zabbix upgrade**. The module manifest remains 5.5.27. This branch is based on production/v5.5.27 at `f9f4f2999d0e21d56573c02ba98e04408ee78a0d`.

## Scope and provenance
- Common module, not a site-specific fork. Consolidates the tested FDB aggregate-port filter (former PR #19) and explicitly declared LLDP chassis aliases (former PR #20) with the previously developed stable multi-Port-ID discovery template (former PR #10).
- The official LLDP template already deployed at some installations uses `0.LocalPort.StableId` (issue #25). **Preserve** its six `[{#SNMPINDEX}]` item keys and unchanged template/item/discovery UUIDs. The multi-Port-ID improvement retains the same StableId for the normal unique-chassis case.
- Do **not** cherry-pick former PR #21: it changes six keys to `[{#NT_NBR}]` and causes an unnecessary one-time item recreation.
- Adds portable copies of two optional evidence-only templates already used in validation: `NT - LLDP Local Ports by SNMP` and `NT - LLDP Remote Evidence by SNMP`. No customer SNMP data, host addresses or credentials are stored. Both are read-only collectors and publish no topology edges.
- Preserves the pre-existing `templates/nt_lldp_local_ports_template.yaml` as a legacy alternative for backward compatibility. **Never attach both local-port templates to the same host**, because their `nt.lldp.local.portid` and `nt.lldp.local.portdesc` item keys overlap. Neither is a replacement for the official LLDP discovery template.

## Link provenance and scope
- LLDP/CDP verified observations retain their source and per-port identity; an LLDP observation alone is not necessarily reciprocal confirmation.
- FDB/ARP connections remain inferred, never proof of a direct cable. No synthetic physical links for visualization. Aggregate or shared ports are not promoted to direct access ports.
- Wireless associations, manually declared links, existing trunk/LAG handling, and group/proxy isolation must retain precedence and receive regression testing.

## LLDP migration caveat
For the ordinary single chassis on one local port, the new LLD StableId is unchanged. When **one chassis advertises two distinct remote Port-IDs on the same local port**, both observations are retained, each with a separate identity. When the neighbor transitions from one to multiple remote Port-IDs (or back), affected discovered item keys may change: this is an explicit known migration risk, not a change in physical cabling. Review the LLDP-Q evidence instead of manufacturing extra physical edges. Consider the dynamic multiport case not fully release-gated until replay against supported platforms.

## Deployment gate and rollback
1. Export and securely retain the currently active official LLDP template **and** the two optional evidence templates, inventory the linked hosts/proxies, capture topology counters and list of discovered item keys. Obtain a maintenance window and explicit environment-level authorization before import.
2. Compare existing template group UUIDs by **name** before import: the portable upstream `Templates/Network devices` group UUID may differ from the installed server. Do not create a duplicate group or overwrite another group. In the Zabbix import preview, protect all existing templates/items; never enable Delete missing.
3. Back up the installed frontend and its two PHP files; compare the source SHA and PHP lint. Install only after separate customer/regression validation; no restart unless required by the PHP runtime cache.
4. Test LLDP/CDP before/after, including multiport/trunks, one-sided evidence, FDB aggregate filtering, Wi-Fi and manual overrides. Match observations by chassis/port with timestamps, not just edge counts.
5. To roll back PHP, restore the verified archived module/files atomically; to roll back the template, re-import the previously exported version and account for lost/disabled discovered items. Restoration of an old template does not automatically restore item history.

## Verification state
- Release candidate code is published in GitHub source only. Automated checks must be checked on the **exact final commit** before promoting it.
- **No global Zabbix template import or module deployment** is authorized by creating this branch or PR.
- No claim is made that the historically absent links have reappeared; LLDP field validation and cross-site acceptance remain open.
