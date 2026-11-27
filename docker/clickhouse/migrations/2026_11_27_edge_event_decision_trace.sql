ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS client_key String DEFAULT '' AFTER shadow_rule,
    ADD COLUMN IF NOT EXISTS snapshot_sequence UInt64 DEFAULT 0 AFTER client_key;
