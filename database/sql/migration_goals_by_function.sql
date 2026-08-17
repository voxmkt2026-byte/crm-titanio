-- Metas por função comercial: SDR, vendedor e supervisor.
-- Execute após schema.sql, migration_fase7_agenda.sql e migration_chat.sql.
-- É idempotente e preserva as metas antigas de fechamentos/leads.

SET @db_name = DATABASE();

-- Função comercial é separada do papel de acesso do sistema. Assim, por
-- exemplo, um usuário com papel "consultor" pode atuar como SDR sem ganhar
-- ou perder permissões por isso.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'commercial_function') = 0,
    'ALTER TABLE users ADD COLUMN commercial_function ENUM(''sdr'', ''vendedor'', ''supervisor'') NOT NULL DEFAULT ''vendedor'' COMMENT ''Função usada nas metas, independente do papel de acesso'' AFTER role',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mantém o comportamento mais próximo do que já existia: os supervisores
-- passam a acompanhar vendas de equipe; os demais permanecem vendedores até
-- que a função seja ajustada em Usuários.
UPDATE users
SET commercial_function = 'supervisor'
WHERE role = 'supervisor' AND commercial_function = 'vendedor';

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'user_goals' AND COLUMN_NAME = 'target_sales_value') = 0,
    'ALTER TABLE user_goals ADD COLUMN target_sales_value DECIMAL(14,2) DEFAULT NULL COMMENT ''Meta de valor vendido no mês'' AFTER target_new_leads',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Valor efetivo e data de fechamento são necessários para que a meta de
-- vendas em reais não use o valor desejado (que é apenas uma intenção).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'closed_value') = 0,
    'ALTER TABLE leads ADD COLUMN closed_value DECIMAL(14,2) DEFAULT NULL COMMENT ''Valor efetivo da venda, informado ao fechar'' AFTER desired_value',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'closed_at') = 0,
    'ALTER TABLE leads ADD COLUMN closed_at DATETIME DEFAULT NULL COMMENT ''Data/hora em que o lead foi fechado'' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'closed_by') = 0,
    'ALTER TABLE leads ADD COLUMN closed_by INT UNSIGNED DEFAULT NULL COMMENT ''Responsável no momento do fechamento'' AFTER closed_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_closed_at') = 0,
    'ALTER TABLE leads ADD KEY idx_leads_closed_at (closed_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_closed_by') = 0,
    'ALTER TABLE leads ADD KEY idx_leads_closed_by (closed_by)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db_name AND CONSTRAINT_NAME = 'fk_leads_closed_by') = 0,
    'ALTER TABLE leads ADD CONSTRAINT fk_leads_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Para históricos sem data própria, preserva a referência que a tela antiga
-- de metas já usava: a última atualização do lead. O valor continua nulo até
-- ser informado, para não confundir intenção de compra com venda efetiva.
UPDATE leads
SET closed_at = COALESCE(closed_at, updated_at),
    closed_by = COALESCE(closed_by, assigned_to)
WHERE status = 'fechado';
