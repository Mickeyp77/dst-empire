-- Migration 074: DST Empire — chat log
-- Persists every Advisor::chat() turn for resumable, auditable DST sessions.
-- Local Docker dev DB only — DO NOT apply to prod Galera (192.168.1.132)
-- without Mickey's explicit go-ahead.
-- MariaDB 10.11+ / 11.x. Idempotent.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS empire_chat_log (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    ts                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_prompt        MEDIUMTEXT NOT NULL,
    assistant_response MEDIUMTEXT NOT NULL,
    model_used         VARCHAR(80) NULL,
    context_entity_ids JSON NULL,
    reason             VARCHAR(200) NULL,
    INDEX idx_tenant_ts (tenant_id, ts),
    INDEX idx_tenant_id (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 074 complete.
