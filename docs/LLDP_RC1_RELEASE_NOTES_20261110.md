# LLDP Consolidated RC1 — release candidate notes

**Candidate branch:** `release/lldp-consolidated-rc1-20261110`  
**Frozen source SHA:** `41d328bb57ecf6cc63e9e4f0a216b9d0f335578d`  
**Base:** `production/v5.5.27`  
**Review:** draft PR #23, not merged.

## Scope
- Consolidate one common portable LLDP/CDP template. Preserve the six installed LLDP prototype UUIDs and `lldpRem*[{#SNMPINDEX}]` keys, the two CDP prototype keys, and original single-neighbor StableId formula.
- Multiport neighbors: when distinct remote Port-IDs are advertised by one chassis on a local port, assign distinct port-specific identities to all logical observations. Never reassign the old aggregate key to a different Port-ID.
- Preserve source, confidence and physical-versus-inferred evidence distinctions in downstream topology. Multiple LLDP observations do not prove multiple physical cables.
- Include the offline verifier for opaque `HEX-STRING` Chassis-ID values and a synthetic regression demonstrating synchronous locally assigned Chassis-ID rotation. Opaque six-byte subtype-7 values are **not** presumed MAC addresses.

## Validation of frozen source
The following GitHub Actions runs completed successfully at frozen SHA:
- `gates`: PASS
- `candidate-package-p3-rc1`: PASS
- `isolated-zabbix70-lldp-import`: PASS

These checks do not substitute for real frontend visual acceptance, complete host-level LLD migration inventory, or production approval.

## Diagnostic finding (not a product change)
An independent five-query SNMP repeatability test using one working community observed two locally assigned Chassis-ID identities on each of two local ports over eight seconds. Changes occurred in the raw source responses without Zabbix discovery or the Topology module. The exact source of variability (remote LLDP content versus SNMP agent behavior) remains unknown. Do not merge or infer distinct physical links solely from repeated/changing IDs.

The temporary standalone diagnostic item used during the investigation must be removed from the pilot host, and the original inherited LLDP master confirmed unchanged. Diagnostic cleanup is an operational requirement, not a reason to rewrite the source StableId formula.

## Release status and blockers
**Candidate source branch published; not a signed/tagged GitHub Release and not a stable release.** PR #23 remains draft and unmerged.

**Blocked before stable or global deployment:**
1. Real staging frontend visual matrix including reciprocal versus inferred edges, Wi-Fi, trunk/LAG, cascading endpoints and user-group/proxy isolation.
2. Explicit host-by-host LLD key migration and history retention plan (245 inherited discovery rules observed in an earlier read-only inventory; revalidate before change).
3. Export and checksum of installed template, exact UUIDs/item IDs, backups, rollback, and approved pilot.
4. Cleanup and verification of the temporary diagnostic item.

No customer identifiers, MAC addresses, SNMP communities or raw network walk files belong in this repository. No template was imported and no production deployment is implied by these notes.

## Rollback and publication separation
Reverting the source candidate means returning the Git ref to its previously recorded SHA after review; changing an installed template is a separate production operation requiring its own backup and restore procedure. Re-importing a template does not automatically restore deleted Zabbix item history.
