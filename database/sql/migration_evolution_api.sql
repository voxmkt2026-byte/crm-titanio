SET NAMES utf8mb4;

-- Corrige a integração de Atendimento WhatsApp para a Evolution API real
-- (gateway self-hosted, autenticação por header "apikey", endpoints por
-- instância). A Evolution API não tem conceito de "conversa"/ticket, então
-- o nosso próprio CRM passa a ser o dono dessa informação: as mensagens
-- chegam via webhook (ver EvolutionWebhookController) e ficam guardadas
-- aqui, e a lista de "conversas" é derivada de evolution_conversation_links.

ALTER TABLE evolution_conversation_links
    ADD COLUMN IF NOT EXISTS labels TEXT NULL AFTER contact_phone,
    ADD COLUMN IF NOT EXISTS last_message TEXT NULL AFTER labels,
    ADD COLUMN IF NOT EXISTS last_message_at DATETIME NULL AFTER last_message,
    ADD COLUMN IF NOT EXISTS unread_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_message_at;

CREATE TABLE IF NOT EXISTS evolution_messages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 conversation_id VARCHAR(80) NOT NULL COMMENT 'remoteJid do WhatsApp, ex: 5511999999999@s.whatsapp.net',
 wa_message_id VARCHAR(150) NULL,
 from_me TINYINT(1) NOT NULL DEFAULT 0,
 is_private TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'nota interna, não enviada ao cliente',
 content MEDIUMTEXT NULL,
 message_type VARCHAR(40) NULL,
 sender_name VARCHAR(180) NULL,
 user_id INT UNSIGNED NULL COMMENT 'colaborador que enviou (quando outgoing pelo nosso painel)',
 wa_timestamp DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_evo_msg_conversation (conversation_id, id),
 KEY idx_evo_msg_wa_id (wa_message_id),
 CONSTRAINT fk_evo_msg_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
