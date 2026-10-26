# Phase 13 — real-process SQLite CAS lab (not Zabbix integration)

Two distinct PHP CLI processes and two distinct PDO SQLite connections
read the same version zero and synchronize on file barriers, then attempt
conditional UPDATE against the same temporary SQLite DB file.

Expected result: exactly one WIN, one CONFLICT, version exactly 1 and one
complete document. Temporary files are deleted by the test. Synthetic host
IDs only. A test PASS establishes contention behavior for SQLite in this
Mac environment, not MySQL/PostgreSQL, not frontend multi-node deployment,
and not module permissions, scoped graph validation or trigger behavior.

Files:
- tests/SqliteDependencyConcurrentWorker.php
- tests/SqliteDependencyConcurrentProcessTest.php

Mac verification:
```sh
php -l tests/SqliteDependencyConcurrentWorker.php
php -l tests/SqliteDependencyConcurrentProcessTest.php
php tests/SqliteDependencyConcurrentProcessTest.php
php tests/SqliteDependencyLabStoreTest.php
php tests/DependencyAtomicStoreContractTest.php
```
Requires PHP CLI with pdo_sqlite and proc_open. Exit 77 means SKIP, not PASS.

Known remaining gates: test on supported Zabbix databases and on staging,
DB schema migration and rollback, authorization from Zabbix server-side,
atomic scope merge in a production backend. No manifest route, DB changes,
merge or production deployment. Rollback: revert phase 13 commits.
