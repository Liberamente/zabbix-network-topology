# Phase 6 — Scoped dependency replacement (feature branch)

Verified source on GitHub before editing: `ManualDependencies`, `NetworkTopologyDependencies`, `DependencyWriteValidator` and `SharedConfig`.

## Behavior
- `DependencyScopeMerge::replace` retains all previously stored relations
  unless BOTH endpoints are in the current server-derived group scope.
- New input replaces only wholly in-scope relations (empty list removes only these).
- Cross-scope relations are retained, never silently deleted.
- Action checks the combined graph for validity, proxy consistency and cycles.
- Existing CSRF, super-admin, revision, throttle and request bounds remain.
- No changes to LLDP/CDP, FDB, Wi-Fi, trunk, Zabbix trigger dependencies, or UI.

## Validation still required
- The action remains UNREGISTERED in `manifest.json`.
- Syntax and synthetic tests need execution on Mac.
- Zabbix API permission and HostGroup.get behavior require staging verification.
- Concurrency between reading the current config and SharedConfig.write is
  NOT transaction-safe; strict revision checking does not fix that race.
- Scope is a union of group hosts: adding relations across selected groups
  requires a separate product-policy decision.
- SharedConfig currently has a fallback to bootstrap config if fresh API read
  fails; not acceptable for sensitive writes without further review.
- Need a read API contract, CSRF issuance, integration tests, and backup/rollback
  plan before registration/deployment.

## Tests
```sh
php -l topology/DependencyScopeMerge.php
php -l tests/DependencyScopeMergeTest.php
php -l actions/NetworkTopologyDependencies.php
php tests/DependencyScopeMergeTest.php
php tests/DependencyWriteValidatorTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

Rollback: revert the phase-6 commits on the feature branch; no production
deployment was made. Existing production remains unchanged.
