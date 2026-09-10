CREATE TABLE IF NOT EXISTS lxmcp_user_settings (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    finalize_enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lxmcp_uploads (
    id CHAR(36) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    connection_id CHAR(36) NOT NULL,
    account_id CHAR(36) NOT NULL,
    account_alias VARCHAR(96) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    metadata_json LONGTEXT NOT NULL,
    expires_at BIGINT NOT NULL,
    binding_hash CHAR(64) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_lxmcp_upload_owner (user_id, connection_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
