# Phase 15 — guarded PDO vendor laboratory adapter

- Added `topology/DependencyPdoLabStore.php` implementing the existing
  versioned snapshot/CAS interface for PDO MySQL/MariaDB and PostgreSQL.
- Uses parameter binding exclusively; one conditional UPDATE with
  `WHERE version = ?`, success requires exactly one affected row.
- Requires explicit disposal assertion, validated `nt_lab_...` DB name,
  and compares that name with the *actual* connected database.
- Rejects unsupported drivers and missing initialized state. No DDL, seeding
  or database connection scripts are shipped.
- Added `tests/DependencyPdoLabStoreGuardTest.php` to demonstrate rejection
  of an unsupported SQLite driver (not a live vendor test).

## Important safety caveat
The guard is defense in depth, NOT proof of a non-production server. A
production database could be named `nt_lab_...`; do NOT connect without
independently verifying server identity and explicit disposable database
authorization. Constructors receive an existing PDO connection; avoid using
Zabbix runtime credentials. Do NOT use this adapter in production.

## Incomplete prerequisites
- No real MySQL/MariaDB or PostgreSQL test yet.
- No multi-process vendor test, rollback, table provisioning or migration.
- No auth/scope checks within adapter; callers must enforce those checks.
- No Zabbix frontend DB abstraction integration.
- No `manifest.json` registration, no live schema changes, no deployment.

## Local checks
```sh
php -l topology/DependencyPdoLabStore.php
php -l tests/DependencyPdoLabStoreGuardTest.php
php tests/DependencyPdoLabStoreGuardTest.php
php tests/DependencyCasSqlTest.php
php tests/SqliteDependencyConcurrentProcessTest.php
```

Rollback: revert phase-15 branch commits. Production remains unchanged.
Checkpoint: update canonical 01/03/04/06 docs once accessible; this file
does not mean those documents were changed.
