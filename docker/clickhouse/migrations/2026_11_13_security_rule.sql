ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS security_rule LowCardinality(String) DEFAULT '';
