# Dependency Resolver — phase 1 (development only)

## Baseline / isolation
- Repository: Liberamente/zabbix-network-topology.
- Start ref: feature/wifi-zonedirector-source-20261026.
- Working branch: feature/nt-dependency-resolver-20261026.
- Production module is **not** updated by this branch. No merge/deploy/trigger write.
- Scope: common multi-vendor dependency model, not a Cardinale-specific adapter.

## Decision
Connectivity adjacency, physical cabling and operational dependency are distinct graphs.
FDB/ARP observations alone MUST NOT establish physical cabling or directed dependency.
LLDP/CDP-verified edges, Wi-Fi associations, shared FDB ports, trunk/LAG and port metrics retain their existing semantics.

Phase 1 models only explicitly confirmed, directed connectivity dependencies.
The builder accepts caller-scoped host records; rejects unknown hosts, different/unknown proxy,
unconfirmed evidence, duplicates, loops and self-dependencies. It does not fabricate
cables or insert Cytoscape edges.

Example SYNTHETIC fixture: switch -> IP phone (integrated Ethernet bridge) -> industrial endpoint.
Two MAC addresses on the switch access port are a shared-FDB observation, NOT enough
to infer the phone -> endpoint ordering. A phone ping failure does not prove bridge failure.

## Files
- topology/DependencyResolver.php: pure validator / builder, no API access.
- tests/DependencyResolverTest.php: synthetic regression.

## Required before UI integration
1. Decide source and persistence format for administrator-confirmed relationships
   (manual-link metadata vs separate administrator-managed configuration).
2. Ensure visibility scope is applied before resolution; validate group permissions and
   proxy boundaries server-side.
3. Define a separate API field for dependency records, not the existing edge list.
4. Test cyclic and redundant paths, non-IP endpoints, lifecycle on host deletion.
5. Design impact as **potential** until telemetry validates failure; do not automatically
   create Zabbix trigger dependencies or suppress alarms.
6. Confirm display UX and rollback in staging.

## Verification / release gate
- Run: php -l topology/DependencyResolver.php
- Run: php -l tests/DependencyResolverTest.php
- Run: php tests/DependencyResolverTest.php
- Run existing FDB, LLDP, Wi-Fi and trunk tests before any wiring.
- No live test, CI pass, merge or deployment is claimed by this document.

## Rollback
Delete the feature branch before merge, or revert its commits if retained.
Existing releases and production deployment remain unchanged.
