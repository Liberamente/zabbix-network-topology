# Phase 12 — disposable SQLite compare-and-swap laboratory

## What exists on this feature branch
- `topology/SqliteDependencyLabStore.php`: PDO SQLite adapter implementing
  `DependencyAtomicStore` with version-conditional UPDATE and affected-row
  result check. It is intentionally not included in production actions.
- `tests/SqliteDependencyLabStoreTest.php`: creates a temporary SQLite
  database and two independent PDO connections; verifies stale-write rejection,
  preservation of separate scoped dependencies, and invalid-version rejection.
  Fixture host IDs are synthetic.

## Limits (important)
- This test arranges the two connections sequentially after both read the
  same revision. It is NOT a truly simultaneous multi-process stress test.
- SQLite is a disposable technical laboratory, NOT a chosen Zabbix backend.
- It does not implement Zabbix authorization, whole-graph revalidation,
  transactionally-scoped merge, frontend DB utilities, schema migration,
  multi-web-node behavior, or MySQL/PostgreSQL integration.
- Installation of schema in Zabbix is NOT authorized. Do not execute the SQL
  sketches from phase 11 on production.
- `pdo_sqlite` missing => test exits with code 77 and SKIP, not PASS.

## Mac commands
```sh
php -m | grep -i sqlite
php -l topology/SqliteDependencyLabStore.php
php -l tests/SqliteDependencyLabStoreTest.php
php tests/SqliteDependencyLabStoreTest.php
php tests/DependencyAtomicStoreContractTest.php
php tests/DependencyScopeMergeTest.php
php tests/DependencyWriteValidatorTest.php
```

## Next gate
Prototype two simultaneous workers with independent processes; then review
actual Zabbix 7 frontend DB abstraction in staging and implement a
vendor-compatible backend with explicit schema lifecycle and rollback.
No merge, endpoint registration, deployment, trigger changes or DB writes
to Zabbix. Rollback: revert phase-12 GitHub commits from feature branch.
