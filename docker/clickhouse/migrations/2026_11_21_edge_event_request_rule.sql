ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS request_rule LowCardinality(String) DEFAULT '' AFTER security_rule;
