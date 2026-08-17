-- Chat v2: uma sala privada por lead, com participantes por convite.
-- Rode este arquivo APÓS database/sql/migration_chat.sql em instalações
-- existentes. Em uma instalação nova, migration_chat.sql já contém as colunas.

SET @db_name = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'lead_id') = 0,
    'ALTER TABLE chat_rooms ADD COLUMN lead_id INT UNSIGNED DEFAULT NULL COMMENT ''Lead associado quando a sala é uma colaboração privada sobre um atendimento'' AFTER department_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND INDEX_NAME = 'uq_chat_rooms_lead') = 0,
    'ALTER TABLE chat_rooms ADD UNIQUE KEY uq_chat_rooms_lead (lead_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db_name AND CONSTRAINT_NAME = 'fk_chat_rooms_lead') = 0,
    'ALTER TABLE chat_rooms ADD CONSTRAINT fk_chat_rooms_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
