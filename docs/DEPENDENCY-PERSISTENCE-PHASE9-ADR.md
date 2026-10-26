# Phase 9 — Persistence architecture decision record (proposal, NOT deployed)

Date: 2026-10-26. Branch base: phase 8 commit `02dc2e29e6b6763aa7f5721fe6dc281dd44edeb5`.

## Verified from branch source
- `SharedConfig::write` and `writeChecked` operate on the entire
  `module.config` JSON document through `API::Module()->update`.
- Other module features write independent keys of this same JSON document.
- New dependency write action is not registered in `manifest.json`.
- Synthetic contention test demonstrates lost-update interleavings.
- No proven conditional-compare-and-update primitive for `module.config`
  has been identified in the existing module code.

## Alternatives

| Alternative | Cross-node atomicity | Compatibility impact | Decision |
|---|---|---|---|
| Per-process mutex or local flock | No | Low | Reject |
| DB advisory lock ONLY in dependency action | Not for other writers | Medium | Reject |
| DB-backed lock wrapping ALL module.config writers | Possible if same lock scope/connection is guaranteed | High; all writers need adaptation and audit | Hold for staging prototype |
| Separate dependency storage with DB transaction/CAS | Possible; independent from existing config JSON | Schema, packaging, migration and Zabbix extension API support needed | Preferred candidate for investigation |
| Dedicated external persistence service | Possible but adds deployment/runtime dependency | High | Reject for portable module |

## Recommendation (design hypothesis, NOT a completed decision)

Investigate an **independent versioned dependency store** that supports a
transactional compare-and-swap operation. Keep `manual_links`, positions and
other `module.config` keys unchanged. A correct write algorithm would:
1. Authorize the actual current Zabbix user, group and proxy scope server-side.
2. In a DB transaction obtain a row lock or perform a conditional
   `UPDATE ... WHERE version = expected_version` on the dependency store.
3. Load current dependencies inside that transaction; merge only the
   authorized scope and revalidate the complete graph.
4. Commit the new dependency document and increment version atomically.
5. Return HTTP conflict on version mismatch; never silently overwrite.
6. Read with matching group/proxy filters and never expose hidden endpoints.

This is an algorithm sketch: storage must first be checked against Zabbix 7.0
module security conventions, DB support (MySQL/MariaDB/PostgreSQL), installation,
upgrades, read-only roles and all deployment topologies. No direct SQL or schema
change is authorized by this proposal. Avoid claiming Zabbix Module.update
supplies CAS or that a file lock spans distinct frontend hosts.

## Staging validation gates
- Identify DB interface and module install/migration capabilities supported
  by the target Zabbix release; verify on a disposable staging instance.
- Inventory ALL module.config write callers and prove the new store doesn't
  share their read-modify-write document.
- Run two concurrent writes to the *same* dependency scope: exactly one
  succeeds; one gets a deterministic revision conflict.
- Run independent scopes concurrently: no loss of either group's relations.
- Run dependency writer concurrently with manual_links/positions: no lost key.
- Verify crash between read and write, transaction rollback and timeout.
- Verify cross-proxy, unauthorized group, hidden hosts, stale revision,
  cycle and invalid FDB-evidence rejection.
- Exercise read API and graph rendering with synthetic verified vs inferred
  links, Wi-Fi and multiple trunk/LAG edges.
- Verify upgrades, module uninstall/reinstall, backup/restore and rollback.
- Never write real client names, IPs, secrets or live topology into public repo.

## Current safe posture
Endpoint stays unregistered; no merge, deployment, trigger changes, or DB
modifications. Continue pure read-only review until staging storage support is
established. The architecture must remain a single portable module across
Techsigno, Cardinale and future customers.

## Checkpoint update suggestion
Copy findings to 01_STATO_CORRENTE, design hypothesis to 03_DECISIONI,
branch/commit and rollback to 04_GIT_E_RELEASE, and gates to 06_BACKLOG.
Those canonical documents were not found in the currently accessible Project
file index; this record does not claim to update them.
