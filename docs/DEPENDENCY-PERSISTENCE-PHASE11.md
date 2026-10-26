# Phase 11 — database feasibility and SQL proposals (NOT implemented)

Date: 2026-10-26. Code baseline: phase 10 commit `2f76c3adc145badc36272154da50de9114bc4420`.

## Evidence vs hypothesis
- Zabbix 7.0 frontend module documentation says custom PHP actions can be
  registered, but not new native API methods.
- Zabbix 7.0 frontend includes transaction-oriented DB helpers (`DBstart`,
  `DBend`, `DBselect`, `DBexecute`), checked in 7.0 source; their existence
  does NOT imply custom schema installation is automatically supported.
- Two SQL proposal files under `docs/prototypes/` illustrate a versioned
  CAS row in MySQL/MariaDB (InnoDB) and PostgreSQL. None has been executed.
- MySQL syntax uses LONGTEXT and InnoDB; PostgreSQL uses TEXT. SQL placeholder
  comments are pseudocode, not statements executable by Zabbix DB wrappers.

## Core open design issue
A per-group CAS row supports disjoint group writes, but a dependency connecting
hosts from different groups may touch multiple rows. Without a formal ownership
rule and transactional handling across every relevant row, separate per-group
versions could produce global cycles or duplicate/conflicting relationships.

Candidate simplest safe policy: maintain ONE versioned document per topology
installation, not one document per customer group. Keep the Phase 6 scoped
merge logic within one CAS transaction. This serializes writes across all groups
but guarantees no lost relations and enables global cycle validation. A high
cardinality implementation may partition later with proven rules. For now
single row's `scope_key = 'global'` is the design candidate, not deployed.

## Next staging gate
1. Approve disposable staging DB and capture Zabbix 7.0 minor build/database
   type and exact DB permissions without credentials in GitHub.
2. Test module lifecycle and schema provisioning/uninstall/backup on staging.
3. Implement adapter that uses a vendor-safe *atomic conditional UPDATE*;
   verify affected-row semantics with frontend DB utilities in both vendors.
4. Scope lookup and merge must be refreshed from snapshot that the eventual
   CAS validates. Retry is NEVER implicit on authorization failure.
5. Test with separate DB connections (not only mocked sequential calls).
6. Test no stored real host identifiers in public fixtures and no link between
   Wi-Fi/FDB evidence and physical certainty.
7. Provide schema migration and rollback without dropping existing config.

## Staging rollback
Before experiments, backup staging only. No SQL scripts run against Zabbix DB;
no changes to live schema, manifest or deployment. Existing production untouched.

## Local review
```sh
git diff 2f76c3a..HEAD -- docs/prototypes docs/DEPENDENCY-PERSISTENCE-PHASE11.md
php tests/DependencyAtomicStoreContractTest.php
php tests/DependencyContentionModelTest.php
```

Canonical Project governance documents were not confidently resolved from
current project file index; this checkpoint does not claim they were updated.
