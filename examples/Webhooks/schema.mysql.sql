-- MySQL / MariaDB schema for TransactionalWebhookProcessor / OutboxWorker.
-- Define indexes with their table: MySQL has no CREATE INDEX IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS webhook_events (
    dedup_key   VARCHAR(80)  NOT NULL PRIMARY KEY,   -- WebhookPayload::deduplicationKey()
    entity_key  VARCHAR(191) NOT NULL,               -- WebhookPayload::entityKey()
    received_at VARCHAR(40)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS webhook_entities (
    entity_key   VARCHAR(191) NOT NULL PRIMARY KEY,  -- invoice:<id> or subscription_*:<id>
    status       VARCHAR(40)  NULL,
    modified_at  VARCHAR(40)  NULL,                  -- last applied payload modifiedDate (never cleared)
    wallet_status VARCHAR(20) NULL,                  -- walletData.status: new | created | failed
    wallet_id    VARCHAR(191) NULL,                  -- walletData.walletId
    last_payload TEXT         NULL,                  -- WebhookPayload::safeArray() as JSON
    updated_at   VARCHAR(40)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS webhook_outbox (
    id              BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(80)  NOT NULL UNIQUE,    -- one job per event
    entity_key      VARCHAR(191) NOT NULL,
    job_type        VARCHAR(80)  NOT NULL,           -- e.g. invoice.success
    payload         TEXT         NOT NULL,
    attempts        INTEGER      NOT NULL DEFAULT 0,
    leased_until    INTEGER      NULL,               -- unix timestamp, set per lease
    lease_owner     VARCHAR(32)  NULL,               -- token of the worker holding the lease
    done_at         VARCHAR(40)  NULL,
    last_error      TEXT         NULL,
    created_at      VARCHAR(40)  NOT NULL,
    INDEX webhook_outbox_due (done_at, leased_until, id),
    INDEX webhook_outbox_entity (entity_key, id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
