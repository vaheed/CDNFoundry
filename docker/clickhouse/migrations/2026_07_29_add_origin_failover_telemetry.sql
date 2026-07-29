ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS origin_role LowCardinality(String) DEFAULT 'primary',
    ADD COLUMN IF NOT EXISTS origin_transition LowCardinality(String) DEFAULT 'none';
