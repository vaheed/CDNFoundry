ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS client_report String DEFAULT '';
