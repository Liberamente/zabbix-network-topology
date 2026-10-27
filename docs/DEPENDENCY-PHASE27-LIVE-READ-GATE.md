# Phase 27 — Authenticated Zabbix read-only integration gate

Date: 2026-10-27. No production deployment.

## Verified branch behavior
- `network.topology.data` is the registered read action in `manifest.json`.
- It uses authenticated frontend controller context and requires AJAX.
- `NetworkTopologyData` assembles topology from the selected visible scope.
- Experimental branch adds a separate `dependencies` response field, projected through `ManualDependencies` and `DependencyReadProjection`.
- `network.topology.dependencies` write action remains UNREGISTERED.

## Critical limitation
The production frontend mounts a different commit (`98d852a`) from the isolated dependency lab (`05ca93b` at last server audit). Therefore querying production `network.topology.data` cannot validate the new experimental `dependencies` payload. In particular, an absent field in production is expected. Never mount or replace the production module as part of this diagnostic.

## Read-only live verification protocol
1. Inspect only the server's existing frontend entrypoint and action route. Do not source the frontend PHP bootstrap from CLI as root, and do not transplant session cookies or passwords into logs or GitHub.
2. An operator with an existing authorized Zabbix browser session may inspect the Network tab response for `network.topology.data`, while avoiding publication of host names, addresses, tokens and topology.
3. For the branch's new response and real authorization checks, provision a *separately approved* frontend staging instance and disposable/copy-of-test database with synthetic hosts; do not connect an unreviewed second frontend to production MySQL.
4. Compare empty dependencies, hidden group, cross-proxy, group change, refresh and disabled write route; confirm no inferred FDB/Wi-Fi links turn into manually confirmed dependencies.

## Safety and release gates
No SQL DDL, no new schema, no lock, no new production route, no changes to existing Zabbix frontend module, no proxy changes. Follow up with visual staging evidence, build/bundle tests and separate deployment authorization. Multiwriter lost-update is accepted as outstanding risk, NOT solved.

## Checkpoint
Documentation only. Canonical Project files (01_STATO_CORRENTE, 03_DECISIONI, 04_GIT_E_RELEASE, 06_BACKLOG) require a separate explicit update when available.
