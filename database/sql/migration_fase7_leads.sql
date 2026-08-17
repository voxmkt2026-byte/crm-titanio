-- =====================================================================
-- Titanium CRM - Migration Fase 7 (Leads/Pipeline - auditoria de UX)
-- Execute este arquivo SOMENTE APÓS já ter importado, nesta ordem, todas as
-- migrations anteriores (schema.sql, seed.sql, migration_fase2.sql até
-- migration_fase6/migration_tasks/migration_chat, conforme aplicável ao
-- seu banco).
--
-- O que esta migration cria/altera:
--   - Tabela `whatsapp_templates`: templates de mensagem para WhatsApp com
--     placeholders {{nome}}/{{interesse}}/{{responsavel}} (ver
--     app/controllers/WhatsappTemplateController.php), com alguns templates
--     de exemplo já cadastrados (seed idempotente via INSERT IGNORE por nome).
--   - leads: índices idx_leads_next_contact_at e idx_leads_last_contact_at,
--     para acelerar Agenda/relatórios/ordenação por essas colunas.
--   - lead_history: índice idx_lead_history_type.
--
-- Esta migration é incremental e idempotente: pode ser executada mais de
-- uma vez sem erro, seguindo o mesmo padrão (checagem via
-- INFORMATION_SCHEMA antes de criar) já usado nas migrations anteriores
-- deste projeto (ver migration_fase4.sql).
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase7_leads.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: whatsapp_templates
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    content TEXT NOT NULL COMMENT 'Aceita placeholders {{nome}}, {{interesse}}, {{responsavel}}',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_templates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Templates de mensagem para envio de WhatsApp a partir do perfil do lead';

INSERT IGNORE INTO whatsapp_templates (name, content, active) VALUES
('Primeiro contato',
 'Olá {{nome}}! Aqui é {{responsavel}}, do Titanium CRM. Recebemos seu interesse em {{interesse}} e gostaria de conversar um pouco mais para entender sua necessidade. Podemos falar agora?',
 1),
('Lembrete de documentação',
 'Olá {{nome}}, tudo bem? Aqui é {{responsavel}}. Para darmos andamento à sua proposta de {{interesse}}, ainda precisamos de alguns documentos. Pode me enviar quando possível?',
 1),
('Proposta enviada',
 'Olá {{nome}}! Encaminhei sua proposta referente a {{interesse}}. Qualquer dúvida, estou à disposição. Att, {{responsavel}}.',
 1),
('Follow-up',
 'Oi {{nome}}, tudo bem? Passando para saber se ainda tem interesse em {{interesse}} e se posso ajudar em algo. Abraço, {{responsavel}}.',
 1);

-- ---------------------------------------------------------------------
-- leads: índice idx_leads_next_contact_at
-- ---------------------------------------------------------------------
SET @idx_exists_next_contact := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_next_contact_at'
);
SET @sql_idx_next_contact := IF(@idx_exists_next_contact = 0,
    'ALTER TABLE leads ADD INDEX idx_leads_next_contact_at (next_contact_at)',
    'SELECT 1'
);
PREPARE stmt_idx_next_contact FROM @sql_idx_next_contact;
EXECUTE stmt_idx_next_contact;
DEALLOCATE PREPARE stmt_idx_next_contact;

-- ---------------------------------------------------------------------
-- leads: índice idx_leads_last_contact_at
-- ---------------------------------------------------------------------
SET @idx_exists_last_contact := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_last_contact_at'
);
SET @sql_idx_last_contact := IF(@idx_exists_last_contact = 0,
    'ALTER TABLE leads ADD INDEX idx_leads_last_contact_at (last_contact_at)',
    'SELECT 1'
);
PREPARE stmt_idx_last_contact FROM @sql_idx_last_contact;
EXECUTE stmt_idx_last_contact;
DEALLOCATE PREPARE stmt_idx_last_contact;

-- ---------------------------------------------------------------------
-- lead_history: índice idx_lead_history_type
-- ---------------------------------------------------------------------
SET @idx_exists_history_type := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_history' AND INDEX_NAME = 'idx_lead_history_type'
);
SET @sql_idx_history_type := IF(@idx_exists_history_type = 0,
    'ALTER TABLE lead_history ADD INDEX idx_lead_history_type (type)',
    'SELECT 1'
);
PREPARE stmt_idx_history_type FROM @sql_idx_history_type;
EXECUTE stmt_idx_history_type;
DEALLOCATE PREPARE stmt_idx_history_type;

SET FOREIGN_KEY_CHECKS = 1;
