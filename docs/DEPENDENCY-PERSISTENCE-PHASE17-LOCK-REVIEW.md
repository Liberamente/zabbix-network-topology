# Phase 17 — MySQL advisory lock feasibility (read-only gate)

Date: 2026-10-27.

## Verified
- Production frontend container reported PHP 8.5.4, pdo_mysql and mysqli.
- Read-only PDO connection reported remote MySQL Community 8.4.9,
  isolation REPEATABLE-READ, successful database connectivity.
- Current fork `SharedConfig.php` invokes `API::Module()->get/update`.
- Current shared writers: `ManualLinks`, `NodePositions`, `ColorScales`,
  and draft `ManualDependencies`.
- MySQL 8.4 reference manual: `GET_LOCK` locks are per-server/session, not
  released on COMMIT/ROLLBACK, released via RELEASE_LOCK or disconnect.
  Source: https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html

## Open blocker
Acquiring GET_LOCK on a NEW PDO connection does not prove serialization of
Zabbix's existing mysqli/API session. All cooperating writers must take the
same named lock and maintain it through fresh read, merge, write, release.
An unrelated API writer outside the protocol can still overwrite config.
Inspect the actual frontend Zabbix 7.0 DB connection implementation and
runtime; do not infer connection reuse from env vars.

## Safe read-only diagnostics on zabbix-web
Run locally, not via public GitHub. Do not print credentials, DB host or
private network addresses. Inspect source paths and symbols only:
```sh
docker exec zabbix-web sh -c '
find /usr/share/zabbix /usr/share/zabbix/ui -type f \
  \( -name "db.inc.php" -o -name "CModule.php" \) 2>/dev/null | head -20
'
docker exec zabbix-web php -r '
echo "mysqli=", extension_loaded("mysqli") ? "yes" : "no", PHP_EOL;
echo "pdo_mysql=", extension_loaded("pdo_mysql") ? "yes" : "no", PHP_EOL;
'
```
GET_LOCK itself obtains server state and is not read-only; avoid live lock
experiments until an isolated connection/staging plan is approved.

## Acceptance criteria
- One shared connection (or correctly coordinated connections) per request.
- Cross-process/multi-frontend locking and timeout behavior verified.
- No bypass writer; all SharedConfig writers audited.
- Lock acquisition fail closed, release in finally, connection loss handled.
- Stale revision rejected inside lock after fresh read.
- No manual CREATE TABLE, no Zabbix database schema modification.

No code change, endpoint registration, DB write, merge or deployment.
Rollback: revert this documentation commit; production unaffected.
