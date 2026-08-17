-- =====================================================================
-- Titanium CRM - Migration Fase 4
-- Execute este arquivo SOMENTE APÓS já ter importado, nesta ordem:
--   1) database/sql/schema.sql
--   2) database/sql/seed.sql
--   3) database/sql/migration_fase2.sql
--   4) database/sql/migration_fase3.sql
--
-- O que esta migration cria/altera (produtividade da equipe comercial):
--   - leads: nova coluna `lead_code` VARCHAR(20) UNIQUE, formato
--            LEAD-YYYYMMDD-NNNN (código único legível do lead, gerado em
--            app/models/Lead.php::generateLeadCode()).
--   - leads: ENUM `source` expandido com cadastro_manual / whatsapp /
--            importacao_csv / api / webhook, mantendo os valores antigos.
--   - Tabela `imports`: um registro por importação de CSV realizada.
--   - Tabela `import_errors`: erros/avisos linha a linha de uma importação.
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase4.sql
--
-- Esta migration é incremental e idempotente onde o MySQL permite
-- (CREATE TABLE IF NOT EXISTS). A coluna nova em `leads` usa um
-- procedimento auxiliar para só ser adicionada se ainda não existir,
-- evitando erro em quem rodar este arquivo mais de uma vez. O ALTER do
-- ENUM `source` (MODIFY COLUMN) é seguro de rodar mais de uma vez também.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- leads: coluna lead_code (código único do lead, ex: LEAD-20260804-0001)
-- ---------------------------------------------------------------------
SET @col_exists_lead_code := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'lead_code'
);
SET @sql_lead_code := IF(@col_exists_lead_code = 0,
    'ALTER TABLE leads ADD COLUMN lead_code VARCHAR(20) DEFAULT NULL AFTER id, ADD UNIQUE KEY uq_leads_lead_code (lead_code)',
    'SELECT 1'
);
PREPARE stmt_lead_code FROM @sql_lead_code;
EXECUTE stmt_lead_code;
DEALLOCATE PREPARE stmt_lead_code;

-- ---------------------------------------------------------------------
-- leads: expande o ENUM `source` com as novas origens de cadastro
-- (mantém todos os valores já existentes desde o schema.sql original).
-- ---------------------------------------------------------------------
ALTER TABLE leads MODIFY COLUMN source ENUM(
    'facebook','instagram','google','indicacao','site','landing_page','organico',
    'cadastro_manual','whatsapp','importacao_csv','api','webhook','outros'
) DEFAULT NULL;

-- ---------------------------------------------------------------------
-- Tabela: imports
-- Um registro por arquivo CSV importado (ver ImportController::processar).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS imports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    filename VARCHAR(255) DEFAULT NULL COMMENT 'Nome original do arquivo enviado',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('processando','concluido','concluido_com_erros','falhou') NOT NULL DEFAULT 'processando',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_imports_user_id (user_id),
    KEY idx_imports_created_at (created_at),
    CONSTRAINT fk_imports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de importações de leads via CSV';

-- ---------------------------------------------------------------------
-- Tabela: import_errors
-- Erros/avisos linha a linha de uma importação específica.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_errors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id INT UNSIGNED NOT NULL,
    row_num INT UNSIGNED NOT NULL,
    raw_data TEXT DEFAULT NULL COMMENT 'Linha original do CSV (JSON) para depuração',
    error_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_errors_import_id (import_id),
    CONSTRAINT fk_import_errors_import FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Erros/avisos linha a linha de cada importação de CSV';

SET FOREIGN_KEY_CHECKS = 1;
