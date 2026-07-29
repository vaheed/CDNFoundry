ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_profile LowCardinality(String) DEFAULT 'off';
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_rule_id UInt32 DEFAULT 0;
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_score UInt16 DEFAULT 0;
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_action LowCardinality(String) DEFAULT 'off';
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_processing_us UInt32 DEFAULT 0;
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_body_limit LowCardinality(String) DEFAULT 'none';
ALTER TABLE cdnf.edge_events ADD COLUMN IF NOT EXISTS waf_exclusion_id UInt64 DEFAULT 0;
