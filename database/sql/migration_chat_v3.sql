-- Chat v3: conversa privada por tarefa.
-- Execute este arquivo APÓS migration_chat.sql, migration_chat_v2.sql e
-- migration_tasks.sql. Ele pode ser executado novamente sem duplicar colunas,
-- índice ou chave estrangeira.

SET @db_name = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'task_id') = 0,
    'ALTER TABLE chat_rooms ADD COLUMN task_id INT UNSIGNED DEFAULT NULL COMMENT ''Tarefa associada quando a sala é uma colaboração privada'' AFTER lead_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND INDEX_NAME = 'uq_chat_rooms_task') = 0,
    'ALTER TABLE chat_rooms ADD UNIQUE KEY uq_chat_rooms_task (task_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @db_name AND CONSTRAINT_NAME = 'fk_chat_rooms_task') = 0,
    'ALTER TABLE chat_rooms ADD CONSTRAINT fk_chat_rooms_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
