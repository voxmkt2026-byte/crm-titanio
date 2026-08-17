-- =====================================================================
-- Titanium CRM - Migration Fase 3
-- Execute este arquivo SOMENTE APÓS já ter importado, nesta ordem:
--   1) database/sql/schema.sql
--   2) database/sql/seed.sql
--   3) database/sql/migration_fase2.sql
--
-- O que esta migration cria/altera:
--   - users: colunas password_reset_token / password_reset_expires
--            (fluxo de "Esqueci minha senha")
--   - Tabela permissions (catálogo de permissões granulares)
--   - Tabela role_permissions (permissões atribuídas por papel)
--   - Seed de permissões padrão para admin / supervisor / consultor
--   - Tabela notifications (sino de notificações no topbar, via polling)
--   - Novas chaves em settings (WhatsApp Cloud API, remetente/criptografia
--     SMTP e token do webhook público de captação de leads)
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase3.sql
--
-- Esta migration é incremental e idempotente onde o MySQL permite
-- (CREATE TABLE IF NOT EXISTS / INSERT IGNORE). As duas colunas novas em
-- `users` usam um procedimento auxiliar para só serem adicionadas se ainda
-- não existirem, evitando erro em quem rodar este arquivo mais de uma vez.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- users: colunas para o fluxo de "Esqueci minha senha"
-- ---------------------------------------------------------------------
SET @col_exists_1 := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_reset_token'
);
SET @sql_1 := IF(@col_exists_1 = 0,
    'ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL AFTER password, ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token, ADD KEY idx_users_reset_token (password_reset_token)',
    'SELECT 1'
);
PREPARE stmt_1 FROM @sql_1;
EXECUTE stmt_1;
DEALLOCATE PREPARE stmt_1;

-- ---------------------------------------------------------------------
-- Tabela: permissions
-- Catálogo de permissões granulares (uma linha por ação do sistema).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL COMMENT 'Identificador único usado por Auth::can()',
    label VARCHAR(150) NOT NULL COMMENT 'Descrição amigável exibida em telas administrativas',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de permissões granulares do sistema';

-- ---------------------------------------------------------------------
-- Tabela: role_permissions
-- Relação N:N entre papel (role) e permissão.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role ENUM('admin', 'supervisor', 'consultor') NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_role_permission (role, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Permissões atribuídas a cada papel (admin/supervisor/consultor)';

-- ---------------------------------------------------------------------
-- Seed: catálogo de permissões
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permissions (slug, label) VALUES
('leads.view',       'Visualizar leads'),
('leads.create',     'Cadastrar leads'),
('leads.edit',       'Editar leads'),
('leads.delete',     'Excluir leads'),
('leads.export',     'Exportar relatórios de leads (CSV/Excel/PDF)'),
('pipeline.manage',  'Mover leads no pipeline (Kanban)'),
('reports.view',     'Acessar tela de Relatórios'),
('users.manage',     'Gerenciar usuários do sistema'),
('settings.manage',  'Gerenciar configurações do sistema');

-- ---------------------------------------------------------------------
-- Seed: permissões por papel
--   admin       -> todas as permissões
--   supervisor  -> todas, exceto users.manage e settings.manage
--   consultor   -> leads.view / leads.create / leads.edit / pipeline.manage
--                  (a regra de só poder mexer nos PRÓPRIOS leads é aplicada
--                  em código, não neste catálogo, que é por ação/tela)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions;

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'supervisor', id FROM permissions
WHERE slug NOT IN ('users.manage', 'settings.manage');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'consultor', id FROM permissions
WHERE slug IN ('leads.view', 'leads.create', 'leads.edit', 'pipeline.manage');

-- ---------------------------------------------------------------------
-- Tabela: notifications
-- Notificações do sino no topbar (lidas via polling AJAX, sem websockets).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL COMMENT 'Caminho relativo (ex: leads/123) para onde o clique deve levar',
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_unread (user_id, read_at),
    KEY idx_notifications_created_at (created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notificações internas exibidas no sino do topbar';

-- ---------------------------------------------------------------------
-- Novas chaves em settings (tabela chave/valor já existente na Fase 1)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('smtp_from_email', ''),
('smtp_encryption', 'tls'),
('whatsapp_token', ''),
('whatsapp_phone_id', ''),
('webhook_token', '');

SET FOREIGN_KEY_CHECKS = 1;
