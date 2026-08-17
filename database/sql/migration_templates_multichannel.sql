-- Templates multicanal: e-mail e classificação dos modelos de WhatsApp.
-- Os dados existentes são preservados; o arquivo pode ser executado mais de uma vez.

CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'geral',
    subject VARCHAR(255) NOT NULL,
    content TEXT NOT NULL COMMENT 'Aceita placeholders como {{nome}}, {{interesse}} e {{responsavel}}',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_templates_name (name),
    KEY idx_email_templates_active_category (active, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Modelos de e-mail reutilizáveis para atendimento e leads';

ALTER TABLE whatsapp_templates
    ADD COLUMN IF NOT EXISTS category VARCHAR(40) NOT NULL DEFAULT 'geral' AFTER name;

-- O histórico principal usa ENUM nas instalações já existentes. Incluímos
-- explicitamente e-mail para que o envio pelo inbox seja auditável no lead.
ALTER TABLE lead_history
    MODIFY COLUMN type ENUM('criacao','contato','whatsapp','email','ligacao','status','observacao','responsavel','agendamento','fechamento','dado_alterado') NOT NULL;

UPDATE whatsapp_templates
SET category = CASE
    WHEN name LIKE '%Primeiro%' THEN 'primeiro_contato'
    WHEN name LIKE '%document%' THEN 'documentacao'
    WHEN name LIKE '%Proposta%' THEN 'proposta'
    WHEN name LIKE '%Follow%' THEN 'follow_up'
    ELSE category
END
WHERE category = '' OR category = 'geral';

INSERT IGNORE INTO email_templates (name, category, subject, content, active) VALUES
('Primeiro contato', 'primeiro_contato', 'Olá {{nome}}, vamos conversar sobre {{interesse}}?',
 'Olá {{nome}},\n\nAqui é {{responsavel}}. Recebemos seu interesse em {{interesse}} e quero entender melhor o que você procura para orientar os próximos passos.\n\nPosso falar com você?', 1),
('Documentação pendente', 'documentacao', 'Documentos para avançarmos sua proposta',
 'Olá {{nome}},\n\nPara avançarmos com sua proposta de {{interesse}}, ainda precisamos de alguns documentos. Quando puder, responda este e-mail que explico a lista e ajudo no que for necessário.\n\nAtenciosamente,\n{{responsavel}}', 1),
('Proposta enviada', 'proposta', 'Sua proposta para {{interesse}}',
 'Olá {{nome}},\n\nEncaminhei a proposta referente a {{interesse}}. Revise com calma e, se surgir qualquer dúvida, responda por aqui para que possamos conversar.\n\nAtenciosamente,\n{{responsavel}}', 1),
('Follow-up cordial', 'follow_up', 'Posso ajudar com sua decisão sobre {{interesse}}?',
 'Olá {{nome}},\n\nQuero saber se ainda faz sentido conversarmos sobre {{interesse}}. Estou à disposição para esclarecer dúvidas e ajustar os próximos passos.\n\nAtenciosamente,\n{{responsavel}}', 1);
