CREATE TABLE `acme_accounts` (
  `id` bigint NOT NULL,
  `directory_url` varchar(255) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `private_key_ciphertext` text NOT NULL,
  `account_url` varchar(255),
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `acme_challenges` (
  `id` varchar(255) NOT NULL,
  `tls_order_id` varchar(255) NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `record_name` varchar(255) NOT NULL,
  `record_value` text NOT NULL,
  `status` varchar(255) NOT NULL,
  `expires_at` geometry NOT NULL,
  `cleaned_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `authorization_url` varchar(255),
  `challenge_url` varchar(255),
  `token` varchar(255)
);

CREATE TABLE `audit_logs` (
  `id` bigint NOT NULL,
  `actor_id` bigint,
  `action` varchar(255) NOT NULL,
  `subject_type` varchar(255),
  `subject_id` varchar(255),
  `metadata` geometry NOT NULL,
  `ip_address` varchar(255),
  `created_at` geometry NOT NULL
);

CREATE TABLE `backups` (
  `id` varchar(255) NOT NULL,
  `requested_by` bigint,
  `status` varchar(255) NOT NULL,
  `snapshot_id` varchar(255),
  `size_bytes` bigint,
  `manifest_sha256` varchar(255),
  `last_error` text,
  `verified_at` geometry,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `expiration` bigint NOT NULL
);

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint NOT NULL
);

CREATE TABLE `cache_purges` (
  `id` varchar(255) NOT NULL,
  `domain_id` bigint NOT NULL,
  `requested_by` bigint,
  `type` varchar(255) NOT NULL,
  `cache_epoch` bigint NOT NULL,
  `cache_keys` geometry,
  `status` varchar(255) NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `sequence` bigint,
  `operation_id` varchar(255)
);

CREATE TABLE `control_nodes` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `fingerprints` geometry,
  `replication_lag_seconds` int,
  `valkey_linked` boolean,
  `reported_at` geometry,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `control_plane_state` (
  `id` int NOT NULL,
  `epoch` bigint NOT NULL,
  `active_node` varchar(255),
  `addresses` geometry NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `dns_clusters` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `enabled` boolean NOT NULL,
  `api_url` varchar(255) NOT NULL,
  `api_key` text NOT NULL,
  `server_id` varchar(255) NOT NULL,
  `nameservers` geometry NOT NULL,
  `capacity_zones` int NOT NULL,
  `operational_notes` text,
  `last_health_status` varchar(255),
  `last_health_error` text,
  `last_health_at` geometry,
  `last_reconciled_revision` bigint NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `transfer_endpoint` varchar(255),
  `transfer_tsig_secret` text
);

CREATE TABLE `dns_deployments` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `dns_cluster_id` bigint NOT NULL,
  `desired_revision` bigint NOT NULL,
  `deployed_revision` bigint NOT NULL,
  `status` varchar(255) NOT NULL,
  `active_checksum` varchar(255),
  `active_rrsets` geometry NOT NULL,
  `last_error` text,
  `attempts` int NOT NULL,
  `last_attempted_at` geometry,
  `deployed_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `tombstone` boolean NOT NULL,
  `deprovisioned_at` geometry,
  `verified_at` geometry
);

CREATE TABLE `dns_records` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `type` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `content_hash` varchar(255) NOT NULL,
  `ttl` int NOT NULL,
  `priority` int NOT NULL,
  `weight` int NOT NULL,
  `port` int NOT NULL,
  `mode` varchar(255) NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `geo_config` geometry,
  `origin` geometry,
  `origin_health` geometry
);

CREATE TABLE `dns_zone_changes` (
  `id` bigint NOT NULL,
  `zone` varchar(255) NOT NULL,
  `serial` bigint NOT NULL,
  `deleted` boolean NOT NULL,
  `created_at` geometry NOT NULL
);

CREATE TABLE `domain_edge_cells` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `replica` int NOT NULL,
  `active_cell_id` bigint,
  `target_cell_id` bigint,
  `desired_revision` bigint NOT NULL,
  `state` varchar(255) NOT NULL,
  `drain_after` geometry,
  `last_error` text,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `domain_edge_placements` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `active_pool_id` bigint,
  `target_pool_id` bigint,
  `state` varchar(255) NOT NULL,
  `desired_revision` bigint NOT NULL,
  `drain_after` geometry,
  `last_error` text,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `domain_name_tombstones` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `source_domain_id` bigint NOT NULL,
  `deprovisioned_at` geometry NOT NULL,
  `reclaim_after` geometry NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `domain_user` (
  `domain_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `created_at` geometry NOT NULL
);

CREATE TABLE `domains` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `lifecycle_state` varchar(255) NOT NULL,
  `revision` bigint NOT NULL,
  `nameservers_verified_at` geometry,
  `nameservers_verified_by` bigint,
  `disabled_at` geometry,
  `deprovision_after` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `proxy_settings` geometry,
  `active_edge_revision` bigint,
  `deleted_at` geometry,
  `cache_settings` geometry,
  `cache_epoch` bigint NOT NULL,
  `cache_development_mode_until` geometry,
  `tls_mode` varchar(255) NOT NULL,
  `active_tls_certificate_id` varchar(255),
  `revision_changed_at` geometry,
  `security_settings` geometry,
  `security_state` varchar(255) NOT NULL,
  `security_state_changed_at` geometry,
  `waf_profile` varchar(255) NOT NULL,
  `delegation_token` varchar(255),
  `delegation_nameservers` geometry,
  `claim_expires_at` geometry
);

CREATE TABLE `edge_artifacts` (
  `sequence` bigint NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `domain_id` bigint,
  `kind` varchar(255) NOT NULL,
  `revision` bigint NOT NULL,
  `payload` geometry NOT NULL,
  `signature` varchar(255) NOT NULL,
  `schema_version` int NOT NULL,
  `minimum_agent_version` varchar(255) NOT NULL,
  `maximum_agent_version` varchar(255) NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `edge_cells` (
  `id` bigint NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `edge_pool_id` bigint,
  `name` varchar(255) NOT NULL,
  `drained` boolean NOT NULL,
  `status` varchar(255) NOT NULL,
  `capacity` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `slot` int NOT NULL,
  `http_port` int,
  `https_port` int,
  `status_port` int,
  `runtime_path` varchar(255),
  `cache_path` varchar(255),
  `temporary_path` varchar(255),
  `resource_limits` geometry
);

CREATE TABLE `edge_pool_endpoints` (
  `id` bigint NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `edge_pool_id` bigint NOT NULL,
  `ipv4` varchar(255),
  `ipv6` varchar(255),
  `withdrawn` boolean NOT NULL,
  `revision` bigint NOT NULL,
  `gateway_state` varchar(255) NOT NULL,
  `gateway_revision` bigint NOT NULL,
  `readiness_reason` varchar(255) NOT NULL,
  `gateway_acknowledged_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `dns_published` boolean NOT NULL,
  `dns_reason` varchar(255),
  `dns_state_changed_at` geometry
);

CREATE TABLE `edge_pools` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `kind` varchar(255) NOT NULL,
  `enabled` boolean NOT NULL,
  `revision` int NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `withdrawn` boolean NOT NULL,
  `minimum_ready_cells` int NOT NULL,
  `replicas_per_edge` int NOT NULL,
  `maximum_domains_per_cell` int NOT NULL,
  `routing_mode` varchar(255) NOT NULL,
  `anycast_ipv4` varchar(255),
  `anycast_ipv6` varchar(255),
  `cache_profile` varchar(255) NOT NULL,
  `compression_profile` varchar(255) NOT NULL,
  `waf_capable` boolean NOT NULL,
  `waf_runtime_version` varchar(255),
  `waf_canary_state` varchar(255) NOT NULL,
  `dns_fail_static` boolean NOT NULL
);

CREATE TABLE `edge_probe_results` (
  `id` bigint NOT NULL,
  `observer_edge_id` varchar(255) NOT NULL,
  `target_edge_id` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `ok` boolean NOT NULL,
  `detail` varchar(255)
);

CREATE TABLE `edge_revisions` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `revision` bigint NOT NULL,
  `snapshot` geometry NOT NULL,
  `status` varchar(255) NOT NULL,
  `created_by` bigint,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `edge_tasks` (
  `id` varchar(255) NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `payload` geometry NOT NULL,
  `status` varchar(255) NOT NULL,
  `result` geometry,
  `finished_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `cache_purge_id` varchar(255),
  `attempts` int NOT NULL,
  `available_at` geometry,
  `last_error` varchar(255)
);

CREATE TABLE `edges` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `country_code` varchar(255) NOT NULL,
  `continent_code` varchar(255) NOT NULL,
  `enabled` boolean NOT NULL,
  `drained` boolean NOT NULL,
  `bootstrap_token_hash` varchar(255),
  `identity_hash` varchar(255),
  `identity_revoked_at` geometry,
  `registered_at` geometry,
  `last_heartbeat_at` geometry,
  `agent_version` varchar(255),
  `capacity` geometry,
  `active_sequence` bigint NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `identity_certificate_serial` varchar(255),
  `identity_certificate_expires_at` geometry,
  `identity_csr_hash` varchar(255),
  `identity_certificate` text,
  `bootstrap_consumed_at` geometry,
  `cell_slot_count` int NOT NULL,
  `runtime_versions` geometry,
  `desired_runtime_versions` geometry,
  `runtime_versions_reported_at` geometry,
  `pending_identity_certificate` text,
  `pending_identity_certificate_serial` varchar(255),
  `pending_identity_certificate_expires_at` geometry,
  `pending_identity_csr_hash` varchar(255),
  `reported_identity_expires_at` geometry,
  `bootstrap_expires_at` geometry
);

CREATE TABLE `emergency_modes` (
  `id` varchar(255) NOT NULL,
  `target_type` varchar(255) NOT NULL,
  `target_id` varchar(255) NOT NULL,
  `actions` geometry NOT NULL,
  `revision` bigint NOT NULL,
  `active` boolean NOT NULL,
  `expires_at` geometry,
  `deactivated_at` geometry,
  `created_by` bigint,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `failed_jobs` (
  `id` bigint NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `exception` text NOT NULL,
  `failed_at` geometry NOT NULL
);

CREATE TABLE `fleet_releases` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `gateway_image` varchar(255) NOT NULL,
  `agent_image` varchar(255) NOT NULL,
  `normal_cell_image` varchar(255) NOT NULL,
  `waf_cell_image` varchar(255) NOT NULL,
  `minimum_compatible_version` varchar(255) NOT NULL,
  `maximum_compatible_version` varchar(255) NOT NULL,
  `enabled` boolean NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `fleet_rollout_edges` (
  `id` bigint NOT NULL,
  `fleet_rollout_id` varchar(255) NOT NULL,
  `edge_id` varchar(255) NOT NULL,
  `wave` int NOT NULL,
  `status` varchar(255) NOT NULL,
  `previous_versions` geometry,
  `desired_versions` geometry NOT NULL,
  `failure_reason` varchar(255),
  `started_at` geometry,
  `finished_at` geometry,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `fleet_rollouts` (
  `id` varchar(255) NOT NULL,
  `fleet_release_id` bigint NOT NULL,
  `previous_release_id` bigint,
  `created_by` bigint,
  `status` varchar(255) NOT NULL,
  `current_wave` int NOT NULL,
  `maximum_parallel` int NOT NULL,
  `minimum_ready_percent` int NOT NULL,
  `maximum_error_percent` int NOT NULL,
  `mixed_version_window_minutes` int NOT NULL,
  `pause_reason` varchar(255),
  `started_at` geometry,
  `finished_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `operation_id` varchar(255)
);

CREATE TABLE `generated_reports` (
  `id` varchar(255) NOT NULL,
  `kind` varchar(255) NOT NULL,
  `period` varchar(255) NOT NULL,
  `format` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `size_bytes` bigint NOT NULL,
  `created_at` geometry NOT NULL
);

CREATE TABLE `tls_certificates` (
  `id` varchar(255) NOT NULL,
  `domain_id` bigint NOT NULL,
  `kind` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `certificate_pem` text NOT NULL,
  `chain_pem` text NOT NULL,
  `private_key_ciphertext` text NOT NULL,
  `names` geometry NOT NULL,
  `fingerprint_sha256` varchar(255) NOT NULL,
  `not_before` geometry NOT NULL,
  `expires_at` geometry NOT NULL,
  `activated_at` geometry,
  `last_failure_at` geometry,
  `last_error` text,
  `created_at` geometry,
  `updated_at` geometry,
  `alerted_at` geometry
);

CREATE TABLE `tls_orders` (
  `id` varchar(255) NOT NULL,
  `domain_id` bigint NOT NULL,
  `certificate_id` varchar(255),
  `status` varchar(255) NOT NULL,
  `names` geometry NOT NULL,
  `acme_order_url` varchar(255),
  `attempts` int NOT NULL,
  `available_at` geometry,
  `started_at` geometry,
  `finished_at` geometry,
  `last_error` text,
  `created_at` geometry,
  `updated_at` geometry,
  `acme_account_id` bigint,
  `authorization_urls` geometry,
  `finalize_url` varchar(255),
  `certificate_url` varchar(255),
  `private_key_ciphertext` text,
  `csr_der` text,
  `dns_revision` bigint,
  `next_poll_at` geometry,
  `alerted_at` geometry,
  `names_hash` varchar(255)
);

CREATE TABLE `usage_rollups` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `interval_start` geometry NOT NULL,
  `interval_end` geometry NOT NULL,
  `granularity` varchar(255) NOT NULL,
  `requests` bigint NOT NULL,
  `bytes_in` bigint NOT NULL,
  `bytes_out` bigint NOT NULL,
  `cache_hits` bigint NOT NULL,
  `dns_queries` bigint NOT NULL,
  `status` varchar(255) NOT NULL,
  `source_finalized_at` geometry,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `waf_exclusions` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `dimension` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `rule_id` int,
  `reason` varchar(255) NOT NULL,
  `owner_id` bigint NOT NULL,
  `expires_at` geometry NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `idempotency_keys` (
  `id` bigint NOT NULL,
  `user_id` bigint,
  `key` varchar(255) NOT NULL,
  `request_hash` varchar(255) NOT NULL,
  `response_status` int NOT NULL,
  `response_body` geometry NOT NULL,
  `expires_at` geometry NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` text NOT NULL,
  `options` text,
  `cancelled_at` int,
  `created_at` int NOT NULL,
  `finished_at` int
);

CREATE TABLE `jobs` (
  `id` bigint NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `attempts` int NOT NULL,
  `reserved_at` int,
  `available_at` int NOT NULL,
  `created_at` int NOT NULL
);

CREATE TABLE `migrations` (
  `id` int NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL
);

CREATE TABLE `nameserver_address_probes` (
  `id` bigint NOT NULL,
  `address` varchar(255) NOT NULL,
  `zone` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `requested_at` geometry NOT NULL,
  `answered_at` geometry,
  `observer_edge_id` varchar(255),
  `last_error` varchar(255),
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `notifications` (
  `id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint NOT NULL,
  `data` geometry NOT NULL,
  `read_at` geometry,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `operations` (
  `id` varchar(255) NOT NULL,
  `actor_id` bigint,
  `type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `input` geometry NOT NULL,
  `result` geometry,
  `error` text,
  `attempts` int NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `started_at` geometry,
  `finished_at` geometry,
  `domain_id` bigint
);

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` geometry
);

CREATE TABLE `personal_access_token_domains` (
  `personal_access_token_id` bigint NOT NULL,
  `domain_id` bigint NOT NULL
);

CREATE TABLE `personal_access_tokens` (
  `id` bigint NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint NOT NULL,
  `name` text NOT NULL,
  `token` varchar(255) NOT NULL,
  `abilities` text,
  `last_used_at` geometry,
  `expires_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `token_last_six` varchar(255)
);

CREATE TABLE `platform_dns_deployments` (
  `id` bigint NOT NULL,
  `dns_cluster_id` bigint NOT NULL,
  `desired_revision` bigint NOT NULL,
  `deployed_revision` bigint NOT NULL,
  `status` varchar(255) NOT NULL,
  `active_checksum` varchar(255),
  `active_rrsets` geometry NOT NULL,
  `last_error` text,
  `attempts` int NOT NULL,
  `last_attempted_at` geometry,
  `deployed_at` geometry,
  `created_at` geometry,
  `updated_at` geometry,
  `active_zone` varchar(255)
);

CREATE TABLE `platform_dns_settings` (
  `id` int NOT NULL,
  `platform_domain` varchar(255) NOT NULL,
  `proxy_hostname` varchar(255) NOT NULL,
  `nameservers` geometry NOT NULL,
  `soa_primary` varchar(255) NOT NULL,
  `soa_mailbox` varchar(255) NOT NULL,
  `soa_refresh` int NOT NULL,
  `soa_retry` int NOT NULL,
  `soa_expire` int NOT NULL,
  `soa_minimum_ttl` int NOT NULL,
  `default_ttl` int NOT NULL,
  `created_at` geometry,
  `updated_at` geometry,
  `revision` bigint NOT NULL,
  `soa_serial` bigint NOT NULL,
  `zone_content_checksum` varchar(255),
  `customer_nameserver_pairs` geometry,
  `cluster_targets` geometry
);

CREATE TABLE `security_events` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `edge_id` varchar(255),
  `hostname` varchar(255),
  `state` varchar(255),
  `reason_code` varchar(255) NOT NULL,
  `details` geometry,
  `occurred_at` geometry NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `security_rules` (
  `id` bigint NOT NULL,
  `domain_id` bigint NOT NULL,
  `match_type` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `priority` int NOT NULL,
  `enabled` boolean NOT NULL,
  `note` varchar(255),
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint,
  `ip_address` varchar(255),
  `user_agent` text,
  `payload` text NOT NULL,
  `last_activity` int NOT NULL
);

CREATE TABLE `system_settings` (
  `"group"` varchar(255) NOT NULL,
  `"values"` geometry NOT NULL,
  `revision` bigint NOT NULL,
  `created_at` geometry,
  `updated_at` geometry
);

CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` geometry,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(255),
  `created_at` geometry,
  `updated_at` geometry,
  `type` varchar(255) NOT NULL,
  `disabled_at` geometry,
  `app_authentication_secret` text,
  `app_authentication_recovery_codes` text
);
