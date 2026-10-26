-- PROPOSAL ONLY: DO NOT APPLY TO PRODUCTION.
CREATE TABLE nt_dependency_state (
    scope_key VARCHAR(128) PRIMARY KEY,
    version BIGINT NOT NULL DEFAULT 0,
    payload TEXT NOT NULL
);
-- UPDATE nt_dependency_state SET payload = :json, version = version + 1
-- WHERE scope_key = :scope AND version = :expected;
-- Conflict if exactly one row was not updated.
