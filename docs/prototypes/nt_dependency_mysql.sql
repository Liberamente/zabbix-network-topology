-- PROPOSAL ONLY: DO NOT APPLY TO PRODUCTION.
-- MySQL/MariaDB InnoDB; installer must ensure only one row for scope.
CREATE TABLE nt_dependency_state (
    scope_key VARCHAR(128) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Separate operation, after loading validated state/version:
-- UPDATE nt_dependency_state SET payload = :json, version = version + 1
-- WHERE scope_key = :scope AND version = :expected;
-- Conflict if affected_rows != 1. This is a sketch; PHP Zabbix
-- DB wrappers do not necessarily support named placeholders.
