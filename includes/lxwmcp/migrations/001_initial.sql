CREATE TABLE IF NOT EXISTS lxmcp_schema_migrations (
    version VARCHAR(64) PRIMARY KEY,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_accounts (
    id CHAR(36) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    alias VARCHAR(96) NOT NULL,
    alias_normalized VARCHAR(96) NOT NULL,
    organization_id CHAR(36) NULL,
    organization_name VARCHAR(255) NULL,
    api_key_ciphertext BLOB NOT NULL,
    api_key_nonce BINARY(24) NOT NULL,
    api_key_fingerprint CHAR(64) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_lxmcp_account_alias (user_id, alias_normalized),
    KEY ix_lxmcp_account_fingerprint (api_key_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_oauth_clients (
    client_id VARCHAR(512) PRIMARY KEY,
    client_name VARCHAR(255) NOT NULL,
    redirect_uris JSON NOT NULL,
    token_endpoint_auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    disabled_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_oauth_requests (
    request_id CHAR(64) PRIMARY KEY,
    parameters_json JSON NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_oauth_codes (
    code_hash CHAR(64) PRIMARY KEY,
    client_id VARCHAR(512) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    redirect_uri VARCHAR(2048) NOT NULL,
    resource VARCHAR(2048) NOT NULL,
    scopes_json JSON NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_lxmcp_code_client (client_id(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_oauth_tokens (
    token_hash CHAR(64) PRIMARY KEY,
    token_type ENUM('access','refresh') NOT NULL,
    family_id CHAR(36) NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    resource VARCHAR(2048) NOT NULL,
    scopes_json JSON NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_lxmcp_token_family (family_id),
    KEY ix_lxmcp_token_subject (user_id, token_type, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_web_sessions (
    session_hash CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_rate_limits (
    api_key_fingerprint CHAR(64) PRIMARY KEY,
    next_allowed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    blocked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_idempotency (
    user_id BIGINT UNSIGNED NOT NULL,
    account_id CHAR(36) NOT NULL,
    operation VARCHAR(128) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    state ENUM('pending','complete','uncertain','failed') NOT NULL,
    result_json JSON NULL,
    error_code VARCHAR(96) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, account_id, operation, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lxmcp_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trace_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    account_id CHAR(36) NULL,
    operation VARCHAR(128) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    payload_hash CHAR(64) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_lxmcp_audit_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
