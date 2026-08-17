SET NAMES utf8mb4;

-- Complemento da integração Evolution API: foto de perfil do contato
-- (para o inbox estilo Zap Responder/Chatwoot mostrar avatar de verdade)
-- e prioridade de mensagem, usados na tela de Atendimento WhatsApp.

ALTER TABLE evolution_conversation_links
    ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) NULL AFTER contact_phone;
