CREATE TABLE IF NOT EXISTS mpauth_oauth_clients (
    client_id VARCHAR(512) PRIMARY KEY,
    client_name VARCHAR(255) NOT NULL,
    redirect_uris JSON NOT NULL,
    token_endpoint_auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    disabled_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mpauth_oauth_requests (
    request_id CHAR(64) PRIMARY KEY,
    parameters_json JSON NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mpauth_oauth_codes (
    code_hash CHAR(64) PRIMARY KEY,
    family_id CHAR(36) NULL,
    client_id VARCHAR(512) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    redirect_uri VARCHAR(2048) NOT NULL,
    resource VARCHAR(2048) NOT NULL,
    scopes_json JSON NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_mpauth_code_client (client_id(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mpauth_oauth_tokens (
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
    KEY ix_mpauth_token_family (family_id),
    KEY ix_mpauth_token_subject (user_id, token_type, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mpauth_web_sessions (
    session_hash CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mpauth_oauth_connections (
    id CHAR(36) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    name VARCHAR(96) NOT NULL,
    requested_scopes_json JSON NOT NULL,
    allowed_scopes_json JSON NOT NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY ix_mpauth_connection_user (user_id, revoked_at, updated_at),
    KEY ix_mpauth_connection_client (client_id(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS mpauth_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT NOT NULL, expires_at BIGINT NOT NULL
) ENGINE=InnoDB;
