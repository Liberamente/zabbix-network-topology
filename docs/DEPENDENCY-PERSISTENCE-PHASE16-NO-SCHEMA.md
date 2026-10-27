# Phase 16 — No manual Zabbix database schema changes (decision gate)

Date: 2026-10-27. Scope: one portable module for every customer.

## User requirement
No manual CREATE TABLE/ALTER TABLE in Zabbix database. Templates must not
be treated as SQL migration mechanisms. Installation, upgrade and rollback
must be reproducible, and must never silently change the production schema.

## Verified in current fork
- Shared edges are in `module.config` through `SharedConfig::write()`.
- Personal manual links use `CProfile`, not shared authoritative network
  statements; personal storage is not an acceptable global dependency store.
- The existing `SharedConfig` API read/merge/write is not a transaction or CAS.
- The unregistered dependency action cannot be enabled safely yet.
- The phase-11 MySQL/PG SQL files and phase-12..15 adapters are LAB
  PROTOTYPES only, not deployment/migration scripts.

## Decision: explicit exploration of zero-schema options first
A. Keep `module.config`, introduce a proven **multi-node distributed
   serialization** shared across all writers of module.config. This means
   existing manual-links/positions/etc writers must participate, otherwise
   whole-document races remain. Require integration and rollback tests.
B. Store a restricted subset as native Zabbix host metadata (tags/macros):
   this would avoid schema changes but is not equivalent to an independent
   graph store. Shared directional dependencies between arbitrary hosts need
   explicit ownership, group/proxy scoping, conflict semantics, deletion,
   authorization and multi-host atomicity tests. Do not assume API host.update
   is a transactional multi-host CAS. Ghost-node or non-host relations differ.
C. Per-user `CProfile` supports personal preferences, not a global
   authoritative topology dependency graph.

## Current preference (hypothesis, not implementation)
Evaluate option A first, because it preserves the current deployment and
data model. However, never claim atomic correctness until lock ownership,
connection scope, all-writer coverage and crash cleanup are demonstrated
across independent PHP workers and (if applicable) web nodes.

If A cannot meet the guarantees, only then consider an **automatically
managed** independent store with explicit operator approval, install/migrate
and rollback procedures. SQL prototypes remain inactive.

## Mandatory next proofs
1. Audit all `SharedConfig::write` and direct `API::Module()->update`
   call sites on the actual branch.
2. Check Zabbix 7.0 DB transaction and advisory-lock connection semantics
   on source and disposable staging, without writing live SQL.
3. Demonstrate dependency + existing manual-link/position writes cannot
   overwrite one another, including multi-worker and crash tests.
4. Keep proper server-derived host group/proxy scope, no fabricated LLDP/
   CDP from FDB or inferred Wi-Fi links.
5. Maintain no manifest route, no production schema changes and no deploy.

## State & rollback
This file is a design checkpoint, not a code or DB migration. Previous branch
baseline: `4cc3991568df0d30ce79a165a6a6dec7fa7dcf64`. Revert this doc
commit to undo only this decision checkpoint. No production rollback needed.
Update canonical 01_STATO_CORRENTE, 03_DECISIONI, 04_GIT_E_RELEASE and
06_BACKLOG when those Project documents are accessible; they have not been
edited by this action.
