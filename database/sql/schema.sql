-- =====================================================================
-- Titanium CRM - Schema do banco de dados (MySQL 8.0+)
-- Empresa: Titanium Consultoria (Cartas Contempladas, Consórcios e Crédito)
--
-- Como importar:
--   1) Via phpMyAdmin (Hostinger): hPanel > Bancos de Dados > phpMyAdmin
--      > selecione o banco > aba "Importar" > escolha este arquivo > Executar.
--   2) Via linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/schema.sql
--
-- Depois de importar este arquivo, importe também database/sql/seed.sql
-- para ter dados fictícios de teste e o usuário admin padrão.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: users
-- Usuários do sistema (equipe interna: admins, supervisores, consultores).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt (password_hash)',
    role ENUM('admin', 'supervisor', 'consultor') NOT NULL DEFAULT 'consultor',
    commercial_function ENUM('sdr', 'vendedor', 'supervisor') NOT NULL DEFAULT 'vendedor'
        COMMENT 'Função usada nas metas, independente do papel de acesso',
    avatar VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuários internos do CRM (equipe Titanium Consultoria)';

-- ---------------------------------------------------------------------
-- Tabela: loss_reasons
-- Motivos de perda de um lead (catálogo fixo, editável pelo admin).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loss_reasons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de motivos de perda de lead';

-- ---------------------------------------------------------------------
-- Tabela: leads
-- Tabela principal: leads captados via Meta Ads / Google Ads / outros.
-- Nenhum campo é obrigatório (NOT NULL) além de id e timestamps.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Dados pessoais
    name VARCHAR(150) DEFAULT NULL,
    ddd VARCHAR(3) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    whatsapp VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    cpf VARCHAR(14) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state CHAR(2) DEFAULT NULL COMMENT 'UF',
    zipcode VARCHAR(10) DEFAULT NULL,

    -- Origem / rastreamento de campanha (Meta Ads / Google Ads)
    source ENUM('facebook','instagram','google','indicacao','site','landing_page','organico','outros') DEFAULT NULL,
    campaign VARCHAR(150) DEFAULT NULL,
    adset VARCHAR(150) DEFAULT NULL,
    ad VARCHAR(150) DEFAULT NULL,
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    utm_content VARCHAR(150) DEFAULT NULL,
    utm_term VARCHAR(150) DEFAULT NULL,

    -- Interesse financeiro
    interest ENUM('imovel','veiculo','caminhao','moto','maquinario','agronegocio','construcao','capital_giro','investimento','quitacao','outros') DEFAULT NULL,
    desired_value DECIMAL(14,2) DEFAULT NULL,
    has_down_payment TINYINT(1) DEFAULT NULL,
    down_payment_value DECIMAL(14,2) DEFAULT NULL,
    income_range VARCHAR(50) DEFAULT NULL,
    profession VARCHAR(100) DEFAULT NULL,
    company VARCHAR(150) DEFAULT NULL,

    -- Gestão comercial
    status ENUM(
        'novo','primeiro_contato','tentando_contato','em_negociacao','documentacao',
        'aguardando_cliente','aguardando_aprovacao','aprovado','fechado','perdido',
        'sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado'
    ) NOT NULL DEFAULT 'novo',
    closed_value DECIMAL(14,2) DEFAULT NULL COMMENT 'Valor efetivo da venda, informado ao fechar',
    closed_at DATETIME DEFAULT NULL COMMENT 'Data/hora em que o lead foi fechado',
    closed_by INT UNSIGNED DEFAULT NULL COMMENT 'Responsável no momento do fechamento',
    last_contact_at DATETIME DEFAULT NULL,
    next_contact_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    internal_notes TEXT DEFAULT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,
    lead_score TINYINT UNSIGNED DEFAULT 0 COMMENT '0 a 100',
    temperature ENUM('frio','morno','quente','muito_quente') DEFAULT NULL,
    priority ENUM('baixa','media','alta','urgente') DEFAULT NULL,

    -- Metadados técnicos de captura
    ip_address VARCHAR(45) DEFAULT NULL,
    device VARCHAR(50) DEFAULT NULL,
    browser VARCHAR(255) DEFAULT NULL,

    loss_reason_id INT UNSIGNED DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_leads_state (state),
    KEY idx_leads_city (city),
    KEY idx_leads_ddd (ddd),
    KEY idx_leads_source (source),
    KEY idx_leads_interest (interest),
    KEY idx_leads_status (status),
    KEY idx_leads_assigned_to (assigned_to),
    KEY idx_leads_created_at (created_at),
    KEY idx_leads_phone (phone),
    KEY idx_leads_whatsapp (whatsapp),
    KEY idx_leads_cpf (cpf),
    KEY idx_leads_email (email),
    KEY idx_leads_status_assigned (status, assigned_to),
    KEY idx_leads_closed_at (closed_at),
    KEY idx_leads_closed_by (closed_by),

    CONSTRAINT fk_leads_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_leads_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_leads_loss_reason FOREIGN KEY (loss_reason_id) REFERENCES loss_reasons (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Leads captados via Meta Ads, Google Ads e outras origens';

-- ---------------------------------------------------------------------
-- Tabela: lead_history
-- Timeline / histórico de eventos de cada lead.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    type ENUM('criacao','contato','whatsapp','ligacao','status','observacao','responsavel','agendamento','fechamento','dado_alterado') NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lead_history_lead_id (lead_id),
    KEY idx_lead_history_created_at (created_at),
    CONSTRAINT fk_lead_history_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lead_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Timeline de eventos/histórico de cada lead';

-- ---------------------------------------------------------------------
-- Tabela: tags
-- Etiquetas livres que podem ser associadas a leads.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(7) DEFAULT '#3b82f6' COMMENT 'Cor em hexadecimal, ex: #3b82f6',
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Etiquetas (tags) livres para classificar leads';

-- ---------------------------------------------------------------------
-- Tabela: lead_tags (pivot N:N entre leads e tags)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_tags (
    lead_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (lead_id, tag_id),
    KEY idx_lead_tags_tag_id (tag_id),
    CONSTRAINT fk_lead_tags_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lead_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relação N:N entre leads e tags';

-- ---------------------------------------------------------------------
-- Tabela: pipeline_stages
-- Colunas fixas do Kanban de pipeline comercial.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pipeline_stages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    order_position INT UNSIGNED NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT '#3b82f6',
    PRIMARY KEY (id),
    UNIQUE KEY uq_pipeline_stages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Colunas do Kanban de pipeline comercial';

-- ---------------------------------------------------------------------
-- Tabela: settings
-- Configurações gerais do sistema em formato chave/valor.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurações gerais do sistema (chave/valor)';

-- ---------------------------------------------------------------------
-- Tabela: activity_log
-- Log de auditoria geral do sistema (ações administrativas/sensíveis).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(150) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_log_user_id (user_id),
    KEY idx_activity_log_created_at (created_at),
    CONSTRAINT fk_activity_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de auditoria/atividades gerais do sistema';

SET FOREIGN_KEY_CHECKS = 1;
