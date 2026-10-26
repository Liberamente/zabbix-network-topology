# Phase 3 — secured dependency write action (not routed)

New: `actions/NetworkTopologyDependencies.php`. This file is committed but
**NOT registered in manifest.json** and is not callable from the UI.

The action is modeled on the existing `NetworkTopologyLinks` action:
POST + AJAX, dedicated CSRF token, Super Admin permission, bounded JSON array,
duplicate/invalid entry rejection, allowed Host API lookup for every endpoint,
same nonzero proxy, directed cycle rejection, optimistic revision check,
rate limit, and safe error message on storage failure.

**Security/review note:** API::Host()->get filters returned hosts for the
authenticated administrator; response count must match requested IDs. However,
this write action requires a focused Zabbix 7.0 review of authorization context,
API field support, and concurrency before adding a manifest route. The current
phase does not connect read API/UI and cannot create a dependency itself.

## Local syntax checks
```sh
php -l actions/NetworkTopologyDependencies.php
php -l topology/ManualDependencies.php
php -l topology/DependencyResolver.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

## Next gates
- Add an explicit server-rendered CSRF token source for the new route.
- Evaluate and test strict replacement semantics: empty array deletes all.
- Review permission and proxy policy (especially server-monitored hosts with
  proxyid 0), and whether shared relationships should be scoped by group.
- Test API::Host()->get as Zabbix Super Admin in staging.
- Add integration tests for CSRF, revisions, races, disallowed user and proxy.
- Resolve stale persisted dependencies on host deletion/permission changes.
- Add a separate read API field before presenting UI.
- Do not auto-create Zabbix trigger dependencies or rewrite topology edges.

## Rollback
No production deploy, route, schema or DB content change. Revert the phase 3
commit on feature branch. Keep released production commit untouched.
