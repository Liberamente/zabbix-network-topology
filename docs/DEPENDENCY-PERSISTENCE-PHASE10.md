# Phase 10 — atomic storage contract (NOT deployed)

## Verified source
- Zabbix 7.0 frontend modules support custom controller actions; they cannot
  register or modify a native Zabbix API method (official module documentation).
- Zabbix frontend DB PHP infrastructure exposes transaction helpers in its
  source, but this does not establish a supported migration or a durable
  storage schema for this module.
- Existing module.config read-modify-write lacks a proven CAS guarantee.

## Branch changes
- `topology/DependencyAtomicStore.php`: minimal interface for a versioned
  snapshot and atomic conditional replacement.
- `tests/DependencyAtomicStoreContractTest.php`: deterministic synthetic
  stale-writer rejection and reset model.

## Meaning of the test
PASS establishes that the **abstract contract** can reject a stale writer
in a sequential simulation. It does not demonstrate cross-process concurrency,
correct DB locking, staging compatibility, or a functional Zabbix backend.

## Proposed backend acceptance criteria
1. Independent schema or storage is installed only with explicit staging
   authorization; keep module.config keys unchanged.
2. Same-store version test and replacement are one atomic DB operation.
3. Scope merge, cycle and host authorization must use the current version,
   ideally within a transaction; no hidden cross-group relations exposed.
4. Atomic semantics proven on supported MySQL/MariaDB and PostgreSQL targets.
5. Readonly users cannot write; unavailable storage fails closed.
6. Installation/upgrade/uninstall, backup, rollback and frontend HA tested.
7. Endpoint remains UNREGISTERED until all gates pass.

## Mac test
```sh
php -l topology/DependencyAtomicStore.php
php -l tests/DependencyAtomicStoreContractTest.php
php tests/DependencyAtomicStoreContractTest.php
php tests/DependencyContentionModelTest.php
```

Rollback: revert phase-10 commits from feature branch; production unchanged.
Canonical Project files unavailable to this session; update 01, 03, 04, 06
using checkpoint conventions when source documents are accessible.
