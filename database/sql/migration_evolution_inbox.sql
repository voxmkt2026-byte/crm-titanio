SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS evolution_conversation_links (
 conversation_id VARCHAR(80) NOT NULL,
 lead_id INT UNSIGNED NULL,
 assigned_to INT UNSIGNED NULL,
 contact_id VARCHAR(80) NULL,
 contact_name VARCHAR(180) NULL,
 contact_phone VARCHAR(30) NULL,
 last_synced_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(conversation_id), KEY idx_evo_link_lead(lead_id), KEY idx_evo_link_assignee(assigned_to), KEY idx_evo_link_phone(contact_phone),
 CONSTRAINT fk_evo_link_lead FOREIGN KEY(lead_id) REFERENCES leads(id) ON DELETE SET NULL,
 CONSTRAINT fk_evo_link_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evolution_agent_mappings (
 user_id INT UNSIGNED NOT NULL,
 external_agent_id VARCHAR(80) NULL,
 external_team_id VARCHAR(80) NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id),
 CONSTRAINT fk_evo_agent_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(slug,label) VALUES
('evolution.view','Visualizar atendimentos WhatsApp Evolution'),
('evolution.manage','Gerenciar e transferir atendimentos Evolution');
INSERT IGNORE INTO role_permissions(role,permission_id) SELECT 'admin',id FROM permissions WHERE slug IN ('evolution.view','evolution.manage');
INSERT IGNORE INTO role_permissions(role,permission_id) SELECT 'supervisor',id FROM permissions WHERE slug IN ('evolution.view','evolution.manage');
INSERT IGNORE INTO role_permissions(role,permission_id) SELECT 'consultor',id FROM permissions WHERE slug='evolution.view';
