ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS query_hash String DEFAULT '';
