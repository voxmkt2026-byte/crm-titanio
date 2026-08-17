SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS calendar_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, description TEXT NULL, guidance TEXT NULL,
 start_at DATETIME NOT NULL, end_at DATETIME NULL, all_day TINYINT(1) NOT NULL DEFAULT 0,
 color VARCHAR(20) DEFAULT '#3b82f6', priority ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
 event_type ENUM('reuniao','ligacao','visita','follow_up','tarefa','outro') NOT NULL DEFAULT 'reuniao',
 lead_id INT UNSIGNED NULL, person_name VARCHAR(180) NULL, task_id INT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL, assigned_to INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_calendar_dates(start_at,end_at), KEY idx_calendar_lead(lead_id),
 CONSTRAINT fk_calendar_lead FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE SET NULL,
 CONSTRAINT fk_calendar_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_calendar_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_pages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id INT UNSIGNED NULL, type ENUM('documento','wiki') NOT NULL,
 title VARCHAR(180) NOT NULL, content LONGTEXT NULL, tags VARCHAR(500) NULL, lead_id INT UNSIGNED NULL,
 is_pinned TINYINT(1) DEFAULT 0, created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_workspace_type(type), KEY idx_workspace_lead(lead_id),
 CONSTRAINT fk_workspace_parent FOREIGN KEY(parent_id) REFERENCES workspace_pages(id) ON DELETE SET NULL,
 CONSTRAINT fk_workspace_lead FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE SET NULL,
 CONSTRAINT fk_workspace_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whiteboards (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, lead_id INT UNSIGNED NULL,
 board_json LONGTEXT NOT NULL, created_by INT UNSIGNED NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_whiteboard_lead(lead_id), CONSTRAINT fk_whiteboard_lead FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE SET NULL,
 CONSTRAINT fk_whiteboard_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_flows (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(180) NOT NULL, trigger_type VARCHAR(60) NOT NULL,
 trigger_config JSON NULL, actions_json JSON NOT NULL, active TINYINT(1) DEFAULT 1, last_run_at DATETIME NULL,
 created_by INT UNSIGNED NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_automation_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS automation_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, flow_id INT UNSIGNED NOT NULL, lead_id INT UNSIGNED NOT NULL,
 status ENUM('sucesso','parcial','erro') NOT NULL, details TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_flow_lead_day(flow_id,lead_id,created_at), KEY idx_automation_runs_created(created_at),
 CONSTRAINT fk_automation_run_flow FOREIGN KEY(flow_id) REFERENCES automation_flows(id) ON DELETE CASCADE,
 CONSTRAINT fk_automation_run_lead FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_workspace_preferences (
 user_id INT UNSIGNED PRIMARY KEY, preferences_json LONGTEXT NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_workspace_preferences_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notification_events (
 event_key VARCHAR(190) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 KEY idx_notification_events_user(user_id), CONSTRAINT fk_notification_events_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(slug,label) VALUES
('workspace.manage','Gerenciar documentos, wiki e whiteboards'),('automations.manage','Gerenciar fluxos automatizados');
INSERT IGNORE INTO role_permissions(role,permission_id) SELECT 'admin',id FROM permissions WHERE slug IN ('workspace.manage','automations.manage');
INSERT IGNORE INTO role_permissions(role,permission_id) SELECT 'supervisor',id FROM permissions WHERE slug IN ('workspace.manage','automations.manage');
SET FOREIGN_KEY_CHECKS = 1;
