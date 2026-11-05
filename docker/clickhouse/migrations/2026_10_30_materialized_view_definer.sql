-- Rollups are written with the schema owner's rights, so the insert-only ingest
-- account needs no privilege on the rollup tables.
ALTER TABLE cdnf.edge_hourly_mv MODIFY SQL SECURITY DEFINER;
ALTER TABLE cdnf.dns_hourly_mv MODIFY SQL SECURITY DEFINER;
ALTER TABLE cdnf.edge_daily_mv MODIFY SQL SECURITY DEFINER;
ALTER TABLE cdnf.dns_daily_mv MODIFY SQL SECURITY DEFINER;
