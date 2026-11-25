ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS waf_rule_ids String DEFAULT '' AFTER waf_rule_id;
