CREATE TABLE IF NOT EXISTS webhook_updates (
    update_id TEXT PRIMARY KEY,
    status TEXT NOT NULL CHECK (status IN ('processing', 'retry', 'done', 'uncertain')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    detail TEXT
);

CREATE INDEX IF NOT EXISTS idx_webhook_updates_created_at
    ON webhook_updates(created_at);
