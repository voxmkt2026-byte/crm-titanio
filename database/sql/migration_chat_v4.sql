-- Chat v4: gestão de grupos personalizados.
-- Execute após migration_chat.sql, migration_chat_v2.sql e migration_chat_v3.sql.
-- Acrescenta descrição e foto opcional; grupos existentes permanecem intactos.

SET NAMES utf8mb4;

SET @db_name = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'description') = 0,
    'ALTER TABLE chat_rooms ADD COLUMN description TEXT NULL AFTER name',
    'SELECT 1'
);
PREPARE tc_chat_v4_description FROM @sql;
EXECUTE tc_chat_v4_description;
DEALLOCATE PREPARE tc_chat_v4_description;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'image_filename') = 0,
    'ALTER TABLE chat_rooms ADD COLUMN image_filename VARCHAR(255) NULL AFTER description',
    'SELECT 1'
);
PREPARE tc_chat_v4_image FROM @sql;
EXECUTE tc_chat_v4_image;
DEALLOCATE PREPARE tc_chat_v4_image;
