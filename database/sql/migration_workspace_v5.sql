-- Calendário: tipo de evento e pessoa externa vinculada.
-- Execute após migration_workspace.sql até migration_workspace_v4.sql.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS tc_ws5_add_col;
DELIMITER $$
CREATE PROCEDURE tc_ws5_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tc_ws5_add_col('calendar_events', 'event_type', "`event_type` ENUM('reuniao','ligacao','visita','follow_up','tarefa','outro') NOT NULL DEFAULT 'reuniao' AFTER `priority`");
CALL tc_ws5_add_col('calendar_events', 'person_name', "`person_name` VARCHAR(180) NULL AFTER `lead_id`");

DROP PROCEDURE tc_ws5_add_col;
