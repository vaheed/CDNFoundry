ALTER TABLE cdnf.edge_events
    ADD COLUMN IF NOT EXISTS attack_score UInt8 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS attack_score_sqli UInt8 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS attack_score_xss UInt8 DEFAULT 0,
    ADD COLUMN IF NOT EXISTS attack_score_rce UInt8 DEFAULT 0;
