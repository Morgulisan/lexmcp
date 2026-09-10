CREATE TABLE IF NOT EXISTS lxmcp_oauth_connections (
    id CHAR(36) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    name VARCHAR(96) NOT NULL,
    requested_scopes_json JSON NOT NULL,
    allowed_scopes_json JSON NOT NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY ix_lxmcp_connection_user (user_id, revoked_at, updated_at),
    KEY ix_lxmcp_connection_client (client_id(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lxmcp_oauth_connections
    (id, user_id, client_id, name, requested_scopes_json, allowed_scopes_json)
SELECT
    t.family_id,
    t.user_id,
    t.client_id,
    LEFT(COALESCE(NULLIF(c.client_name, ''), 'MCP-Verbindung'), 96),
    t.scopes_json,
    t.scopes_json
FROM lxmcp_oauth_tokens t
LEFT JOIN lxmcp_oauth_clients c ON c.client_id = t.client_id
WHERE t.token_type = 'refresh'
  AND t.revoked_at IS NULL
  AND t.expires_at > NOW(6)
  AND t.created_at = (
      SELECT MAX(t2.created_at)
      FROM lxmcp_oauth_tokens t2
      WHERE t2.family_id = t.family_id AND t2.token_type = 'refresh'
  );
