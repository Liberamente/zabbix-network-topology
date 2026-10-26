# Phase 8 — module.config contention review (design gate)

## Evidence (GitHub source, 2026-10-26)
- `SharedConfig::write` reads current module.config, merges one key, then
  issues `API::Module()->update` for the WHOLE config document.
- `SharedConfig::writeChecked` newly checks a fresh dependency revision, but
  does not make read+compare+update atomic.
- Other writers (positions, manual_links, settings) still use the shared writer.
- Draft dependency write action is not registered in manifest.json.

## Reproducible synthetic counterexample
`tests/DependencyContentionModelTest.php` constructs interleavings for:
(1) two simultaneous writes to the dependency key that both pass revision,
(2) a dependency write and a different-key config write that each overwrite
the other's outdated snapshot. This test PASS means the races are demonstrated,
NOT that serialization is implemented.

## Decision checkpoint: do not add a misleading single-endpoint lock
- Process-local mutex or `flock` works only for processes sharing its lock
  filesystem, not for multiple independent web nodes.
- An advisory DB lock on the dependency endpoint alone does not coordinate
  writers of other module.config keys.
- Locking around API read+write needs explicit guarantees about a common
  connection/lock scope, lifetime, and all participating writers.
- Zabbix's `Module.update` does not expose a documented config CAS in this
  code; do not assume atomic conditional updates.
- Existing modules need compatibility/regression review before changing the
  shared write path.

## Next engineering decision (NOT IMPLEMENTED)
Prefer an **explicitly coordinated shared writer** covering EVERY module.config
mutation with proven DB-backed serialization, or an independent storage mechanism
with a native conditional update. Requirements:
1. Multi-worker, multi-web-node correctness.
2. No lost updates to existing manual_links, positions and other config keys.
3. Fail closed on stale/failed lock acquisition or read errors.
4. Timeout, lock cleanup and recovery after process death.
5. Restrict to Super Admin for dependency writes; never trust client scope.
6. Verify against Zabbix 7.0 staging using controlled concurrent sessions.
7. Document installation/migration/rollback of storage and dependencies.

No API route enabled, no production deployment, no trigger dependency writes.

## Local check
```sh
php -l tests/DependencyContentionModelTest.php
php tests/DependencyContentionModelTest.php
php tests/DependencyScopeMergeTest.php
php tests/DependencyWriteValidatorTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

Rollback: revert the phase-8 documentation and synthetic model commits
on feature branch. No production rollback required.
