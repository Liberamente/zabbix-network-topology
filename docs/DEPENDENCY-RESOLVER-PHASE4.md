# Phase 4 — authorization review checkpoint (26 Oct 2026)

## Verified in feature branch
- Existing action is NOT in manifest.json.
- Super Admin check, POST/AJAX, CSRF, bounded payload, optimistic revision and
  same-proxy checks exist in the draft action.
- Crucial gap: action currently requests hosts globally through Host.get and
  does NOT require a selected group/visibility scope; do not expose route.
- Revision comparison precedes SharedConfig::write; concurrent writers can still
  race between read and write. A revision hash is not a transaction/CAS.
- Proxyid 0 (server-monitored host) is rejected by the phase-1 conservative model.
- Existing manual_links are undirected, and MUST NOT be treated as dependency order.

## New files
- topology/DependencyWriteValidator.php: pure fail-closed input/group/proxy
  validator, cycle and duplicate checks, no Zabbix API access.
- tests/DependencyWriteValidatorTest.php: synthetic cases only.

## Release blockers
1. Introduce explicit selected-group authorization and intersection with
   genuinely visible hostids in the write action; passing attacker-supplied
   scope IDs is NOT sufficient. Group data must be resolved server-side.
2. Decide policy for relationships across groups: shared stored relations may
   appear only when both endpoints are in the requested, permitted view.
3. Ensure concurrency-safe writes, or document/mitigate outstanding lost-update
   race. SharedConfig currently only best-effort fresh merge.
4. Add CSRF token distribution and read revision source for the new route.
5. Unit and staging test Zabbix 7.0 API compatibility, permission denials,
   concurrent updates, and deletion/reset of dependencies.
6. Consider canonical host IDs and alternative proxy-zero semantics.
7. Read-only UI and impact simulation must be separate from topology edges.
8. Run all prior LLDP/CDP, FDB, Wi-Fi and trunk regressions.

## Verification requested from local Mac
```sh
php -l topology/DependencyWriteValidator.php
php -l tests/DependencyWriteValidatorTest.php
php tests/DependencyWriteValidatorTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

No production deployment, manifest registration, DB changes or Zabbix trigger
writes. Rollback: revert the two new source/test commits and this checkpoint
on the feature branch.
