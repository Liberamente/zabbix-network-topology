# Phase 18 — Decision: defer concurrency locks

Date: 2026-10-27. Decision requested by project owner.

## Decision
- Do not implement MySQL GET_LOCK, row locking, cross-process mutexes, new tables, or schema migrations in the present development phase.
- Keep the existing module.config storage and current SharedConfig implementation unchanged.
- Focus development effort on discovery integration, correct topology edges and dependency rendering in the single common portable module.
- Revisit concurrency control as an optimization when the module sees actual shared editing by multiple people or groups; record as a backlog item, not as a completed fix.

## Known technical risk and release gate
- Concurrent read/modify/write operations on the shared module.config JSON can overwrite another writer's changes. Synthetic contention tests already reproduced the issue.
- The existing revision check reduces stale writes but is NOT atomic. Deferring locking does not make concurrent writes safe, even in a single-user installation with multiple browser tabs.
- The draft dependency write endpoint stays UNREGISTERED / disabled until a deliberate release decision and appropriate safety acceptance tests. Never advertise the draft as concurrency-safe.
- Existing writes for manual links, node positions and color scales are unchanged.

## Architecture invariants
LLDP/CDP verified physical links stay distinct from FDB/ARP inferred links, Wi-Fi links and manually confirmed dependencies. Preserve parallel trunk/LAG edges and group/proxy visibility. Never infer a certain physical cable from MAC learning.

## Scope / deployment
Documentation-only branch checkpoint: no code changes, no MySQL modifications, no merge, no deployment. Techsigno and Cardinale remain validation environments of the same module. Update canonical 01_STATO_CORRENTE, 03_DECISIONI, 04_GIT_E_RELEASE and 06_BACKLOG separately when accessible; this checkpoint does not edit those files.

## Rollback
Revert this commit to undo the documentation checkpoint; production remains unchanged.
