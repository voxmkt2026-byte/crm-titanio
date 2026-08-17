-- =====================================================================
-- Titanium CRM - Migration: Módulo de Tarefas (delegação de demandas)
-- Esta migration é INDEPENDENTE das migrations fase2/fase3/fase4/fase5:
-- pode ser executada a qualquer momento DEPOIS delas (precisa apenas das
-- tabelas `users` e `leads`, criadas em database/sql/schema.sql, e da
-- tabela `permissions`/`role_permissions`, criada em migration_fase3.sql).
--
-- O que esta migration cria:
--   - Tabela tasks           (demanda de trabalho, pode ou não estar
--                              vinculada a um lead)
--   - Tabela task_comments   (observações/atualizações ao longo da execução)
--   - Tabela task_history    (timeline de auditoria da tarefa, mesmo
--                              padrão já usado em lead_history)
--   - Tabela task_watchers   (usuários que acompanham a tarefa mesmo sem
--                              serem o responsável; quem cria é watcher
--                              automático)
--   - Novas permissões: tasks.view_all / tasks.create / tasks.manage
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_tasks.sql
--
-- Idempotente: usa CREATE TABLE IF NOT EXISTS / INSERT IGNORE, pode ser
-- executada mais de uma vez sem erro.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: tasks
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL COMMENT 'Observações/detalhes da demanda',
    creator_id INT UNSIGNED NOT NULL COMMENT 'Quem criou/delegou a tarefa',
    assigned_to INT UNSIGNED DEFAULT NULL COMMENT 'Responsável atual pela tarefa (nullable até ser atribuída)',
    lead_id INT UNSIGNED DEFAULT NULL COMMENT 'Lead vinculado, opcional',
    priority ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
    status ENUM('pendente','em_andamento','aguardando','concluida','cancelada') NOT NULL DEFAULT 'pendente',
    due_at DATETIME DEFAULT NULL COMMENT 'Prazo da tarefa',
    sla_hours INT UNSIGNED DEFAULT NULL COMMENT 'Prazo em horas a partir da criação, usado para calcular o SLA da tarefa',
    started_at DATETIME DEFAULT NULL COMMENT 'Quando o responsável começou a trabalhar (status virou em_andamento)',
    completed_at DATETIME DEFAULT NULL COMMENT 'Quando a tarefa foi concluída',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_assigned_to (assigned_to),
    KEY idx_tasks_creator_id (creator_id),
    KEY idx_tasks_status (status),
    KEY idx_tasks_priority (priority),
    KEY idx_tasks_due_at (due_at),
    KEY idx_tasks_lead_id (lead_id),
    CONSTRAINT fk_tasks_creator FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tasks_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tasks_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tarefas/demandas de trabalho internas, delegadas entre colaboradores';

-- ---------------------------------------------------------------------
-- Tabela: task_comments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_comments_task_id (task_id),
    CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comentários/observações ao longo da execução de uma tarefa';

-- ---------------------------------------------------------------------
-- Tabela: task_history
-- Timeline de auditoria da tarefa (mesmo padrão de lead_history).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    type ENUM('criacao','atribuicao','status','prioridade','prazo','comentario','conclusao') NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_history_task_id (task_id),
    KEY idx_task_history_created_at (created_at),
    CONSTRAINT fk_task_history_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Timeline de auditoria de cada tarefa';

-- ---------------------------------------------------------------------
-- Tabela: task_watchers
-- Usuários que acompanham a tarefa (recebem notificação de qualquer
-- atualização) mesmo não sendo o responsável atual. Quem cria a tarefa
-- é adicionado automaticamente como watcher (ver TaskController::store).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_watchers (
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    KEY idx_task_watchers_user_id (user_id),
    CONSTRAINT fk_task_watchers_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_watchers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuários que acompanham atualizações de uma tarefa sem serem o responsável';

-- ---------------------------------------------------------------------
-- Permissões do módulo de Tarefas (tabelas permissions/role_permissions
-- já devem existir, criadas em migration_fase3.sql). Se ainda não
-- existirem, os INSERTs abaixo simplesmente falham silenciosamente pois
-- a permissão não é obrigatória para o módulo funcionar (Auth::can() já
-- tem fallback seguro - ver app/core/Auth.php).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permissions (slug, label) VALUES
('tasks.view_all', 'Visualizar todas as tarefas do sistema'),
('tasks.create',   'Criar/delegar tarefas'),
('tasks.manage',   'Editar, excluir e reatribuir qualquer tarefa');

-- admin: todas as permissões de tarefas
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE slug IN ('tasks.view_all', 'tasks.create', 'tasks.manage');

-- supervisor: todas as permissões de tarefas
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'supervisor', id FROM permissions WHERE slug IN ('tasks.view_all', 'tasks.create', 'tasks.manage');

-- consultor: pode criar/delegar tarefas, mas só vê/gerencia as próprias
-- (criador, responsável ou watcher) - regra aplicada em código (TaskController)
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'consultor', id FROM permissions WHERE slug IN ('tasks.create');

SET FOREIGN_KEY_CHECKS = 1;
