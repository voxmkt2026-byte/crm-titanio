-- Titanium CRM · Automações v2
-- Execute depois de migration_workspace.sql e migration_workspace_v3.sql.
-- Cria índices de execução e disponibiliza modelos pausados, sem alterar
-- fluxos existentes nem disparar qualquer ação automaticamente.

SET NAMES utf8mb4;

SET @automation_flow_index := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_flows'
      AND INDEX_NAME = 'idx_automation_flow_schedule'
);
SET @automation_flow_sql := IF(@automation_flow_index = 0,
    'ALTER TABLE automation_flows ADD INDEX idx_automation_flow_schedule (active, trigger_type, last_run_at)',
    'SELECT 1'
);
PREPARE tc_automation_index FROM @automation_flow_sql;
EXECUTE tc_automation_index;
DEALLOCATE PREPARE tc_automation_index;

SET @automation_run_index := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_runs'
      AND INDEX_NAME = 'idx_automation_runs_flow_lead_created'
);
SET @automation_run_sql := IF(@automation_run_index = 0,
    'ALTER TABLE automation_runs ADD INDEX idx_automation_runs_flow_lead_created (flow_id, lead_id, created_at)',
    'SELECT 1'
);
PREPARE tc_automation_run_index FROM @automation_run_sql;
EXECUTE tc_automation_run_index;
DEALLOCATE PREPARE tc_automation_run_index;

-- Modelos são intencionalmente criados pausados. O gestor deve revisar as
-- mensagens, responsáveis e prazos antes de salvar a própria cópia.
INSERT INTO automation_flows (name, description, trigger_type, trigger_config, actions_json, active, is_template, created_by)
SELECT 'Qualificar novo lead',
       'Cria uma tarefa e avisa o responsável logo após uma nova entrada.',
       'lead_new',
       JSON_OBJECT('window_hours', 24, 'cooldown_hours', 24,
         'task', JSON_OBJECT('title', 'Qualificar: {{lead.nome}}', 'description', 'Nova oportunidade recebida pelo CRM.', 'priority', 'alta', 'due_hours', 2),
         'history_message', 'Lead novo encaminhado pela automação {{fluxo}}.'),
       JSON_ARRAY('create_task', 'notify_owner', 'log_history'), 0, 1, u.id
FROM users u
WHERE u.role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM automation_flows f WHERE f.name = 'Qualificar novo lead' AND f.is_template = 1)
ORDER BY u.id LIMIT 1;

INSERT INTO automation_flows (name, description, trigger_type, trigger_config, actions_json, active, is_template, created_by)
SELECT 'Retorno comercial vencido',
       'Recoloca um retorno atrasado na rotina comercial com tarefa, agenda e aviso.',
       'lead_overdue',
       JSON_OBJECT('window_hours', 24, 'cooldown_hours', 24,
         'task', JSON_OBJECT('title', 'Retomar contato: {{lead.nome}}', 'description', 'O retorno deste lead está vencido.', 'priority', 'alta', 'due_hours', 4),
         'event', JSON_OBJECT('title', 'Follow-up: {{lead.nome}}', 'description', 'Retorno criado pela automação.', 'in_hours', 2, 'duration_minutes', 30),
         'history_message', 'Retorno vencido sinalizado pelo fluxo {{fluxo}}.'),
       JSON_ARRAY('create_task', 'create_calendar_event', 'notify_owner', 'log_history'), 0, 1, u.id
FROM users u
WHERE u.role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM automation_flows f WHERE f.name = 'Retorno comercial vencido' AND f.is_template = 1)
ORDER BY u.id LIMIT 1;

INSERT INTO automation_flows (name, description, trigger_type, trigger_config, actions_json, active, is_template, created_by)
SELECT 'Lead quente para distribuição',
       'Quando o score atingir 70, prioriza, etiqueta e chama a equipe responsável.',
       'lead_score',
       JSON_OBJECT('min_score', 70, 'window_hours', 24, 'cooldown_hours', 24,
         'priority_to', 'urgente', 'tag', JSON_OBJECT('name', 'Lead quente', 'color', '#ea580c'),
         'history_message', 'Lead priorizado automaticamente pelo fluxo {{fluxo}}.'),
       JSON_ARRAY('set_priority', 'add_tag', 'notify_manager', 'log_history'), 0, 1, u.id
FROM users u
WHERE u.role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM automation_flows f WHERE f.name = 'Lead quente para distribuição' AND f.is_template = 1)
ORDER BY u.id LIMIT 1;
