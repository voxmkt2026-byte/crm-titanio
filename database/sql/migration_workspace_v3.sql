SET NAMES utf8mb4;
DROP PROCEDURE IF EXISTS tc_ws3_add_col;
DELIMITER $$
CREATE PROCEDURE tc_ws3_add_col(IN tbl VARCHAR(64),IN col VARCHAR(64),IN ddl TEXT)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=tbl AND COLUMN_NAME=col) THEN
  SET @q=CONCAT('ALTER TABLE `',tbl,'` ADD COLUMN ',ddl);PREPARE s FROM @q;EXECUTE s;DEALLOCATE PREPARE s;
 END IF;
END$$
DELIMITER ;
CALL tc_ws3_add_col('workspace_pages','assigned_to',"`assigned_to` INT UNSIGNED NULL AFTER `lead_id`");
CALL tc_ws3_add_col('workspace_pages','category',"`category` VARCHAR(80) NULL AFTER `type`");
CALL tc_ws3_add_col('workspace_pages','visibility',"`visibility` ENUM('equipe','privado') NOT NULL DEFAULT 'equipe' AFTER `is_pinned`");
CALL tc_ws3_add_col('automation_flows','description',"`description` TEXT NULL AFTER `name`");
CALL tc_ws3_add_col('automation_flows','is_template',"`is_template` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active`");
DROP PROCEDURE tc_ws3_add_col;

SET @fk_ws_assignee=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='workspace_pages' AND CONSTRAINT_NAME='fk_workspace_assignee');
SET @sql=IF(@fk_ws_assignee=0,'ALTER TABLE workspace_pages ADD KEY idx_workspace_assignee(assigned_to), ADD CONSTRAINT fk_workspace_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL','SELECT 1');
PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;

INSERT INTO automation_flows(name,description,trigger_type,trigger_config,actions_json,active,is_template,created_by)
SELECT 'Recuperar lead parado','Retoma leads sem contato, cria tarefa e avisa a gestão.','lead_stale',JSON_OBJECT('days',10),JSON_ARRAY('Enviar WhatsApp','Criar tarefa','Avisar gestor','Aumentar prioridade'),0,1,id FROM users WHERE role='admin' ORDER BY id LIMIT 1;
