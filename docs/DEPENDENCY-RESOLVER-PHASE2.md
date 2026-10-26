# Dependency Resolver - phase 2 checkpoint

## Verified in GitHub
- ManualLinks uses module.config key `manual_links` for shared undirected links and CProfile for personal links.
- SharedConfig merges a single config key through the Zabbix module API.
- NetworkTopologyLinks action already protects manual link writes with POST, AJAX, CSRF, rate limits, revision checks and super-admin scope.
- Manual links may point to ghost nodes; confirmed dependencies in the initial proposal deliberately require numeric monitored host IDs.

## Added in this phase (development branch only)
- `topology/ManualDependencies.php`: a separate `manual_dependencies` module config key, sanitizer, shared loader and writer.
- `tests/ManualDependenciesTest.php`: synthetic validation regression.
- No manifest route, frontend control, data payload or production code path invokes this storage class yet.
- Existing manual links and source-specific LLDP/CDP/FDB/Wi-Fi edges remain unchanged.

## Must be addressed before enabling writes
1. Implement a dedicated write action with POST/AJAX, CSRF, request-size limits, throttle,
   super-admin requirement, optimistic revision, and safe error handling.
2. Validate all saved endpoints against the administering user's permitted hosts and
   same-proxy rule. Merely sanitizing JSON shape is insufficient.
3. Define how shared dependencies are displayed under group-scoped visibility.
4. When resolving, require matching scoped hosts and reject cycles. For redundancy,
   never infer impacts by simple reachability alone.
5. Decide whether manual dependency confirmation should optionally validate an
   existing manual-link path; never mistake raw FDB co-learning for direct wiring.
6. Keep Zabbix trigger dependencies separate from this visual metadata.

## Local validation (not yet executed for phase 2)
```sh
php -l topology/ManualDependencies.php
php -l tests/ManualDependenciesTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```

## Rollback
No deployment or DB schema changes. Revert new commits or delete feature branch;
before release, keep previously deployed production commit untouched.
