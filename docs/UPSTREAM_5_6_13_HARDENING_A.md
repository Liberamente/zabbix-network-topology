# Upstream 5.6.13 hardening — Phase A (isolated candidate)

Date: 2026-11-25. STATUS: **SOURCE PATCH + SYNTHETIC TESTS ONLY**.
No deploy, no Zabbix configuration change, no templates, no production approval.

## Lineage

- Fork: Liberamente/zabbix-network-topology (one shared portable module).
- P4 base: production/v5.5.27, SHA e5817f2558e60df60b5396580b0178edf835e523.
- Audit parent: integration/upstream-5.6.13-audit-20261125, SHA de031e2e053894a6dded5f4831618172295fc627.
- Fixed upstream: linuser/zabbix-network-topology, SHA 96f5f0a7f7b70677f73f746c8d22c3e45baf58ef (v5.6.13).
- Branch: feature/upstream-5.6.13-hardening-a-20261125.
- Audit: docs/UPSTREAM_5_6_13_INTEGRATION_AUDIT.md.

## Implemented candidate changes

1. actions/NetworkTopologyCreateHost.php: require both Zabbix Admin type and CRoleHelper::UI_CONFIGURATION_HOSTS. Group write, CSRF and API checks are unchanged.
2. actions/NtCache.php: include role ID and user type in APCu key and bump cache schema to v2 to avoid reusing old entries. Not a full host-group-rights signature: rights removed within the same role can leave the 60-second response cache stale. Do not add longer-lived snapshots without current-user host visibility revalidation.
3. tools/topo-change-sender.sh: default TLS certificate verification, optional ZBX_CACERT, explicit ZBX_INSECURE=1 lab-only escape, private 0600 temp files for credentials/cookie/large response, no password or graph JSON passed as process argv. All existing trapper item keys retained.

## Deliberate exclusions

- UI capability flag: the P4 compiled JS context menu still relies on can_edit. The backend now rejects an Admin without host configuration rights, but that Admin can still see the create menu. Add can_create_host end-to-end, regenerate the bundle and run parity tests in a separate follow-up; do not ship source JS without synchronized assets/js/dist/nt-bundle.js.
- deploy.sh: unchanged; requires Docker/bind-mount-specific staging and rollback design.
- Single-flight and stale-while-rebuild: not imported. Additional group/proxy/visibility tests required.
- No changes to topology edge builders: preserve LLDP/CDP, FDB/ARP, Wi-Fi, VoIP, Port-ID, LAG/trunk and host/proxy scopes.

## Tests / gates

New synthetic offline tests:
- php tests/CreateHostRolePermissionTest.php: role denied/granted, user/Admin/Super Admin, exact permission checked.
- php tests/NtCacheRoleScopeTest.php: user, role, type, namespace, group isolation; schema v2; anonymous miss.
- node tests/TopoChangeSenderTest.mjs: fake curl/trapper, oversized JSON, TLS defaults/optional CA/lab bypass, private temp files and cleanup, sender keys, missing group scope denial.
- npm run ci:frontend includes Node sender smoke test.

Required before merge/deploy: npm ci, ci:lint-php, ci:test, ci:frontend, ci:eslint, ci:bundle-drift, ci:shellcheck, ci:xss, ci:templates, ci:package, ci:parity, ci:layers, ci:i18n, ci:ghost, ci:quality, ci:pipeline. Results are not yet verified; compare any failure against P4 baseline.

## Deployment and rollback

The branch is NOT deployed. Before any GO: inspect real Docker mount and active source checksums, PHP/APCu setup, sender cron usage and certificate chain; build and verify a release artifact, back up full active package and sender, test restore outside production, authorize deployment. If deployed later, restore exact pre-change runtime tree and sender, invalidate opcache/APCu as appropriate and re-run group/proxy and permission checks. Git revert alone cannot restore a manually deployed overlay.

**NO-GO for production.** Independent review, CI gates, UI capability/bundle alignment, staging acceptance and explicit operator authorization are required.

## Verified CI at PR #25 — 2026-11-25

At source commit 58e2dec694e0b779d14521fee260b2e320b90e1e:
- GitHub Actions gates run 45653339225: PHP lint/unit tests PASS; XSS tripwire + ShellCheck PASS; frontend/ESLint/parity/templates/package/layers/i18n/ghost/pipeline/bundle-drift PASS.
- Verified exact test lines in job logs: CreateHost role-permission regressions PASS, NtCache role/group isolation regressions PASS, TopoChangeSender synthetic integration tests PASS.
- Candidate package workflow run 45653339260: isolated build PASS. This is **not** a production deployment or release tag.
- PR #25 remains DRAFT. Runtime/staging acceptance and UI capability/bundle follow-up are not yet complete.
- This documentation update is a new commit; CI must be re-evaluated for its own HEAD rather than assumed.
