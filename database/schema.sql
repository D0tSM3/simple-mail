CREATE DATABASE IF NOT EXISTS smart_mailbox
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_mailbox;

CREATE TABLE IF NOT EXISTS app_users (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_sub VARCHAR(255) NOT NULL UNIQUE,   -- stable OpenID "sub"
    email      VARCHAR(254) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
    user_id       BIGINT UNSIGNED PRIMARY KEY,
    access_token  TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at    BIGINT UNSIGNED NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_oauth_user FOREIGN KEY (user_id)
        REFERENCES app_users(id) ON DELETE CASCADE
);

-- This file creates the database schema only. Create a dedicated application
-- account separately with a unique password and only SELECT/INSERT/UPDATE/DELETE
-- privileges on smart_mailbox.*. Never use a placeholder password.
