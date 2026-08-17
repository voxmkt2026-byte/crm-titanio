SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP PROCEDURE IF EXISTS tc_ws_add_col;
DELIMITER $$
CREATE PROCEDURE tc_ws_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=tbl AND COLUMN_NAME=col) THEN
   SET @sql=CONCAT('ALTER TABLE `',tbl,'` ADD COLUMN ',ddl); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
 END IF;
END$$
DELIMITER ;
CALL tc_ws_add_col('calendar_events','priority',"`priority` ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media' AFTER `color`");
CALL tc_ws_add_col('calendar_events','guidance',"`guidance` TEXT NULL AFTER `description`");
CALL tc_ws_add_col('whiteboards','visibility',"`visibility` ENUM('privado','equipe') NOT NULL DEFAULT 'equipe' AFTER `board_json`");
DROP PROCEDURE tc_ws_add_col;

CREATE TABLE IF NOT EXISTS workspace_attachments(
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,page_id INT UNSIGNED NOT NULL,original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,size_bytes BIGINT UNSIGNED NOT NULL,uploaded_by INT UNSIGNED NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 KEY idx_workspace_attachment_page(page_id),CONSTRAINT fk_ws_attachment_page FOREIGN KEY(page_id) REFERENCES workspace_pages(id) ON DELETE CASCADE,
 CONSTRAINT fk_ws_attachment_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whiteboard_members(
 board_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,role ENUM('editor','visualizador') NOT NULL DEFAULT 'editor',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(board_id,user_id),CONSTRAINT fk_wb_member_board FOREIGN KEY(board_id) REFERENCES whiteboards(id) ON DELETE CASCADE,
 CONSTRAINT fk_wb_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
