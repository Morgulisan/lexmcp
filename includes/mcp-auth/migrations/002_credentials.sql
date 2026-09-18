CREATE TABLE IF NOT EXISTS mpauth_credentials (
    user_id BIGINT UNSIGNED NOT NULL,
    service_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ciphertext BLOB NOT NULL,
    nonce BINARY(24) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(user_id,service_id)
) ENGINE=InnoDB;
