# Phase 7 — Fresh revision recheck (draft, not atomic)

## Changes
- New `SharedConfig::writeChecked` obtains a fresh config from
  `API::Module()->get`, refuses read failure instead of reverting to bootstrap,
  and compares current dependency revision before update.
- `ManualDependencies::saveSharedChecked` uses this method; the draft action
  now passes its client revision into this fresh-check write path.
- Existing `SharedConfig::write` remains unchanged for unrelated module writes.

## Critical limitation
This is a *freshness check*, NOT a transaction or compare-and-swap. Two
simultaneous workers may both pass the check and update the same module.config;
other key writers could race as well. Do not register the action, enable its UI,
or deploy to production until cross-worker serialization/transaction semantics
are designed and tested in Zabbix 7.0 staging.

## Local verification
```sh
php -l topology/SharedConfig.php
php -l topology/ManualDependencies.php
php -l actions/NetworkTopologyDependencies.php
php tests/DependencyScopeMergeTest.php
php tests/DependencyWriteValidatorTest.php
php tests/ManualDependenciesTest.php
php tests/DependencyResolverTest.php
```
Further tasks: Zabbix API contract test; stale and simultaneous writer simulation;
read endpoint with group/proxy filtering and CSRF/revision contract; inspect
full-config races with unrelated existing writers. No manifest registration,
merge, trigger edits or deployment.
Rollback: revert phase 7 commits on feature branch. Production unaffected.
