# Phase 5 checkpoint — group-scoped write integration

## Modified
- `actions/NetworkTopologyDependencies.php` now obtains authorized group records
  with `API::HostGroup()->get` and monitored hosts from `API::Host()->get`.
- Passes server-derived scope IDs to `DependencyWriteValidator::validate`,
  including same-proxy, no cycles, no FDB-derived assertions.
- Rejects full-list replacement when stored relationships exist outside the
  selected scope, to avoid erasing relations from other customer groups.
- Requires nonempty groupids, max 100, and checks group existence.
- CSRF, Super Admin gate, revision, payload cap, throttle unchanged.

## Known release blockers (DO NOT REGISTER MANIFEST ROUTE)
- API HostGroup.get permissions and `array_id` handling require live staging test.
- No CSRF/revision source for the new endpoint yet.
- Existing `SharedConfig::write` has a read/merge/write concurrency window.
- No endpoint-focused permission integration tests; only PHP unit tests exist.
- Group semantics: API groupids may select union, and authorized scope
  needs Zabbix 7.0 validation for mixed groups and permissions.
- Endpoint needs a precise UX for full-list replacement and deletion;
  existing relationships outside chosen scope are currently rejected.
- No support yet for proxyid 0 (server monitored), by deliberate conservative policy.
- No read-side dependency field, no UI changes, no trigger dependencies.

## Verify locally
```sh
php -l actions/NetworkTopologyDependencies.php
php tests/DependencyWriteValidatorTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

This is branch-only development with no merge/deployment and no production writes.
Rollback by reverting phase-5 commit or resetting the feature branch to its
pre-phase-5 SHA `2f8d570d8bcc9ed89214f50392bc3d141279acd0` (after coordinating
with other contributors).
