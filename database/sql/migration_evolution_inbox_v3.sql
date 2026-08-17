-- Atendimento WhatsApp v3: múltiplas linhas da Evolution e fluxos guiados.
-- Rode após migration_evolution_inbox.sql e migration_evolution_api.sql.

CREATE TABLE IF NOT EXISTS evolution_instances (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_name VARCHAR(120) NOT NULL,
    label VARCHAR(120) NOT NULL,
    payload_mode ENUM('auto','official','legacy_text') NOT NULL DEFAULT 'auto',
    active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evolution_instances_name (instance_name),
    KEY idx_evolution_instances_active (active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evolution_service_flows (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    instance_name VARCHAR(120) NULL COMMENT 'Nulo = disponível em todas as linhas',
    steps_json JSON NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evolution_flows_instance_active (instance_name, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a instância já configurada como a linha inicial do novo modelo.
INSERT IGNORE INTO evolution_instances (instance_name, label, payload_mode, active, is_default)
SELECT `value`, 'WhatsApp principal', 'auto', 1, 1
FROM settings WHERE `key` = 'evolution_instance_name' AND `value` IS NOT NULL AND `value` <> '';

ALTER TABLE evolution_conversation_links
    ADD COLUMN IF NOT EXISTS instance_name VARCHAR(120) NULL AFTER conversation_id,
    ADD COLUMN IF NOT EXISTS remote_jid VARCHAR(120) NULL AFTER instance_name,
    ADD COLUMN IF NOT EXISTS flow_id INT UNSIGNED NULL AFTER labels,
    ADD COLUMN IF NOT EXISTS flow_step INT UNSIGNED NOT NULL DEFAULT 0 AFTER flow_id,
    ADD KEY IF NOT EXISTS idx_evo_link_instance_remote (instance_name, remote_jid),
    ADD KEY IF NOT EXISTS idx_evo_link_flow (flow_id);

ALTER TABLE evolution_messages
    ADD COLUMN IF NOT EXISTS instance_name VARCHAR(120) NULL AFTER conversation_id,
    ADD KEY IF NOT EXISTS idx_evo_msg_instance (instance_name, id);

SET @tc_evo_legacy_instance = (SELECT instance_name FROM evolution_instances WHERE active = 1 ORDER BY is_default DESC, id ASC LIMIT 1);
UPDATE evolution_conversation_links
SET instance_name = COALESCE(NULLIF(instance_name, ''), @tc_evo_legacy_instance),
    remote_jid = COALESCE(NULLIF(remote_jid, ''), conversation_id)
WHERE instance_name IS NULL OR instance_name = '' OR remote_jid IS NULL OR remote_jid = '';

UPDATE evolution_messages m
INNER JOIN evolution_conversation_links c ON c.conversation_id = m.conversation_id
SET m.instance_name = c.instance_name
WHERE m.instance_name IS NULL OR m.instance_name = '';
