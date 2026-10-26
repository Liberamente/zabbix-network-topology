# Phase 14 — vendor SQL contract (laboratory only)

## Verified
- Zabbix 7.0 module frontend actions are supported. The module cannot add native API methods (Zabbix 7.0 docs).
- Frontend DB helper functions exist, but `DBexecute` returns boolean; no PDO-style parameter binding or affected-rows contract has been established for these helpers.
- Previous phase 13 SQLite test proved single-winner CAS under two PHP processes in an isolated temporary DB.

## This change
- `topology/DependencyCasSql.php` returns parameterized SQL templates (three
  bound parameters: payload, scope key, version) for mysql and pgsql.
- `tests/DependencyCasSqlTest.php` checks template characteristics and
  refuses unsupported drivers. Both are lab-only: do NOT concatenate values.
- No MySQL or PostgreSQL database adapter has been implemented. A future
  adapter must bind all parameters, handle affected-row semantics, restrict
  versions to supported numeric range, and supply correct transactional
  read/merge/revalidate/CAS behavior.

## Design caveat
The existing Zabbix frontend database API is NOT the same as PDO. The template
cannot safely be passed directly to `DBexecute()`. Implementing an adapter
requires documenting which DB connection and binding API it uses and how to
install a custom schema across Zabbix upgrades and vendor backends.

## Next gates
1. Build an isolated PDO lab adapter for MySQL/MariaDB and PostgreSQL only
   after obtaining disposable database targets (not production).
2. Two-process test per vendor, transaction failure and rollback test.
3. Document Zabbix frontend credential/DB abstraction and safe deployment.
4. Only then evaluate integration with auth/scope/cycle validator and action.
5. No manifest action registration or production deployment.

## Local commands
```sh
php -l topology/DependencyCasSql.php
php -l tests/DependencyCasSqlTest.php
php tests/DependencyCasSqlTest.php
php tests/SqliteDependencyConcurrentProcessTest.php
```

Rollback: revert phase-14 GitHub commits; no database or production changes.
Canonical project governance files were not altered.
