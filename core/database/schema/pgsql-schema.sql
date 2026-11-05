--
-- PostgreSQL database dump
--

\restrict Q6bVKd0l5gZ9C1fXSc0soYVVbYAiXDdsTpHOzMNPcDWDaIwl6f7Xr4LoueSD4dR

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--



--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS 'standard public schema';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: acme_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.acme_accounts (
    id bigint NOT NULL,
    directory_url character varying(2048) NOT NULL,
    contact_email character varying(320) NOT NULL,
    private_key_ciphertext text NOT NULL,
    account_url character varying(2048),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: acme_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.acme_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: acme_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.acme_accounts_id_seq OWNED BY public.acme_accounts.id;


--
-- Name: acme_challenges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.acme_challenges (
    id uuid NOT NULL,
    tls_order_id uuid NOT NULL,
    hostname character varying(253) NOT NULL,
    record_name character varying(253) NOT NULL,
    record_value text NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    cleaned_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    authorization_url character varying(2048),
    challenge_url character varying(2048),
    token character varying(512),
    CONSTRAINT acme_challenges_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'published'::character varying, 'validating'::character varying, 'valid'::character varying, 'failed'::character varying, 'cleaned'::character varying])::text[])))
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id bigint NOT NULL,
    actor_id bigint,
    action character varying(100) NOT NULL,
    subject_type character varying(255),
    subject_id character varying(255),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    ip_address character varying(45),
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: backups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backups (
    id uuid NOT NULL,
    requested_by bigint,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    snapshot_id character varying(128),
    size_bytes bigint,
    manifest_sha256 character(64),
    last_error text,
    verified_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT backups_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'deleting'::character varying])::text[])))
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_purge_sequence; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cache_purge_sequence
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cache_purges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_purges (
    id uuid NOT NULL,
    domain_id bigint NOT NULL,
    requested_by bigint,
    type character varying(10) NOT NULL,
    cache_epoch bigint NOT NULL,
    cache_keys json,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    sequence bigint,
    operation_id uuid,
    CONSTRAINT cache_purges_payload_check CHECK (((((type)::text = 'all'::text) AND (cache_keys IS NULL)) OR (((type)::text = 'urls'::text) AND (jsonb_typeof((cache_keys)::jsonb) = 'array'::text)))),
    CONSTRAINT cache_purges_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'succeeded'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT cache_purges_type_check CHECK (((type)::text = ANY ((ARRAY['all'::character varying, 'urls'::character varying])::text[])))
);


--
-- Name: control_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.control_nodes (
    id bigint NOT NULL,
    name character varying(63) NOT NULL,
    role character varying(12) NOT NULL,
    address character varying(45) NOT NULL,
    fingerprints json,
    replication_lag_seconds integer,
    valkey_linked boolean,
    reported_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT control_nodes_role_check CHECK (((role)::text = ANY ((ARRAY['active'::character varying, 'standby'::character varying])::text[])))
);


--
-- Name: control_nodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.control_nodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: control_nodes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.control_nodes_id_seq OWNED BY public.control_nodes.id;


--
-- Name: control_plane_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.control_plane_state (
    id smallint NOT NULL,
    epoch bigint DEFAULT '1'::bigint NOT NULL,
    active_node character varying(63),
    addresses json NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT control_plane_state_single_row CHECK (((id = 1) AND (epoch >= 1)))
);


--
-- Name: dns_clusters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_clusters (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    location character varying(100) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    api_url character varying(500) NOT NULL,
    api_key text NOT NULL,
    server_id character varying(100) DEFAULT 'localhost'::character varying NOT NULL,
    nameservers jsonb DEFAULT '[]'::jsonb NOT NULL,
    capacity_zones integer DEFAULT 100000 NOT NULL,
    operational_notes text,
    last_health_status character varying(20),
    last_health_error text,
    last_health_at timestamp(0) with time zone,
    last_reconciled_revision bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    transfer_endpoint character varying(100),
    transfer_tsig_secret text,
    CONSTRAINT dns_clusters_health_check CHECK (((last_health_status IS NULL) OR ((last_health_status)::text = ANY ((ARRAY['healthy'::character varying, 'unhealthy'::character varying])::text[]))))
);


--
-- Name: dns_clusters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_clusters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_clusters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_clusters_id_seq OWNED BY public.dns_clusters.id;


--
-- Name: dns_deployments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_deployments (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    dns_cluster_id bigint NOT NULL,
    desired_revision bigint DEFAULT '0'::bigint NOT NULL,
    deployed_revision bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    active_checksum character(64),
    active_rrsets jsonb DEFAULT '[]'::jsonb NOT NULL,
    last_error text,
    attempts integer DEFAULT 0 NOT NULL,
    last_attempted_at timestamp(0) with time zone,
    deployed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    tombstone boolean DEFAULT false NOT NULL,
    deprovisioned_at timestamp(0) with time zone,
    verified_at timestamp(0) without time zone,
    CONSTRAINT dns_deployments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'deploying'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'obsolete'::character varying, 'deprovisioning'::character varying, 'deprovisioned'::character varying])::text[])))
);


--
-- Name: dns_deployments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_deployments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_deployments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_deployments_id_seq OWNED BY public.dns_deployments.id;


--
-- Name: dns_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_records (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    type character varying(8) NOT NULL,
    name character varying(253) NOT NULL,
    content text NOT NULL,
    content_hash character(64) NOT NULL,
    ttl integer NOT NULL,
    priority smallint DEFAULT '0'::smallint NOT NULL,
    weight smallint DEFAULT '0'::smallint NOT NULL,
    port smallint DEFAULT '0'::smallint NOT NULL,
    mode character varying(16) DEFAULT 'dns_only'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    geo_config jsonb,
    origin jsonb,
    origin_health jsonb,
    CONSTRAINT dns_records_mode_check CHECK (((mode)::text = ANY ((ARRAY['dns_only'::character varying, 'geo_dns'::character varying, 'proxied'::character varying])::text[]))),
    CONSTRAINT dns_records_mode_payload_check CHECK (((((mode)::text = 'dns_only'::text) AND (geo_config IS NULL) AND (origin IS NULL)) OR (((mode)::text = 'geo_dns'::text) AND ((type)::text = ANY (ARRAY[('A'::character varying)::text, ('AAAA'::character varying)::text, ('CNAME'::character varying)::text, ('MX'::character varying)::text, ('TXT'::character varying)::text, ('NS'::character varying)::text, ('SRV'::character varying)::text, ('PTR'::character varying)::text])) AND (geo_config IS NOT NULL) AND (origin IS NULL)) OR (((mode)::text = 'proxied'::text) AND ((type)::text = ANY (ARRAY[('A'::character varying)::text, ('AAAA'::character varying)::text, ('CNAME'::character varying)::text])) AND (geo_config IS NULL) AND (origin IS NOT NULL)))),
    CONSTRAINT dns_records_ttl_check CHECK (((ttl >= 30) AND (ttl <= 2147483647))),
    CONSTRAINT dns_records_type_check CHECK (((type)::text = ANY ((ARRAY['A'::character varying, 'AAAA'::character varying, 'CNAME'::character varying, 'MX'::character varying, 'TXT'::character varying, 'NS'::character varying, 'CAA'::character varying, 'SRV'::character varying, 'PTR'::character varying])::text[])))
);


--
-- Name: dns_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_records_id_seq OWNED BY public.dns_records.id;


--
-- Name: dns_zone_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_zone_changes (
    id bigint NOT NULL,
    zone character varying(253) NOT NULL,
    serial bigint DEFAULT '0'::bigint NOT NULL,
    deleted boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: dns_zone_changes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_zone_changes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_zone_changes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_zone_changes_id_seq OWNED BY public.dns_zone_changes.id;


--
-- Name: domain_edge_cells; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_edge_cells (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    edge_id uuid NOT NULL,
    replica smallint DEFAULT '1'::smallint NOT NULL,
    active_cell_id bigint,
    target_cell_id bigint,
    desired_revision bigint NOT NULL,
    state character varying(24) DEFAULT 'deploying'::character varying NOT NULL,
    drain_after timestamp(0) with time zone,
    last_error text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT domain_edge_cells_state_check CHECK (((state)::text = ANY ((ARRAY['deploying'::character varying, 'draining'::character varying, 'active'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT domain_edge_cells_target_check CHECK (((active_cell_id IS NOT NULL) OR (target_cell_id IS NOT NULL)))
);


--
-- Name: domain_edge_cells_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domain_edge_cells_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domain_edge_cells_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domain_edge_cells_id_seq OWNED BY public.domain_edge_cells.id;


--
-- Name: domain_edge_placements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_edge_placements (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    active_pool_id bigint,
    target_pool_id bigint,
    state character varying(24) DEFAULT 'pending'::character varying NOT NULL,
    desired_revision bigint NOT NULL,
    drain_after timestamp(0) with time zone,
    last_error text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT placement_state_check CHECK (((state)::text = ANY ((ARRAY['pending'::character varying, 'deploying'::character varying, 'draining'::character varying, 'active'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: domain_edge_placements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domain_edge_placements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domain_edge_placements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domain_edge_placements_id_seq OWNED BY public.domain_edge_placements.id;


--
-- Name: domain_name_tombstones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_name_tombstones (
    id bigint NOT NULL,
    name character varying(253) NOT NULL,
    source_domain_id bigint NOT NULL,
    deprovisioned_at timestamp(0) with time zone NOT NULL,
    reclaim_after timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: domain_name_tombstones_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domain_name_tombstones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domain_name_tombstones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domain_name_tombstones_id_seq OWNED BY public.domain_name_tombstones.id;


--
-- Name: domain_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_user (
    domain_id bigint NOT NULL,
    user_id bigint NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domains (
    id bigint NOT NULL,
    name character varying(253) NOT NULL,
    display_name character varying(253) NOT NULL,
    lifecycle_state character varying(32) DEFAULT 'pending_verification'::character varying NOT NULL,
    revision bigint DEFAULT '1'::bigint NOT NULL,
    nameservers_verified_at timestamp(0) with time zone,
    nameservers_verified_by bigint,
    disabled_at timestamp(0) with time zone,
    deprovision_after timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    proxy_settings jsonb,
    active_edge_revision bigint,
    deleted_at timestamp(0) with time zone,
    cache_settings json,
    cache_epoch bigint DEFAULT '1'::bigint NOT NULL,
    cache_development_mode_until timestamp(0) with time zone,
    tls_mode character varying(16) DEFAULT 'managed'::character varying NOT NULL,
    active_tls_certificate_id uuid,
    revision_changed_at timestamp(0) with time zone,
    security_settings jsonb,
    security_state character varying(20) DEFAULT 'normal'::character varying NOT NULL,
    security_state_changed_at timestamp(0) with time zone,
    waf_profile character varying(16) DEFAULT 'off'::character varying NOT NULL,
    delegation_token character varying(32),
    delegation_nameservers jsonb,
    claim_expires_at timestamp(0) with time zone,
    CONSTRAINT domains_lifecycle_state_check CHECK (((lifecycle_state)::text = ANY ((ARRAY['pending_verification'::character varying, 'active'::character varying, 'disabled'::character varying, 'deprovisioning'::character varying])::text[]))),
    CONSTRAINT domains_proxy_settings_object CHECK (((proxy_settings IS NULL) OR (jsonb_typeof(proxy_settings) = 'object'::text))),
    CONSTRAINT domains_revision_positive CHECK ((revision > 0)),
    CONSTRAINT domains_security_settings_object CHECK (((security_settings IS NULL) OR (jsonb_typeof(security_settings) = 'object'::text))),
    CONSTRAINT domains_security_state_check CHECK (((security_state)::text = ANY ((ARRAY['normal'::character varying, 'suspected'::character varying, 'restricted'::character varying, 'quarantined'::character varying, 'recovering'::character varying])::text[]))),
    CONSTRAINT domains_tls_mode_check CHECK (((tls_mode)::text = ANY ((ARRAY['managed'::character varying, 'custom'::character varying, 'disabled'::character varying])::text[]))),
    CONSTRAINT domains_waf_profile_check CHECK (((waf_profile)::text = ANY ((ARRAY['off'::character varying, 'monitor'::character varying, 'balanced'::character varying, 'strict'::character varying])::text[])))
);


--
-- Name: domains_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domains_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domains_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domains_id_seq OWNED BY public.domains.id;


--
-- Name: edge_artifacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_artifacts (
    sequence bigint NOT NULL,
    edge_id uuid NOT NULL,
    domain_id bigint,
    kind character varying(20) NOT NULL,
    revision bigint NOT NULL,
    payload jsonb NOT NULL,
    checksum character(64) NOT NULL,
    signature character varying(128) NOT NULL,
    schema_version smallint DEFAULT '1'::smallint NOT NULL,
    minimum_agent_version character varying(20) DEFAULT '1.0.0'::character varying NOT NULL,
    maximum_agent_version character varying(20) DEFAULT '1.99.99'::character varying NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT edge_artifacts_kind_check CHECK (((kind)::text = ANY ((ARRAY['domain'::character varying, 'tombstone'::character varying])::text[]))),
    CONSTRAINT edge_artifacts_revision_positive CHECK ((revision > 0))
);


--
-- Name: edge_artifacts_sequence_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_artifacts_sequence_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_artifacts_sequence_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_artifacts_sequence_seq OWNED BY public.edge_artifacts.sequence;


--
-- Name: edge_cells; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_cells (
    id bigint NOT NULL,
    edge_id uuid NOT NULL,
    edge_pool_id bigint,
    name character varying(100) NOT NULL,
    drained boolean DEFAULT false NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    capacity jsonb,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    slot smallint NOT NULL,
    http_port smallint,
    https_port smallint,
    status_port smallint,
    runtime_path character varying(255),
    cache_path character varying(255),
    temporary_path character varying(255),
    resource_limits json,
    CONSTRAINT edge_cells_name_check CHECK (((name)::text = ('cell-'::text || lpad((slot)::text, 2, '0'::text)))),
    CONSTRAINT edge_cells_paths_check CHECK ((((runtime_path)::text = (('/var/lib/cdnfoundry/runtime/'::text || (name)::text) || '.json'::text)) AND ((cache_path)::text = ('/var/cache/cdnfoundry/'::text || (name)::text)) AND ((temporary_path)::text = ('/var/lib/cdnfoundry/tmp/'::text || (name)::text)))),
    CONSTRAINT edge_cells_ports_check CHECK ((((http_port >= 1) AND (http_port <= 65535)) AND ((https_port >= 1) AND (https_port <= 65535)) AND ((status_port >= 1) AND (status_port <= 65535)) AND (http_port <> https_port) AND (http_port <> status_port) AND (https_port <> status_port))),
    CONSTRAINT edge_cells_slot_check CHECK (((slot >= 1) AND (slot <= 32))),
    CONSTRAINT edge_cells_status_check CHECK (((status)::text = ANY ((ARRAY['assigned'::character varying, 'unassigned'::character varying, 'ready'::character varying, 'degraded'::character varying, 'drained'::character varying, 'stopped'::character varying])::text[])))
);


--
-- Name: edge_cells_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_cells_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_cells_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_cells_id_seq OWNED BY public.edge_cells.id;


--
-- Name: edge_pool_endpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_pool_endpoints (
    id bigint NOT NULL,
    edge_id uuid NOT NULL,
    edge_pool_id bigint NOT NULL,
    ipv4 inet,
    ipv6 inet,
    withdrawn boolean DEFAULT false NOT NULL,
    revision bigint DEFAULT '1'::bigint NOT NULL,
    gateway_state character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    gateway_revision bigint DEFAULT '0'::bigint NOT NULL,
    readiness_reason character varying(80) DEFAULT 'gateway_not_acknowledged'::character varying NOT NULL,
    gateway_acknowledged_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    dns_published boolean DEFAULT false NOT NULL,
    dns_reason character varying(80),
    dns_state_changed_at timestamp(0) with time zone,
    CONSTRAINT edge_pool_endpoints_gateway_state_check CHECK (((gateway_state)::text = ANY ((ARRAY['pending'::character varying, 'ready'::character varying, 'degraded'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: edge_pool_endpoints_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_pool_endpoints_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_pool_endpoints_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_pool_endpoints_id_seq OWNED BY public.edge_pool_endpoints.id;


--
-- Name: edge_pools; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_pools (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    kind character varying(16) DEFAULT 'shared'::character varying NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    revision integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    withdrawn boolean DEFAULT false NOT NULL,
    minimum_ready_cells smallint DEFAULT '1'::smallint NOT NULL,
    replicas_per_edge smallint DEFAULT '1'::smallint NOT NULL,
    maximum_domains_per_cell integer DEFAULT 20000 NOT NULL,
    routing_mode character varying(24) DEFAULT 'geo_unicast'::character varying NOT NULL,
    anycast_ipv4 inet,
    anycast_ipv6 inet,
    cache_profile character varying(16) DEFAULT 'standard'::character varying NOT NULL,
    compression_profile character varying(24) DEFAULT 'standard'::character varying NOT NULL,
    waf_capable boolean DEFAULT false NOT NULL,
    waf_runtime_version character varying(80),
    waf_canary_state character varying(20) DEFAULT 'not_required'::character varying NOT NULL,
    dns_fail_static boolean DEFAULT false NOT NULL,
    CONSTRAINT edge_pools_anycast_addresses_check CHECK (((((routing_mode)::text = 'geo_unicast'::text) AND (anycast_ipv4 IS NULL) AND (anycast_ipv6 IS NULL)) OR (((routing_mode)::text = 'simple_anycast'::text) AND ((anycast_ipv4 IS NOT NULL) OR (anycast_ipv6 IS NOT NULL))))),
    CONSTRAINT edge_pools_cache_profile_check CHECK (((cache_profile)::text = ANY ((ARRAY['small'::character varying, 'standard'::character varying, 'large'::character varying, 'streaming'::character varying])::text[]))),
    CONSTRAINT edge_pools_capacity_check CHECK (((maximum_domains_per_cell >= 1) AND (maximum_domains_per_cell <= 100000))),
    CONSTRAINT edge_pools_compression_kind_check CHECK ((((compression_profile)::text <> 'maximum_savings'::text) OR ((kind)::text = ANY ((ARRAY['reserved'::character varying, 'dedicated'::character varying])::text[])))),
    CONSTRAINT edge_pools_compression_profile_check CHECK (((compression_profile)::text = ANY ((ARRAY['off'::character varying, 'standard'::character varying, 'maximum_savings'::character varying])::text[]))),
    CONSTRAINT edge_pools_kind_check CHECK (((kind)::text = ANY ((ARRAY['shared'::character varying, 'reserved'::character varying, 'dedicated'::character varying, 'quarantine'::character varying])::text[]))),
    CONSTRAINT edge_pools_minimum_ready_check CHECK (((minimum_ready_cells >= 1) AND (minimum_ready_cells <= 32))),
    CONSTRAINT edge_pools_replica_kind_check CHECK (((replicas_per_edge = 1) OR ((kind)::text = ANY ((ARRAY['reserved'::character varying, 'dedicated'::character varying])::text[])))),
    CONSTRAINT edge_pools_replicas_check CHECK (((replicas_per_edge >= 1) AND (replicas_per_edge <= 3))),
    CONSTRAINT edge_pools_routing_mode_check CHECK (((routing_mode)::text = ANY ((ARRAY['geo_unicast'::character varying, 'simple_anycast'::character varying])::text[]))),
    CONSTRAINT edge_pools_waf_canary_state_check CHECK (((waf_canary_state)::text = ANY ((ARRAY['not_required'::character varying, 'monitoring'::character varying, 'passed'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: edge_pools_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_pools_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_pools_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_pools_id_seq OWNED BY public.edge_pools.id;


--
-- Name: edge_probe_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_probe_results (
    id bigint NOT NULL,
    observer_edge_id uuid NOT NULL,
    target_edge_id uuid NOT NULL,
    address inet NOT NULL,
    ok boolean NOT NULL,
    detail character varying(120),
    checked_at timestamp(0) with time zone NOT NULL
);


--
-- Name: edge_probe_results_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_probe_results_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_probe_results_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_probe_results_id_seq OWNED BY public.edge_probe_results.id;


--
-- Name: edge_revisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_revisions (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    revision bigint NOT NULL,
    snapshot jsonb NOT NULL,
    checksum character(64) NOT NULL,
    status character varying(20) DEFAULT 'validated'::character varying NOT NULL,
    created_by bigint,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT edge_revisions_status_check CHECK (((status)::text = ANY ((ARRAY['validated'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: edge_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.edge_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: edge_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.edge_revisions_id_seq OWNED BY public.edge_revisions.id;


--
-- Name: edge_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_tasks (
    id uuid NOT NULL,
    edge_id uuid NOT NULL,
    type character varying(30) NOT NULL,
    payload jsonb NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    result jsonb,
    finished_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    cache_purge_id uuid,
    attempts integer DEFAULT 0 NOT NULL,
    available_at timestamp(0) with time zone,
    last_error character varying(255),
    CONSTRAINT edge_tasks_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'succeeded'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: edges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edges (
    id uuid NOT NULL,
    name character varying(100) NOT NULL,
    country_code character(2) NOT NULL,
    continent_code character(2) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    drained boolean DEFAULT false NOT NULL,
    bootstrap_token_hash character(64),
    identity_hash character(64),
    identity_revoked_at timestamp(0) with time zone,
    registered_at timestamp(0) with time zone,
    last_heartbeat_at timestamp(0) with time zone,
    agent_version character varying(40),
    capacity jsonb,
    active_sequence bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    identity_certificate_serial character varying(64),
    identity_certificate_expires_at timestamp(0) with time zone,
    identity_csr_hash character(64),
    identity_certificate text,
    bootstrap_consumed_at timestamp(0) with time zone,
    cell_slot_count smallint DEFAULT '8'::smallint NOT NULL,
    runtime_versions json,
    desired_runtime_versions json,
    runtime_versions_reported_at timestamp(0) with time zone,
    pending_identity_certificate text,
    pending_identity_certificate_serial character varying(64),
    pending_identity_certificate_expires_at timestamp(0) with time zone,
    pending_identity_csr_hash character varying(64),
    reported_identity_expires_at timestamp(0) with time zone,
    bootstrap_expires_at timestamp(0) with time zone,
    CONSTRAINT edges_cell_slot_count_check CHECK (((cell_slot_count >= 1) AND (cell_slot_count <= 32))),
    CONSTRAINT edges_geo_codes_check CHECK ((((country_code)::text = upper((country_code)::text)) AND ((continent_code)::text = upper((continent_code)::text))))
);


--
-- Name: emergency_modes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emergency_modes (
    id uuid NOT NULL,
    target_type character varying(16) NOT NULL,
    target_id character varying(64) NOT NULL,
    actions jsonb NOT NULL,
    revision bigint DEFAULT '1'::bigint NOT NULL,
    active boolean DEFAULT true NOT NULL,
    expires_at timestamp(0) with time zone,
    deactivated_at timestamp(0) with time zone,
    created_by bigint,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT emergency_modes_actions_array CHECK ((jsonb_typeof(actions) = 'array'::text)),
    CONSTRAINT emergency_modes_target_type_check CHECK (((target_type)::text = ANY ((ARRAY['edge'::character varying, 'cell'::character varying, 'pool'::character varying])::text[])))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: fleet_releases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fleet_releases (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    gateway_image character varying(255) NOT NULL,
    agent_image character varying(255) NOT NULL,
    normal_cell_image character varying(255) NOT NULL,
    waf_cell_image character varying(255) NOT NULL,
    minimum_compatible_version character varying(40) NOT NULL,
    maximum_compatible_version character varying(40) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: fleet_releases_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fleet_releases_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fleet_releases_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fleet_releases_id_seq OWNED BY public.fleet_releases.id;


--
-- Name: fleet_rollout_edges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fleet_rollout_edges (
    id bigint NOT NULL,
    fleet_rollout_id uuid NOT NULL,
    edge_id uuid NOT NULL,
    wave smallint NOT NULL,
    status character varying(24) DEFAULT 'pending'::character varying NOT NULL,
    previous_versions json,
    desired_versions json NOT NULL,
    failure_reason character varying(100),
    started_at timestamp(0) with time zone,
    finished_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT fleet_rollout_edges_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'dispatched'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'rolling_back'::character varying, 'rolled_back'::character varying])::text[])))
);


--
-- Name: fleet_rollout_edges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fleet_rollout_edges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fleet_rollout_edges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fleet_rollout_edges_id_seq OWNED BY public.fleet_rollout_edges.id;


--
-- Name: fleet_rollouts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fleet_rollouts (
    id uuid NOT NULL,
    fleet_release_id bigint NOT NULL,
    previous_release_id bigint,
    created_by bigint,
    status character varying(24) DEFAULT 'pending'::character varying NOT NULL,
    current_wave smallint DEFAULT '0'::smallint NOT NULL,
    maximum_parallel smallint DEFAULT '2'::smallint NOT NULL,
    minimum_ready_percent smallint DEFAULT '100'::smallint NOT NULL,
    maximum_error_percent smallint DEFAULT '5'::smallint NOT NULL,
    mixed_version_window_minutes integer DEFAULT 60 NOT NULL,
    pause_reason character varying(100),
    started_at timestamp(0) with time zone,
    finished_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    operation_id uuid,
    CONSTRAINT fleet_rollout_bounds_check CHECK ((((maximum_parallel >= 1) AND (maximum_parallel <= 20)) AND ((minimum_ready_percent >= 50) AND (minimum_ready_percent <= 100)) AND ((maximum_error_percent >= 0) AND (maximum_error_percent <= 25)) AND ((mixed_version_window_minutes >= 5) AND (mixed_version_window_minutes <= 1440)))),
    CONSTRAINT fleet_rollouts_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'paused'::character varying, 'rolling_back'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'rolled_back'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: generated_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.generated_reports (
    id uuid NOT NULL,
    kind character varying(16) NOT NULL,
    period character varying(12) NOT NULL,
    format character varying(4) NOT NULL,
    path character varying(160) NOT NULL,
    size_bytes bigint NOT NULL,
    checksum_sha256 character(64) NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT generated_reports_format_check CHECK (((format)::text = ANY ((ARRAY['csv'::character varying, 'pdf'::character varying])::text[]))),
    CONSTRAINT generated_reports_kind_check CHECK (((kind)::text = ANY ((ARRAY['usage'::character varying, 'operations'::character varying])::text[])))
);


--
-- Name: grafana_audit_events; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_audit_events AS
 SELECT id,
    actor_id,
    action,
    created_at,
        CASE
            WHEN ((ip_address IS NULL) OR ((ip_address)::text = ''::text)) THEN NULL::text
            WHEN (POSITION((':'::text) IN (ip_address)) > 0) THEN (host(set_masklen((ip_address)::inet, 48)) || '/48'::text)
            ELSE (host(set_masklen((ip_address)::inet, 24)) || '/24'::text)
        END AS network
   FROM public.audit_logs;


--
-- Name: grafana_backups; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_backups AS
 SELECT id,
    status,
    size_bytes,
    verified_at,
    created_at
   FROM public.backups;


--
-- Name: grafana_cache_purges; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_cache_purges AS
 SELECT id,
    domain_id,
    type,
    status,
    created_at,
    updated_at
   FROM public.cache_purges;


--
-- Name: tls_certificates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tls_certificates (
    id uuid NOT NULL,
    domain_id bigint NOT NULL,
    kind character varying(16) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    certificate_pem text NOT NULL,
    chain_pem text NOT NULL,
    private_key_ciphertext text NOT NULL,
    names json NOT NULL,
    fingerprint_sha256 character(64) NOT NULL,
    not_before timestamp(0) with time zone NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    activated_at timestamp(0) with time zone,
    last_failure_at timestamp(0) with time zone,
    last_error text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    alerted_at timestamp(0) with time zone,
    CONSTRAINT tls_certificates_kind_check CHECK (((kind)::text = ANY ((ARRAY['managed'::character varying, 'custom'::character varying])::text[]))),
    CONSTRAINT tls_certificates_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'active'::character varying, 'superseded'::character varying, 'failed'::character varying, 'revoked'::character varying])::text[])))
);


--
-- Name: grafana_domain_operational_metadata; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_domain_operational_metadata AS
 SELECT d.id AS domain_id,
    d.lifecycle_state,
    d.revision AS desired_revision,
    d.active_edge_revision,
    d.revision_changed_at,
    d.nameservers_verified_at,
    COALESCE(((d.proxy_settings ->> 'enabled'::text))::boolean, true) AS proxy_enabled,
    p.state AS placement_state,
    p.desired_revision AS placement_revision,
    active_pool.name AS active_pool,
    target_pool.name AS target_pool,
    COALESCE(active_pool.cache_profile, target_pool.cache_profile) AS cache_profile,
    COALESCE(active_pool.compression_profile, target_pool.compression_profile) AS compression_profile,
    (d.cache_settings ->> 'mode'::text) AS cache_mode,
    d.cache_epoch,
    d.cache_development_mode_until,
    d.tls_mode,
    certificate.status AS certificate_status,
    certificate.expires_at AS certificate_expires_at,
    d.security_state,
    (d.security_settings ->> 'profile'::text) AS security_profile,
    d.waf_profile,
    COALESCE(assignments.assignment_count, (0)::bigint) AS assignment_count,
    COALESCE(assignments.failed_assignments, (0)::bigint) AS failed_assignments,
    COALESCE(assignments.assignments, ''::text) AS cell_assignments,
    COALESCE(dns.deployment_count, (0)::bigint) AS dns_deployment_count,
    COALESCE(dns.drifted_deployments, (0)::bigint) AS dns_drifted_deployments
   FROM ((((((public.domains d
     LEFT JOIN public.domain_edge_placements p ON ((p.domain_id = d.id)))
     LEFT JOIN public.edge_pools active_pool ON ((active_pool.id = p.active_pool_id)))
     LEFT JOIN public.edge_pools target_pool ON ((target_pool.id = p.target_pool_id)))
     LEFT JOIN public.tls_certificates certificate ON ((certificate.id = d.active_tls_certificate_id)))
     LEFT JOIN LATERAL ( SELECT count(*) AS assignment_count,
            count(*) FILTER (WHERE (("dec".state)::text = 'failed'::text)) AS failed_assignments,
            string_agg((((((edge.name)::text || '/'::text) || (COALESCE(active_cell.name, target_cell.name, 'unassigned'::character varying))::text) || ':'::text) || ("dec".state)::text), ', '::text ORDER BY edge.name, "dec".replica) AS assignments
           FROM (((public.domain_edge_cells "dec"
             JOIN public.edges edge ON ((edge.id = "dec".edge_id)))
             LEFT JOIN public.edge_cells active_cell ON ((active_cell.id = "dec".active_cell_id)))
             LEFT JOIN public.edge_cells target_cell ON ((target_cell.id = "dec".target_cell_id)))
          WHERE ("dec".domain_id = d.id)) assignments ON (true))
     LEFT JOIN LATERAL ( SELECT count(*) AS deployment_count,
            count(*) FILTER (WHERE (((dd.status)::text = ANY ((ARRAY['pending'::character varying, 'failed'::character varying])::text[])) OR (dd.deployed_revision < dd.desired_revision))) AS drifted_deployments
           FROM public.dns_deployments dd
          WHERE (dd.domain_id = d.id)) dns ON (true))
  WHERE (d.deleted_at IS NULL);


--
-- Name: grafana_edge_artifacts; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_edge_artifacts AS
 SELECT sequence,
    edge_id,
    domain_id,
    kind,
    revision,
    created_at
   FROM public.edge_artifacts;


--
-- Name: grafana_edge_cells; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_edge_cells AS
 SELECT edge_id,
    edge_pool_id,
    name,
    status,
    drained
   FROM public.edge_cells;


--
-- Name: grafana_edge_tasks; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_edge_tasks AS
 SELECT id,
    edge_id,
    type,
    status,
    created_at,
    finished_at
   FROM public.edge_tasks;


--
-- Name: grafana_edges; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_edges AS
 SELECT id,
    name,
    country_code,
    continent_code,
    enabled,
    drained,
    last_heartbeat_at,
    registered_at,
    agent_version,
    active_sequence
   FROM public.edges;


--
-- Name: grafana_emergency_modes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_emergency_modes AS
 SELECT id,
    target_type,
    active,
    expires_at,
    created_at
   FROM public.emergency_modes;


--
-- Name: grafana_fleet_rollouts; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_fleet_rollouts AS
 SELECT id,
    status,
    current_wave,
    maximum_parallel,
    pause_reason,
    started_at,
    finished_at,
    created_at
   FROM public.fleet_rollouts;


--
-- Name: grafana_placements; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_placements AS
 SELECT domain_id,
    state,
    active_pool_id,
    target_pool_id,
    updated_at
   FROM public.domain_edge_placements;


--
-- Name: grafana_tls_certificates; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_tls_certificates AS
 SELECT id,
    domain_id,
    kind,
    status,
    names,
    expires_at,
    activated_at,
    last_failure_at
   FROM public.tls_certificates;


--
-- Name: tls_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tls_orders (
    id uuid NOT NULL,
    domain_id bigint NOT NULL,
    certificate_id uuid,
    status character varying(24) DEFAULT 'pending'::character varying NOT NULL,
    names json NOT NULL,
    acme_order_url character varying(2048),
    attempts integer DEFAULT 0 NOT NULL,
    available_at timestamp(0) with time zone,
    started_at timestamp(0) with time zone,
    finished_at timestamp(0) with time zone,
    last_error text,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    acme_account_id bigint,
    authorization_urls json,
    finalize_url character varying(2048),
    certificate_url character varying(2048),
    private_key_ciphertext text,
    csr_der text,
    dns_revision bigint,
    next_poll_at timestamp(0) with time zone,
    alerted_at timestamp(0) with time zone,
    names_hash character(64),
    CONSTRAINT tls_orders_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'creating'::character varying, 'publishing'::character varying, 'validating'::character varying, 'finalizing'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'obsolete'::character varying])::text[])))
);


--
-- Name: grafana_tls_orders; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_tls_orders AS
 SELECT id,
    domain_id,
    status,
    started_at,
    finished_at
   FROM public.tls_orders;


--
-- Name: usage_rollups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usage_rollups (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    interval_start timestamp(0) with time zone NOT NULL,
    interval_end timestamp(0) with time zone NOT NULL,
    granularity character varying(12) DEFAULT 'hour'::character varying NOT NULL,
    requests bigint DEFAULT '0'::bigint NOT NULL,
    bytes_in bigint DEFAULT '0'::bigint NOT NULL,
    bytes_out bigint DEFAULT '0'::bigint NOT NULL,
    cache_hits bigint DEFAULT '0'::bigint NOT NULL,
    dns_queries bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(16) DEFAULT 'finalized'::character varying NOT NULL,
    source_finalized_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT usage_rollups_granularity_check CHECK (((granularity)::text = ANY ((ARRAY['hour'::character varying, 'day'::character varying])::text[]))),
    CONSTRAINT usage_rollups_interval_check CHECK ((interval_end > interval_start)),
    CONSTRAINT usage_rollups_status_check CHECK (((status)::text = ANY ((ARRAY['provisional'::character varying, 'finalized'::character varying])::text[])))
);


--
-- Name: grafana_usage_rollups; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_usage_rollups AS
 SELECT domain_id,
    interval_start,
    interval_end,
    granularity,
    status,
    requests,
    bytes_in,
    bytes_out,
    cache_hits,
    dns_queries
   FROM public.usage_rollups;


--
-- Name: waf_exclusions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.waf_exclusions (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    dimension character varying(20) NOT NULL,
    value character varying(255) NOT NULL,
    rule_id integer,
    reason character varying(255) NOT NULL,
    owner_id bigint NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT waf_exclusions_dimension_check CHECK (((dimension)::text = ANY ((ARRAY['path'::character varying, 'rule'::character varying, 'parameter'::character varying, 'cookie'::character varying])::text[]))),
    CONSTRAINT waf_exclusions_expiry_check CHECK ((expires_at > created_at))
);


--
-- Name: grafana_waf_exclusions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.grafana_waf_exclusions AS
 SELECT id,
    domain_id,
    dimension,
    rule_id,
    expires_at,
    created_at
   FROM public.waf_exclusions;


--
-- Name: idempotency_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.idempotency_keys (
    id bigint NOT NULL,
    user_id bigint,
    key uuid NOT NULL,
    request_hash character(64) NOT NULL,
    response_status smallint NOT NULL,
    response_body jsonb NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: idempotency_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.idempotency_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: idempotency_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.idempotency_keys_id_seq OWNED BY public.idempotency_keys.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: nameserver_address_probes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nameserver_address_probes (
    id bigint NOT NULL,
    address character varying(45) NOT NULL,
    zone character varying(253) NOT NULL,
    status character varying(12) DEFAULT 'pending'::character varying NOT NULL,
    requested_at timestamp(0) with time zone NOT NULL,
    answered_at timestamp(0) with time zone,
    observer_edge_id uuid,
    last_error character varying(120),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT nameserver_address_probes_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'answering'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: nameserver_address_probes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nameserver_address_probes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nameserver_address_probes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nameserver_address_probes_id_seq OWNED BY public.nameserver_address_probes.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id bigint NOT NULL,
    data jsonb NOT NULL,
    read_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: operations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.operations (
    id uuid NOT NULL,
    actor_id bigint,
    type character varying(100) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    input jsonb DEFAULT '{}'::jsonb NOT NULL,
    result jsonb,
    error text,
    attempts integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    started_at timestamp(0) with time zone,
    finished_at timestamp(0) with time zone,
    domain_id bigint,
    CONSTRAINT operations_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'succeeded'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_token_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_token_domains (
    personal_access_token_id bigint NOT NULL,
    domain_id bigint NOT NULL
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    token_last_six character varying(6)
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: platform_dns_deployments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_dns_deployments (
    id bigint NOT NULL,
    dns_cluster_id bigint NOT NULL,
    desired_revision bigint DEFAULT '0'::bigint NOT NULL,
    deployed_revision bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    active_checksum character(64),
    active_rrsets jsonb DEFAULT '[]'::jsonb NOT NULL,
    last_error text,
    attempts integer DEFAULT 0 NOT NULL,
    last_attempted_at timestamp(0) with time zone,
    deployed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    active_zone character varying(253),
    CONSTRAINT platform_dns_deployments_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'deploying'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'obsolete'::character varying])::text[])))
);


--
-- Name: platform_dns_deployments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.platform_dns_deployments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: platform_dns_deployments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.platform_dns_deployments_id_seq OWNED BY public.platform_dns_deployments.id;


--
-- Name: platform_dns_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_dns_settings (
    id smallint NOT NULL,
    platform_domain character varying(255) NOT NULL,
    proxy_hostname character varying(255) NOT NULL,
    nameservers jsonb NOT NULL,
    soa_primary character varying(255) NOT NULL,
    soa_mailbox character varying(255) NOT NULL,
    soa_refresh integer NOT NULL,
    soa_retry integer NOT NULL,
    soa_expire integer NOT NULL,
    soa_minimum_ttl integer NOT NULL,
    default_ttl integer NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    revision bigint DEFAULT '1'::bigint NOT NULL,
    soa_serial bigint DEFAULT '0'::bigint NOT NULL,
    zone_content_checksum character varying(64),
    customer_nameserver_pairs jsonb,
    cluster_targets jsonb DEFAULT '[]'::jsonb
);


--
-- Name: security_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_events (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    edge_id uuid,
    hostname character varying(253),
    state character varying(20),
    reason_code character varying(48) NOT NULL,
    details jsonb,
    occurred_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: security_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_events_id_seq OWNED BY public.security_events.id;


--
-- Name: security_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_rules (
    id bigint NOT NULL,
    domain_id bigint NOT NULL,
    match_type character varying(16) NOT NULL,
    value character varying(128) NOT NULL,
    action character varying(8) NOT NULL,
    priority integer NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    note character varying(250),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT security_rules_action_check CHECK (((action)::text = ANY ((ARRAY['allow'::character varying, 'block'::character varying])::text[]))),
    CONSTRAINT security_rules_match_type_check CHECK (((match_type)::text = ANY ((ARRAY['ip'::character varying, 'cidr'::character varying, 'country'::character varying, 'continent'::character varying])::text[])))
);


--
-- Name: security_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_rules_id_seq OWNED BY public.security_rules.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_settings (
    "group" character varying(80) NOT NULL,
    "values" jsonb NOT NULL,
    revision bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT system_settings_values_object CHECK ((jsonb_typeof("values") = 'object'::text))
);


--
-- Name: usage_rollups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usage_rollups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usage_rollups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usage_rollups_id_seq OWNED BY public.usage_rollups.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    type character varying(16) DEFAULT 'user'::character varying NOT NULL,
    disabled_at timestamp(0) with time zone,
    app_authentication_secret text,
    app_authentication_recovery_codes text,
    CONSTRAINT users_type_check CHECK (((type)::text = ANY ((ARRAY['admin'::character varying, 'user'::character varying])::text[])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: waf_exclusions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.waf_exclusions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: waf_exclusions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.waf_exclusions_id_seq OWNED BY public.waf_exclusions.id;


--
-- Name: acme_accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.acme_accounts ALTER COLUMN id SET DEFAULT nextval('public.acme_accounts_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: control_nodes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.control_nodes ALTER COLUMN id SET DEFAULT nextval('public.control_nodes_id_seq'::regclass);


--
-- Name: dns_clusters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_clusters ALTER COLUMN id SET DEFAULT nextval('public.dns_clusters_id_seq'::regclass);


--
-- Name: dns_deployments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_deployments ALTER COLUMN id SET DEFAULT nextval('public.dns_deployments_id_seq'::regclass);


--
-- Name: dns_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records ALTER COLUMN id SET DEFAULT nextval('public.dns_records_id_seq'::regclass);


--
-- Name: dns_zone_changes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_zone_changes ALTER COLUMN id SET DEFAULT nextval('public.dns_zone_changes_id_seq'::regclass);


--
-- Name: domain_edge_cells id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells ALTER COLUMN id SET DEFAULT nextval('public.domain_edge_cells_id_seq'::regclass);


--
-- Name: domain_edge_placements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements ALTER COLUMN id SET DEFAULT nextval('public.domain_edge_placements_id_seq'::regclass);


--
-- Name: domain_name_tombstones id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_name_tombstones ALTER COLUMN id SET DEFAULT nextval('public.domain_name_tombstones_id_seq'::regclass);


--
-- Name: domains id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domains ALTER COLUMN id SET DEFAULT nextval('public.domains_id_seq'::regclass);


--
-- Name: edge_artifacts sequence; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_artifacts ALTER COLUMN sequence SET DEFAULT nextval('public.edge_artifacts_sequence_seq'::regclass);


--
-- Name: edge_cells id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells ALTER COLUMN id SET DEFAULT nextval('public.edge_cells_id_seq'::regclass);


--
-- Name: edge_pool_endpoints id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints ALTER COLUMN id SET DEFAULT nextval('public.edge_pool_endpoints_id_seq'::regclass);


--
-- Name: edge_pools id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pools ALTER COLUMN id SET DEFAULT nextval('public.edge_pools_id_seq'::regclass);


--
-- Name: edge_probe_results id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_probe_results ALTER COLUMN id SET DEFAULT nextval('public.edge_probe_results_id_seq'::regclass);


--
-- Name: edge_revisions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_revisions ALTER COLUMN id SET DEFAULT nextval('public.edge_revisions_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: fleet_releases id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_releases ALTER COLUMN id SET DEFAULT nextval('public.fleet_releases_id_seq'::regclass);


--
-- Name: fleet_rollout_edges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollout_edges ALTER COLUMN id SET DEFAULT nextval('public.fleet_rollout_edges_id_seq'::regclass);


--
-- Name: idempotency_keys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys ALTER COLUMN id SET DEFAULT nextval('public.idempotency_keys_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: nameserver_address_probes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nameserver_address_probes ALTER COLUMN id SET DEFAULT nextval('public.nameserver_address_probes_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: platform_dns_deployments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_dns_deployments ALTER COLUMN id SET DEFAULT nextval('public.platform_dns_deployments_id_seq'::regclass);


--
-- Name: security_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_events ALTER COLUMN id SET DEFAULT nextval('public.security_events_id_seq'::regclass);


--
-- Name: security_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_rules ALTER COLUMN id SET DEFAULT nextval('public.security_rules_id_seq'::regclass);


--
-- Name: usage_rollups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_rollups ALTER COLUMN id SET DEFAULT nextval('public.usage_rollups_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: waf_exclusions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waf_exclusions ALTER COLUMN id SET DEFAULT nextval('public.waf_exclusions_id_seq'::regclass);


--
-- Name: acme_accounts acme_accounts_directory_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.acme_accounts
    ADD CONSTRAINT acme_accounts_directory_url_unique UNIQUE (directory_url);


--
-- Name: acme_accounts acme_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.acme_accounts
    ADD CONSTRAINT acme_accounts_pkey PRIMARY KEY (id);


--
-- Name: acme_challenges acme_challenges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.acme_challenges
    ADD CONSTRAINT acme_challenges_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: backups backups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_pkey PRIMARY KEY (id);


--
-- Name: backups backups_snapshot_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_snapshot_id_unique UNIQUE (snapshot_id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: cache_purges cache_purges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_purges
    ADD CONSTRAINT cache_purges_pkey PRIMARY KEY (id);


--
-- Name: cache_purges cache_purges_sequence_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_purges
    ADD CONSTRAINT cache_purges_sequence_unique UNIQUE (sequence);


--
-- Name: control_nodes control_nodes_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.control_nodes
    ADD CONSTRAINT control_nodes_name_unique UNIQUE (name);


--
-- Name: control_nodes control_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.control_nodes
    ADD CONSTRAINT control_nodes_pkey PRIMARY KEY (id);


--
-- Name: control_plane_state control_plane_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.control_plane_state
    ADD CONSTRAINT control_plane_state_pkey PRIMARY KEY (id);


--
-- Name: dns_clusters dns_clusters_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_clusters
    ADD CONSTRAINT dns_clusters_name_unique UNIQUE (name);


--
-- Name: dns_clusters dns_clusters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_clusters
    ADD CONSTRAINT dns_clusters_pkey PRIMARY KEY (id);


--
-- Name: dns_deployments dns_deployments_domain_id_dns_cluster_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_deployments
    ADD CONSTRAINT dns_deployments_domain_id_dns_cluster_id_unique UNIQUE (domain_id, dns_cluster_id);


--
-- Name: dns_deployments dns_deployments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_deployments
    ADD CONSTRAINT dns_deployments_pkey PRIMARY KEY (id);


--
-- Name: dns_records dns_records_logical_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records
    ADD CONSTRAINT dns_records_logical_unique UNIQUE (domain_id, name, type, content_hash, priority, weight, port);


--
-- Name: dns_records dns_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records
    ADD CONSTRAINT dns_records_pkey PRIMARY KEY (id);


--
-- Name: dns_zone_changes dns_zone_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_zone_changes
    ADD CONSTRAINT dns_zone_changes_pkey PRIMARY KEY (id);


--
-- Name: dns_zone_changes dns_zone_changes_zone_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_zone_changes
    ADD CONSTRAINT dns_zone_changes_zone_unique UNIQUE (zone);


--
-- Name: domain_edge_cells domain_edge_cells_domain_id_edge_id_replica_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_domain_id_edge_id_replica_unique UNIQUE (domain_id, edge_id, replica);


--
-- Name: domain_edge_cells domain_edge_cells_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_pkey PRIMARY KEY (id);


--
-- Name: domain_edge_placements domain_edge_placements_domain_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements
    ADD CONSTRAINT domain_edge_placements_domain_id_unique UNIQUE (domain_id);


--
-- Name: domain_edge_placements domain_edge_placements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements
    ADD CONSTRAINT domain_edge_placements_pkey PRIMARY KEY (id);


--
-- Name: domain_name_tombstones domain_name_tombstones_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_name_tombstones
    ADD CONSTRAINT domain_name_tombstones_name_unique UNIQUE (name);


--
-- Name: domain_name_tombstones domain_name_tombstones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_name_tombstones
    ADD CONSTRAINT domain_name_tombstones_pkey PRIMARY KEY (id);


--
-- Name: domain_user domain_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_user
    ADD CONSTRAINT domain_user_pkey PRIMARY KEY (domain_id, user_id);


--
-- Name: domains domains_delegation_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domains
    ADD CONSTRAINT domains_delegation_token_unique UNIQUE (delegation_token);


--
-- Name: domains domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domains
    ADD CONSTRAINT domains_pkey PRIMARY KEY (id);


--
-- Name: edge_artifacts edge_artifacts_content_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_artifacts
    ADD CONSTRAINT edge_artifacts_content_unique UNIQUE (edge_id, domain_id, revision, kind, checksum);


--
-- Name: edge_artifacts edge_artifacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_artifacts
    ADD CONSTRAINT edge_artifacts_pkey PRIMARY KEY (sequence);


--
-- Name: edge_cells edge_cells_edge_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells
    ADD CONSTRAINT edge_cells_edge_id_name_unique UNIQUE (edge_id, name);


--
-- Name: edge_cells edge_cells_edge_id_slot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells
    ADD CONSTRAINT edge_cells_edge_id_slot_unique UNIQUE (edge_id, slot);


--
-- Name: edge_cells edge_cells_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells
    ADD CONSTRAINT edge_cells_pkey PRIMARY KEY (id);


--
-- Name: edge_pool_endpoints edge_pool_endpoints_edge_id_edge_pool_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_edge_id_edge_pool_id_unique UNIQUE (edge_id, edge_pool_id);


--
-- Name: edge_pool_endpoints edge_pool_endpoints_ipv4_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_ipv4_unique UNIQUE (ipv4);


--
-- Name: edge_pool_endpoints edge_pool_endpoints_ipv6_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_ipv6_unique UNIQUE (ipv6);


--
-- Name: edge_pool_endpoints edge_pool_endpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_pkey PRIMARY KEY (id);


--
-- Name: edge_pools edge_pools_anycast_ipv4_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pools
    ADD CONSTRAINT edge_pools_anycast_ipv4_unique UNIQUE (anycast_ipv4);


--
-- Name: edge_pools edge_pools_anycast_ipv6_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pools
    ADD CONSTRAINT edge_pools_anycast_ipv6_unique UNIQUE (anycast_ipv6);


--
-- Name: edge_pools edge_pools_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pools
    ADD CONSTRAINT edge_pools_name_unique UNIQUE (name);


--
-- Name: edge_pools edge_pools_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pools
    ADD CONSTRAINT edge_pools_pkey PRIMARY KEY (id);


--
-- Name: edge_probe_results edge_probe_results_observer_edge_id_target_edge_id_address_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_probe_results
    ADD CONSTRAINT edge_probe_results_observer_edge_id_target_edge_id_address_uniq UNIQUE (observer_edge_id, target_edge_id, address);


--
-- Name: edge_probe_results edge_probe_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_probe_results
    ADD CONSTRAINT edge_probe_results_pkey PRIMARY KEY (id);


--
-- Name: edge_revisions edge_revisions_domain_id_revision_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_revisions
    ADD CONSTRAINT edge_revisions_domain_id_revision_unique UNIQUE (domain_id, revision);


--
-- Name: edge_revisions edge_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_revisions
    ADD CONSTRAINT edge_revisions_pkey PRIMARY KEY (id);


--
-- Name: edge_tasks edge_tasks_edge_id_cache_purge_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_tasks
    ADD CONSTRAINT edge_tasks_edge_id_cache_purge_id_unique UNIQUE (edge_id, cache_purge_id);


--
-- Name: edge_tasks edge_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_tasks
    ADD CONSTRAINT edge_tasks_pkey PRIMARY KEY (id);


--
-- Name: edges edges_identity_certificate_serial_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edges
    ADD CONSTRAINT edges_identity_certificate_serial_unique UNIQUE (identity_certificate_serial);


--
-- Name: edges edges_identity_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edges
    ADD CONSTRAINT edges_identity_hash_unique UNIQUE (identity_hash);


--
-- Name: edges edges_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edges
    ADD CONSTRAINT edges_name_unique UNIQUE (name);


--
-- Name: edges edges_pending_identity_certificate_serial_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edges
    ADD CONSTRAINT edges_pending_identity_certificate_serial_unique UNIQUE (pending_identity_certificate_serial);


--
-- Name: edges edges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edges
    ADD CONSTRAINT edges_pkey PRIMARY KEY (id);


--
-- Name: emergency_modes emergency_modes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_modes
    ADD CONSTRAINT emergency_modes_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: fleet_releases fleet_releases_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_releases
    ADD CONSTRAINT fleet_releases_name_unique UNIQUE (name);


--
-- Name: fleet_releases fleet_releases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_releases
    ADD CONSTRAINT fleet_releases_pkey PRIMARY KEY (id);


--
-- Name: fleet_rollout_edges fleet_rollout_edges_fleet_rollout_id_edge_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollout_edges
    ADD CONSTRAINT fleet_rollout_edges_fleet_rollout_id_edge_id_unique UNIQUE (fleet_rollout_id, edge_id);


--
-- Name: fleet_rollout_edges fleet_rollout_edges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollout_edges
    ADD CONSTRAINT fleet_rollout_edges_pkey PRIMARY KEY (id);


--
-- Name: fleet_rollouts fleet_rollouts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollouts
    ADD CONSTRAINT fleet_rollouts_pkey PRIMARY KEY (id);


--
-- Name: generated_reports generated_reports_kind_period_format_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.generated_reports
    ADD CONSTRAINT generated_reports_kind_period_format_unique UNIQUE (kind, period, format);


--
-- Name: generated_reports generated_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.generated_reports
    ADD CONSTRAINT generated_reports_pkey PRIMARY KEY (id);


--
-- Name: idempotency_keys idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: idempotency_keys idempotency_keys_user_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_user_id_key_unique UNIQUE (user_id, key);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: nameserver_address_probes nameserver_address_probes_address_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nameserver_address_probes
    ADD CONSTRAINT nameserver_address_probes_address_unique UNIQUE (address);


--
-- Name: nameserver_address_probes nameserver_address_probes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nameserver_address_probes
    ADD CONSTRAINT nameserver_address_probes_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: operations operations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operations
    ADD CONSTRAINT operations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_token_domains personal_access_token_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_token_domains
    ADD CONSTRAINT personal_access_token_domains_pkey PRIMARY KEY (personal_access_token_id, domain_id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: platform_dns_deployments platform_dns_deployments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_dns_deployments
    ADD CONSTRAINT platform_dns_deployments_pkey PRIMARY KEY (id);


--
-- Name: platform_dns_settings platform_dns_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_dns_settings
    ADD CONSTRAINT platform_dns_settings_pkey PRIMARY KEY (id);


--
-- Name: security_events security_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_events
    ADD CONSTRAINT security_events_pkey PRIMARY KEY (id);


--
-- Name: security_rules security_rules_domain_id_match_type_value_action_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_rules
    ADD CONSTRAINT security_rules_domain_id_match_type_value_action_unique UNIQUE (domain_id, match_type, value, action);


--
-- Name: security_rules security_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_rules
    ADD CONSTRAINT security_rules_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY ("group");


--
-- Name: tls_certificates tls_certificates_domain_id_kind_fingerprint_sha256_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_certificates
    ADD CONSTRAINT tls_certificates_domain_id_kind_fingerprint_sha256_unique UNIQUE (domain_id, kind, fingerprint_sha256);


--
-- Name: tls_certificates tls_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_certificates
    ADD CONSTRAINT tls_certificates_pkey PRIMARY KEY (id);


--
-- Name: tls_orders tls_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_orders
    ADD CONSTRAINT tls_orders_pkey PRIMARY KEY (id);


--
-- Name: usage_rollups usage_rollups_interval_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_rollups
    ADD CONSTRAINT usage_rollups_interval_unique UNIQUE (domain_id, interval_start, granularity);


--
-- Name: usage_rollups usage_rollups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_rollups
    ADD CONSTRAINT usage_rollups_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: waf_exclusions waf_exclusions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waf_exclusions
    ADD CONSTRAINT waf_exclusions_pkey PRIMARY KEY (id);


--
-- Name: acme_challenges_status_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX acme_challenges_status_expires_at_index ON public.acme_challenges USING btree (status, expires_at);


--
-- Name: audit_logs_action_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_action_id_index ON public.audit_logs USING btree (action, id);


--
-- Name: audit_logs_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_subject_type_subject_id_index ON public.audit_logs USING btree (subject_type, subject_id);


--
-- Name: backups_health_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_health_idx ON public.backups USING btree (status, verified_at);


--
-- Name: backups_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_status_created_at_index ON public.backups USING btree (status, created_at);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: cache_purges_domain_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_purges_domain_id_created_at_index ON public.cache_purges USING btree (domain_id, created_at);


--
-- Name: cache_purges_health_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_purges_health_idx ON public.cache_purges USING btree (status, created_at);


--
-- Name: control_nodes_one_per_role; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX control_nodes_one_per_role ON public.control_nodes USING btree (role);


--
-- Name: dns_clusters_enabled_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dns_clusters_enabled_id_index ON public.dns_clusters USING btree (enabled, id);


--
-- Name: dns_clusters_single_enabled; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX dns_clusters_single_enabled ON public.dns_clusters USING btree ((1)) WHERE enabled;


--
-- Name: dns_deployments_status_updated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dns_deployments_status_updated_at_index ON public.dns_deployments USING btree (status, updated_at);


--
-- Name: dns_deployments_verification_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dns_deployments_verification_order ON public.dns_deployments USING btree (verified_at, id);


--
-- Name: dns_records_domain_id_name_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dns_records_domain_id_name_type_id_index ON public.dns_records USING btree (domain_id, name, type, id);


--
-- Name: domain_edge_cells_active_cell_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_edge_cells_active_cell_id_state_index ON public.domain_edge_cells USING btree (active_cell_id, state);


--
-- Name: domain_edge_cells_edge_id_target_cell_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_edge_cells_edge_id_target_cell_id_state_index ON public.domain_edge_cells USING btree (edge_id, target_cell_id, state);


--
-- Name: domain_edge_placements_health_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_edge_placements_health_idx ON public.domain_edge_placements USING btree (state, desired_revision, domain_id);


--
-- Name: domain_name_tombstones_reclaim_after_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_name_tombstones_reclaim_after_index ON public.domain_name_tombstones USING btree (reclaim_after);


--
-- Name: domain_user_user_id_domain_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_user_user_id_domain_id_index ON public.domain_user USING btree (user_id, domain_id);


--
-- Name: domains_claim_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domains_claim_expires_at_index ON public.domains USING btree (claim_expires_at);


--
-- Name: domains_lifecycle_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domains_lifecycle_state_id_index ON public.domains USING btree (lifecycle_state, id);


--
-- Name: domains_name_holder_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX domains_name_holder_unique ON public.domains USING btree (name) WHERE ((deleted_at IS NULL) AND ((lifecycle_state)::text = ANY ((ARRAY['active'::character varying, 'disabled'::character varying])::text[])));


--
-- Name: domains_name_live; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domains_name_live ON public.domains USING btree (name) WHERE (deleted_at IS NULL);


--
-- Name: domains_name_pair_reserved; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX domains_name_pair_reserved ON public.domains USING btree (name, delegation_nameservers) WHERE ((deleted_at IS NULL) AND (delegation_nameservers IS NOT NULL) AND ((lifecycle_state)::text <> 'deprovisioning'::text));


--
-- Name: edge_artifacts_domain_revision_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_artifacts_domain_revision_index ON public.edge_artifacts USING btree (domain_id, revision);


--
-- Name: edge_artifacts_edge_id_sequence_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_artifacts_edge_id_sequence_index ON public.edge_artifacts USING btree (edge_id, sequence);


--
-- Name: edge_cells_health_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_cells_health_idx ON public.edge_cells USING btree (status, drained, edge_id);


--
-- Name: edge_pool_endpoints_edge_pool_id_withdrawn_gateway_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_pool_endpoints_edge_pool_id_withdrawn_gateway_state_index ON public.edge_pool_endpoints USING btree (edge_pool_id, withdrawn, gateway_state);


--
-- Name: edge_probe_results_target_edge_id_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_probe_results_target_edge_id_checked_at_index ON public.edge_probe_results USING btree (target_edge_id, checked_at);


--
-- Name: edge_revisions_retention_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_revisions_retention_index ON public.edge_revisions USING btree (created_at, domain_id);


--
-- Name: edge_tasks_edge_id_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_tasks_edge_id_status_created_at_index ON public.edge_tasks USING btree (edge_id, status, created_at);


--
-- Name: edge_tasks_status_available_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_tasks_status_available_at_index ON public.edge_tasks USING btree (status, available_at);


--
-- Name: edges_enabled_drained_last_heartbeat_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edges_enabled_drained_last_heartbeat_at_index ON public.edges USING btree (enabled, drained, last_heartbeat_at);


--
-- Name: emergency_modes_active_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emergency_modes_active_expires_at_index ON public.emergency_modes USING btree (active, expires_at);


--
-- Name: emergency_modes_target_type_target_id_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX emergency_modes_target_type_target_id_active_index ON public.emergency_modes USING btree (target_type, target_id, active);


--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);


--
-- Name: fleet_rollout_edges_fleet_rollout_id_wave_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fleet_rollout_edges_fleet_rollout_id_wave_status_index ON public.fleet_rollout_edges USING btree (fleet_rollout_id, wave, status);


--
-- Name: fleet_rollouts_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fleet_rollouts_status_created_at_index ON public.fleet_rollouts USING btree (status, created_at);


--
-- Name: generated_reports_created_at_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX generated_reports_created_at_id_index ON public.generated_reports USING btree (created_at, id);


--
-- Name: idempotency_keys_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idempotency_keys_expires_at_index ON public.idempotency_keys USING btree (expires_at);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: notifications_read_at_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_read_at_created_at_index ON public.notifications USING btree (read_at, created_at);


--
-- Name: operations_one_unfinished_per_domain; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX operations_one_unfinished_per_domain ON public.operations USING btree (type, domain_id) WHERE (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying])::text[])) AND (domain_id IS NOT NULL) AND ((type)::text = ANY ((ARRAY['dns.zone_reconcile'::character varying, 'edge.domain_reconcile'::character varying])::text[])));


--
-- Name: operations_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX operations_status_created_at_index ON public.operations USING btree (status, created_at);


--
-- Name: personal_access_token_domains_domain_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_token_domains_domain_id_index ON public.personal_access_token_domains USING btree (domain_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: platform_dns_deployments_status_updated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX platform_dns_deployments_status_updated_at_index ON public.platform_dns_deployments USING btree (status, updated_at);


--
-- Name: security_events_domain_id_occurred_at_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_events_domain_id_occurred_at_id_index ON public.security_events USING btree (domain_id, occurred_at, id);


--
-- Name: security_rules_domain_id_enabled_priority_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_rules_domain_id_enabled_priority_id_index ON public.security_rules USING btree (domain_id, enabled, priority, id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: tls_certificates_status_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tls_certificates_status_expires_at_index ON public.tls_certificates USING btree (status, expires_at);


--
-- Name: tls_orders_active_names_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX tls_orders_active_names_unique ON public.tls_orders USING btree (domain_id, names_hash) WHERE ((status)::text = ANY ((ARRAY['pending'::character varying, 'creating'::character varying, 'publishing'::character varying, 'validating'::character varying, 'finalizing'::character varying])::text[]));


--
-- Name: tls_orders_domain_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tls_orders_domain_id_created_at_index ON public.tls_orders USING btree (domain_id, created_at);


--
-- Name: tls_orders_status_available_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tls_orders_status_available_at_index ON public.tls_orders USING btree (status, available_at);


--
-- Name: usage_rollups_health_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX usage_rollups_health_idx ON public.usage_rollups USING btree (status, interval_end);


--
-- Name: usage_rollups_interval_start_domain_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX usage_rollups_interval_start_domain_id_index ON public.usage_rollups USING btree (interval_start, domain_id);


--
-- Name: users_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_type_id_index ON public.users USING btree (type, id);


--
-- Name: waf_exclusions_domain_id_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX waf_exclusions_domain_id_expires_at_index ON public.waf_exclusions USING btree (domain_id, expires_at);


--
-- Name: acme_challenges acme_challenges_tls_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.acme_challenges
    ADD CONSTRAINT acme_challenges_tls_order_id_foreign FOREIGN KEY (tls_order_id) REFERENCES public.tls_orders(id) ON DELETE CASCADE;


--
-- Name: audit_logs audit_logs_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: backups backups_requested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cache_purges cache_purges_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_purges
    ADD CONSTRAINT cache_purges_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: cache_purges cache_purges_operation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_purges
    ADD CONSTRAINT cache_purges_operation_id_foreign FOREIGN KEY (operation_id) REFERENCES public.operations(id) ON DELETE SET NULL;


--
-- Name: cache_purges cache_purges_requested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_purges
    ADD CONSTRAINT cache_purges_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: dns_deployments dns_deployments_dns_cluster_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_deployments
    ADD CONSTRAINT dns_deployments_dns_cluster_id_foreign FOREIGN KEY (dns_cluster_id) REFERENCES public.dns_clusters(id) ON DELETE CASCADE;


--
-- Name: dns_deployments dns_deployments_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_deployments
    ADD CONSTRAINT dns_deployments_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: dns_records dns_records_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records
    ADD CONSTRAINT dns_records_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: domain_edge_cells domain_edge_cells_active_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_active_cell_id_foreign FOREIGN KEY (active_cell_id) REFERENCES public.edge_cells(id) ON DELETE RESTRICT;


--
-- Name: domain_edge_cells domain_edge_cells_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: domain_edge_cells domain_edge_cells_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: domain_edge_cells domain_edge_cells_target_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_cells
    ADD CONSTRAINT domain_edge_cells_target_cell_id_foreign FOREIGN KEY (target_cell_id) REFERENCES public.edge_cells(id) ON DELETE RESTRICT;


--
-- Name: domain_edge_placements domain_edge_placements_active_pool_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements
    ADD CONSTRAINT domain_edge_placements_active_pool_id_foreign FOREIGN KEY (active_pool_id) REFERENCES public.edge_pools(id) ON DELETE RESTRICT;


--
-- Name: domain_edge_placements domain_edge_placements_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements
    ADD CONSTRAINT domain_edge_placements_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: domain_edge_placements domain_edge_placements_target_pool_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_edge_placements
    ADD CONSTRAINT domain_edge_placements_target_pool_id_foreign FOREIGN KEY (target_pool_id) REFERENCES public.edge_pools(id) ON DELETE RESTRICT;


--
-- Name: domain_user domain_user_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_user
    ADD CONSTRAINT domain_user_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: domain_user domain_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_user
    ADD CONSTRAINT domain_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: domains domains_active_tls_certificate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domains
    ADD CONSTRAINT domains_active_tls_certificate_id_foreign FOREIGN KEY (active_tls_certificate_id) REFERENCES public.tls_certificates(id) ON DELETE SET NULL;


--
-- Name: domains domains_nameservers_verified_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domains
    ADD CONSTRAINT domains_nameservers_verified_by_foreign FOREIGN KEY (nameservers_verified_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_artifacts edge_artifacts_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_artifacts
    ADD CONSTRAINT edge_artifacts_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: edge_artifacts edge_artifacts_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_artifacts
    ADD CONSTRAINT edge_artifacts_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: edge_cells edge_cells_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells
    ADD CONSTRAINT edge_cells_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: edge_cells edge_cells_edge_pool_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_cells
    ADD CONSTRAINT edge_cells_edge_pool_id_foreign FOREIGN KEY (edge_pool_id) REFERENCES public.edge_pools(id) ON DELETE RESTRICT;


--
-- Name: edge_pool_endpoints edge_pool_endpoints_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: edge_pool_endpoints edge_pool_endpoints_edge_pool_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_pool_endpoints
    ADD CONSTRAINT edge_pool_endpoints_edge_pool_id_foreign FOREIGN KEY (edge_pool_id) REFERENCES public.edge_pools(id) ON DELETE CASCADE;


--
-- Name: edge_probe_results edge_probe_results_observer_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_probe_results
    ADD CONSTRAINT edge_probe_results_observer_edge_id_foreign FOREIGN KEY (observer_edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: edge_probe_results edge_probe_results_target_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_probe_results
    ADD CONSTRAINT edge_probe_results_target_edge_id_foreign FOREIGN KEY (target_edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: edge_revisions edge_revisions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_revisions
    ADD CONSTRAINT edge_revisions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_revisions edge_revisions_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_revisions
    ADD CONSTRAINT edge_revisions_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: edge_tasks edge_tasks_cache_purge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_tasks
    ADD CONSTRAINT edge_tasks_cache_purge_id_foreign FOREIGN KEY (cache_purge_id) REFERENCES public.cache_purges(id) ON DELETE CASCADE;


--
-- Name: edge_tasks edge_tasks_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_tasks
    ADD CONSTRAINT edge_tasks_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: emergency_modes emergency_modes_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emergency_modes
    ADD CONSTRAINT emergency_modes_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fleet_rollout_edges fleet_rollout_edges_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollout_edges
    ADD CONSTRAINT fleet_rollout_edges_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE CASCADE;


--
-- Name: fleet_rollout_edges fleet_rollout_edges_fleet_rollout_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollout_edges
    ADD CONSTRAINT fleet_rollout_edges_fleet_rollout_id_foreign FOREIGN KEY (fleet_rollout_id) REFERENCES public.fleet_rollouts(id) ON DELETE CASCADE;


--
-- Name: fleet_rollouts fleet_rollouts_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollouts
    ADD CONSTRAINT fleet_rollouts_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fleet_rollouts fleet_rollouts_fleet_release_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollouts
    ADD CONSTRAINT fleet_rollouts_fleet_release_id_foreign FOREIGN KEY (fleet_release_id) REFERENCES public.fleet_releases(id) ON DELETE RESTRICT;


--
-- Name: fleet_rollouts fleet_rollouts_operation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollouts
    ADD CONSTRAINT fleet_rollouts_operation_id_foreign FOREIGN KEY (operation_id) REFERENCES public.operations(id) ON DELETE SET NULL;


--
-- Name: fleet_rollouts fleet_rollouts_previous_release_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fleet_rollouts
    ADD CONSTRAINT fleet_rollouts_previous_release_id_foreign FOREIGN KEY (previous_release_id) REFERENCES public.fleet_releases(id) ON DELETE RESTRICT;


--
-- Name: idempotency_keys idempotency_keys_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: nameserver_address_probes nameserver_address_probes_observer_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nameserver_address_probes
    ADD CONSTRAINT nameserver_address_probes_observer_edge_id_foreign FOREIGN KEY (observer_edge_id) REFERENCES public.edges(id) ON DELETE SET NULL;


--
-- Name: operations operations_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operations
    ADD CONSTRAINT operations_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: operations operations_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.operations
    ADD CONSTRAINT operations_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE SET NULL;


--
-- Name: personal_access_token_domains personal_access_token_domains_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_token_domains
    ADD CONSTRAINT personal_access_token_domains_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: personal_access_token_domains personal_access_token_domains_personal_access_token_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_token_domains
    ADD CONSTRAINT personal_access_token_domains_personal_access_token_id_foreign FOREIGN KEY (personal_access_token_id) REFERENCES public.personal_access_tokens(id) ON DELETE CASCADE;


--
-- Name: platform_dns_deployments platform_dns_deployments_dns_cluster_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_dns_deployments
    ADD CONSTRAINT platform_dns_deployments_dns_cluster_id_foreign FOREIGN KEY (dns_cluster_id) REFERENCES public.dns_clusters(id) ON DELETE CASCADE;


--
-- Name: security_events security_events_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_events
    ADD CONSTRAINT security_events_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: security_events security_events_edge_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_events
    ADD CONSTRAINT security_events_edge_id_foreign FOREIGN KEY (edge_id) REFERENCES public.edges(id) ON DELETE SET NULL;


--
-- Name: security_rules security_rules_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_rules
    ADD CONSTRAINT security_rules_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: tls_certificates tls_certificates_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_certificates
    ADD CONSTRAINT tls_certificates_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: tls_orders tls_orders_acme_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_orders
    ADD CONSTRAINT tls_orders_acme_account_id_foreign FOREIGN KEY (acme_account_id) REFERENCES public.acme_accounts(id) ON DELETE SET NULL;


--
-- Name: tls_orders tls_orders_certificate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_orders
    ADD CONSTRAINT tls_orders_certificate_id_foreign FOREIGN KEY (certificate_id) REFERENCES public.tls_certificates(id) ON DELETE SET NULL;


--
-- Name: tls_orders tls_orders_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tls_orders
    ADD CONSTRAINT tls_orders_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: usage_rollups usage_rollups_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usage_rollups
    ADD CONSTRAINT usage_rollups_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: waf_exclusions waf_exclusions_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waf_exclusions
    ADD CONSTRAINT waf_exclusions_domain_id_foreign FOREIGN KEY (domain_id) REFERENCES public.domains(id) ON DELETE CASCADE;


--
-- Name: waf_exclusions waf_exclusions_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.waf_exclusions
    ADD CONSTRAINT waf_exclusions_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- PostgreSQL database dump complete
--

\unrestrict Q6bVKd0l5gZ9C1fXSc0soYVVbYAiXDdsTpHOzMNPcDWDaIwl6f7Xr4LoueSD4dR

--
-- PostgreSQL database dump
--

\restrict 3m1EQDrqu2CGcUiL2TRuSH0zcmNWsG2h2dSB8jathGQWzbFE2WYaFJdh1LpQaG2

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: control_plane_state; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.control_plane_state (id, epoch, active_node, addresses, created_at, updated_at) VALUES (1, 1, NULL, '[]', '2026-11-05 13:07:15+00', '2026-11-05 13:07:15+00');


--
-- Data for Name: edge_pools; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.edge_pools (id, name, kind, enabled, revision, created_at, updated_at, withdrawn, minimum_ready_cells, replicas_per_edge, maximum_domains_per_cell, routing_mode, anycast_ipv4, anycast_ipv6, cache_profile, compression_profile, waf_capable, waf_runtime_version, waf_canary_state, dns_fail_static) VALUES (1, 'shared-default', 'shared', true, 1, '2026-11-05 13:07:12+00', '2026-11-05 13:07:12+00', false, 1, 1, 20000, 'geo_unicast', NULL, NULL, 'standard', 'standard', false, NULL, 'not_required', false);
INSERT INTO public.edge_pools (id, name, kind, enabled, revision, created_at, updated_at, withdrawn, minimum_ready_cells, replicas_per_edge, maximum_domains_per_cell, routing_mode, anycast_ipv4, anycast_ipv6, cache_profile, compression_profile, waf_capable, waf_runtime_version, waf_canary_state, dns_fail_static) VALUES (2, 'quarantine-default', 'quarantine', true, 1, '2026-11-05 13:07:12+00', '2026-11-05 13:07:12+00', false, 1, 1, 20000, 'geo_unicast', NULL, NULL, 'standard', 'standard', false, NULL, 'not_required', false);


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.migrations (id, migration, batch) VALUES (1, '0001_01_01_000000_create_users_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (2, '0001_01_01_000001_create_cache_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (3, '0001_01_01_000002_create_jobs_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (4, '2026_07_16_150027_create_personal_access_tokens_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (5, '2026_07_16_160000_add_access_fields_to_users_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (6, '2026_07_16_160100_create_audit_logs_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (7, '2026_07_16_160200_create_idempotency_keys_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (8, '2026_07_16_160300_create_operations_and_platform_settings_tables', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (9, '2026_07_16_160310_allow_uuid_audit_subjects', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (10, '2026_07_17_000100_create_domains_tables', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (11, '2026_07_17_000200_create_dns_records_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (12, '2026_07_17_000300_create_dns_clusters_and_deployments_tables', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (13, '2026_07_17_000400_add_dns_verification_and_tombstones', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (14, '2026_07_17_000400_create_platform_dns_deployments_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (15, '2026_07_17_180000_add_token_last_six_to_personal_access_tokens_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (16, '2026_07_17_200000_add_geo_dns_to_dns_records', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (17, '2026_07_17_210000_create_edge_proxy_tables', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (18, '2026_07_17_220000_expand_edge_artifact_signatures', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (19, '2026_07_17_230000_allow_edge_placement_artifacts', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (20, '2026_07_17_240000_add_edge_identity_certificate', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (21, '2026_07_18_010000_align_edge_cell_runtime_names', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (22, '2026_07_18_020000_allow_geo_dns_for_all_record_types', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (23, '2026_07_18_030000_restrict_geo_dns_to_runtime_types', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (24, '2026_07_18_040000_harden_edge_runtime_state', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (25, '2026_07_18_050000_add_domain_reclaim_state', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (26, '2026_07_18_060000_track_platform_dns_active_zone', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (27, '2026_07_18_070000_create_system_settings_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (28, '2026_07_18_080000_enforce_domain_proxy_settings_shape', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (29, '2026_07_19_000100_create_cache_purges', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (30, '2026_07_19_010000_create_tls_state', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (31, '2026_07_19_010100_add_managed_tls_workflow', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (32, '2026_07_19_010200_create_notifications_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (33, '2026_07_19_010300_convert_notification_data_to_jsonb', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (34, '2026_07_19_020000_configure_revision_history', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (35, '2026_07_20_000000_create_usage_rollups', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (36, '2026_07_20_000100_create_security_readiness_state', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (37, '2026_07_20_010000_add_operations_settings', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (38, '2026_07_20_020000_create_backups_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (39, '2026_07_20_030000_add_operational_health_indexes', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (40, '2026_07_27_000000_add_bounded_edge_cell_slots', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (41, '2026_07_28_000000_add_multi_cell_pool_placement', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (42, '2026_07_28_010000_create_edge_pool_endpoints', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (43, '2026_07_28_020000_remove_legacy_cell_addresses_and_name_management_addresses', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (44, '2026_07_28_030000_add_simple_anycast_pool_routing', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (45, '2026_07_28_100000_add_cache_profiles_to_edge_pools', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (46, '2026_07_29_000000_add_compression_profiles_to_edge_pools', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (47, '2026_07_29_100000_add_managed_waf', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (48, '2026_07_29_200000_add_observability_and_fleet_rollouts', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (49, '2026_07_30_000000_create_grafana_domain_operational_metadata_view', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (50, '2026_08_01_170000_add_observability_settings', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (51, '2026_09_05_160000_add_domain_delegation_claims', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (52, '2026_09_20_170000_add_display_timezone_setting', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (53, '2026_10_29_000000_widen_operation_attempt_counters', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (54, '2026_10_30_100000_scrub_private_keys_from_edge_state', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (55, '2026_10_31_000000_add_pop_health_probing', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (56, '2026_10_31_000100_add_edge_identity_renewal', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (57, '2026_10_31_000200_add_edge_promotion_and_probe_settings', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (58, '2026_11_01_000000_add_dns_deployment_verification_time', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (59, '2026_11_01_000100_add_platform_zone_serial', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (60, '2026_11_01_000200_type_the_operation_domain', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (61, '2026_11_01_000300_number_cache_purges_and_link_operations', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (62, '2026_11_01_000400_link_fleet_rollouts_to_operations', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (63, '2026_11_01_000500_allow_competing_domain_claims', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (64, '2026_11_01_000600_add_customer_nameserver_pairs', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (65, '2026_11_01_000700_add_hidden_primary_transfer', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (66, '2026_11_01_000800_add_api_token_domain_scope', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (67, '2026_11_01_000900_expire_edge_bootstrap_tokens', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (68, '2026_11_01_001000_add_administrator_two_step_sign_in', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (69, '2026_11_01_001100_create_nameserver_address_probes', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (70, '2026_11_01_001200_create_control_plane_high_availability', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (71, '2026_11_03_000100_stop_using_platform_dns_cluster_targets', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (72, '2026_11_03_000200_drop_edge_management_addresses', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (73, '2026_11_05_000100_add_observability_retention_settings', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (74, '2026_11_05_000200_create_grafana_operational_views', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (75, '2026_11_05_000300_create_generated_reports', 1);


--
-- Data for Name: system_settings; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('display', '{"timezone": "UTC"}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('observability', '{"grafana_explore_url": null}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('dns_lifecycle', '{"deprovision_delay_days": 7, "domain_reclaim_cooldown_days": 7}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('revision_history', '{"retention_days": 1, "minimum_revisions_per_domain": 10}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('rate_limits', '{"bulk_per_minute": 12, "login_per_minute": 10, "edge_agent_per_minute": 600, "origin_tests_per_minute": 20, "account_reads_per_minute": 600, "edge_registrations_per_hour": 10, "account_mutations_per_minute": 240}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('origin_safety', '{"blocked_origin_networks": [], "blocked_origin_addresses": [], "private_origin_allowlist": []}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('proxy_defaults', '{"enabled": true, "retry_count": 0, "http_versions": ["1.1", "2"], "redirect_https": false}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:13+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('edge_runtime', '{"heartbeat_fresh_seconds": 45, "placement_drain_seconds": 300, "promotion_grace_seconds": 120, "probe_min_vantage_points": 2, "promotion_quorum_percent": 100, "max_domain_artifact_bytes": 2097152, "probe_result_max_age_seconds": 180}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:15+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('operations', '{"backup_stale_hours": 26, "audit_retention_days": 365, "scheduler_stale_seconds": 180, "clock_drift_warning_seconds": 5, "failed_operations_window_minutes": 60}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:15+00');
INSERT INTO public.system_settings ("group", "values", revision, created_at, updated_at) VALUES ('telemetry', '{"ipv4_mask_bits": 24, "ipv6_mask_bits": 48, "raw_retention_days": 7, "loki_retention_days": 14, "daily_retention_days": 1095, "hourly_retention_days": 400, "prometheus_retention_days": 30, "finalization_delay_minutes": 15, "one_minute_retention_hours": 48}', 1, '2026-11-05 13:07:13+00', '2026-11-05 13:07:16+00');


--
-- Name: edge_pools_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.edge_pools_id_seq', 2, true);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 76, true);


--
-- PostgreSQL database dump complete
--

\unrestrict 3m1EQDrqu2CGcUiL2TRuSH0zcmNWsG2h2dSB8jathGQWzbFE2WYaFJdh1LpQaG2

