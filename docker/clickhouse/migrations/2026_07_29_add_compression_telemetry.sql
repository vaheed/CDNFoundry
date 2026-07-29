ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS compression_encoding LowCardinality(String) DEFAULT 'identity',
    ADD COLUMN IF NOT EXISTS compression_ratio Float32 DEFAULT 1,
    ADD COLUMN IF NOT EXISTS compression_profile LowCardinality(String) DEFAULT 'off',
    ADD COLUMN IF NOT EXISTS compression_fallback LowCardinality(String) DEFAULT 'none';
