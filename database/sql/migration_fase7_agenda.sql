-- =====================================================================
-- Titanium CRM - Migration Fase 7 (Agenda/Produtividade)
-- Esta migration é INDEPENDENTE de qualquer outra migration da Fase 7
-- que porventura esteja sendo aplicada em paralelo (ex: migration_fase7_leads.sql).
-- Precisa apenas das tabelas `users` e `leads` (database/sql/schema.sql) e,
-- opcionalmente, de `permissions`/`role_permissions` (migration_fase3.sql).
--
-- O que esta migration cria:
--   - Tabela user_goals: metas mensais por vendedor (fechamentos e, opcio-
--     nalmente, novos leads trabalhados), usada pela tela "Meu Dia" e por
--     Configurações > Metas (ver app/controllers/GoalController.php).
--   - Nova permissão: goals.manage (definir metas de qualquer vendedor).
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase7_agenda.sql
--
-- Idempotente: usa CREATE TABLE IF NOT EXISTS / INSERT IGNORE, pode ser
-- executada mais de uma vez sem erro.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: user_goals
-- Meta mensal de cada vendedor: quantidade de leads que devem ser
-- fechados (target_closed_deals) e, opcionalmente, quantidade de novos
-- leads que devem ser trabalhados (target_new_leads) no mês/ano.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_goals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL COMMENT '1 a 12',
    target_closed_deals INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Meta de leads fechados no mês',
    target_new_leads INT UNSIGNED DEFAULT NULL COMMENT 'Meta opcional de novos leads trabalhados no mês',
    target_sales_value DECIMAL(14,2) DEFAULT NULL COMMENT 'Meta opcional de valor vendido no mês',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_goals_user_month (user_id, year, month),
    KEY idx_user_goals_year_month (year, month),
    CONSTRAINT fk_user_goals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Metas pessoais mensais por vendedor (fechamentos e novos leads trabalhados)';

-- ---------------------------------------------------------------------
-- Permissão: goals.manage (definir/editar metas de qualquer vendedor).
-- Se a tabela permissions ainda não existir (Fase 3 não aplicada), os
-- INSERTs abaixo falham silenciosamente - Auth::can() tem fallback
-- seguro (apenas admin) nesse caso, ver app/core/Auth.php.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permissions (slug, label) VALUES
('goals.manage', 'Definir metas mensais de vendedores');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE slug = 'goals.manage';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'supervisor', id FROM permissions WHERE slug = 'goals.manage';

SET FOREIGN_KEY_CHECKS = 1;
