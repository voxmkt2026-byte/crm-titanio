<?php
/**
 * Motor de automações no-code. Os gatilhos são avaliados pelo cron e as
 * ações conversam com os módulos nativos (leads, tarefas, agenda, WhatsApp,
 * e-mail, notificações e histórico). Configurações antigas por rótulo seguem
 * aceitas por normalizeAction(), para não quebrar fluxos já cadastrados.
 */

require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/WhatsappClient.php';
require_once APP_PATH . '/helpers/helpers.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/Setting.php';

class AutomationRunner
{
    private PDO $db;

    private const TERMINAL_STATUSES = ['fechado', 'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'nao_responde', 'bloqueou', 'duplicado'];
    private const LEAD_STATUSES = ['novo', 'primeiro_contato', 'tentando_contato', 'em_negociacao', 'documentacao', 'aguardando_cliente', 'aguardando_aprovacao', 'aprovado', 'fechado', 'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'nao_responde', 'bloqueou', 'duplicado'];
    private const PRIORITIES = ['baixa', 'media', 'alta', 'urgente'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function triggerCatalog(): array
    {
        return [
            'lead_stale' => ['label' => 'Lead sem interação', 'help' => 'Não recebeu contato dentro do número de dias informado.', 'unit' => 'dias', 'default' => 10],
            'lead_new' => ['label' => 'Novo lead captado', 'help' => 'Lead entrou recentemente no CRM.', 'unit' => 'horas', 'default' => 24],
            'lead_overdue' => ['label' => 'Retorno vencido', 'help' => 'O próximo contato está atrasado.', 'unit' => 'horas', 'default' => 24],
            'lead_status' => ['label' => 'Lead em um status', 'help' => 'O status escolhido foi atualizado recentemente.', 'unit' => 'horas', 'default' => 24],
            'lead_score' => ['label' => 'Lead atingiu score', 'help' => 'O score mínimo foi atingido ou atualizado recentemente.', 'unit' => 'pontos', 'default' => 70],
            'lead_closed' => ['label' => 'Venda fechada', 'help' => 'Um lead foi fechado recentemente.', 'unit' => 'horas', 'default' => 24],
        ];
    }

    public static function actionCatalog(): array
    {
        return [
            'send_whatsapp' => ['label' => 'Enviar WhatsApp', 'icon' => 'fa-brands fa-whatsapp', 'help' => 'Usa um template aprovado configurado para a automação.'],
            'send_email' => ['label' => 'Enviar e-mail', 'icon' => 'fa-solid fa-envelope', 'help' => 'Envia e-mail ao contato usando o SMTP configurado.'],
            'create_task' => ['label' => 'Criar tarefa', 'icon' => 'fa-solid fa-list-check', 'help' => 'Cria um cartão no Kanban e vincula ao lead.'],
            'create_calendar_event' => ['label' => 'Criar evento na agenda', 'icon' => 'fa-solid fa-calendar-plus', 'help' => 'Agenda um follow-up para a equipe.'],
            'notify_manager' => ['label' => 'Avisar gestores', 'icon' => 'fa-solid fa-bell', 'help' => 'Notifica administradores e supervisores ativos.'],
            'notify_owner' => ['label' => 'Notificar responsável', 'icon' => 'fa-solid fa-user-bell', 'help' => 'Avisa o responsável atual pelo lead.'],
            'set_priority' => ['label' => 'Definir prioridade', 'icon' => 'fa-solid fa-flag', 'help' => 'Define a prioridade comercial escolhida.'],
            'add_tag' => ['label' => 'Adicionar etiqueta', 'icon' => 'fa-solid fa-tag', 'help' => 'Cria ou reutiliza uma etiqueta personalizada.'],
            'change_status' => ['label' => 'Alterar status', 'icon' => 'fa-solid fa-arrow-right-arrow-left', 'help' => 'Move o lead para uma etapa do funil.'],
            'reassign_owner' => ['label' => 'Reatribuir responsável', 'icon' => 'fa-solid fa-people-arrows', 'help' => 'Encaminha o lead a uma pessoa da equipe.'],
            'schedule_followup' => ['label' => 'Agendar próximo contato', 'icon' => 'fa-solid fa-clock', 'help' => 'Preenche a data de próximo contato do lead.'],
            'log_history' => ['label' => 'Registrar no histórico', 'icon' => 'fa-solid fa-clock-rotate-left', 'help' => 'Mantém rastreabilidade na ficha do lead.'],
        ];
    }

    public static function normalizeAction(string $action): ?string
    {
        $aliases = [
            'Enviar WhatsApp' => 'send_whatsapp', 'Criar tarefa' => 'create_task', 'Avisar gestor' => 'notify_manager',
            'Avisar gestores' => 'notify_manager', 'Notificar responsável' => 'notify_owner', 'Aumentar prioridade' => 'set_priority',
            'Adicionar tag automação' => 'add_tag', 'Registrar histórico' => 'log_history',
        ];
        if (isset($aliases[$action])) {
            return $aliases[$action];
        }
        return array_key_exists($action, self::actionCatalog()) ? $action : null;
    }

    public static function actionLabel(string $action): string
    {
        $key = self::normalizeAction($action);
        return $key ? (self::actionCatalog()[$key]['label'] ?? $key) : $action;
    }

    /** Executa fluxos ativos pelo cron, ou um único fluxo quando solicitado no painel. */
    public function run(?int $onlyFlowId = null): array
    {
        $stats = ['flows' => 0, 'leads' => 0, 'success' => 0, 'partial' => 0, 'errors' => 0];
        if ($onlyFlowId) {
            $stmt = $this->db->prepare('SELECT * FROM automation_flows WHERE id = :id AND is_template = 0');
            $stmt->execute([':id' => $onlyFlowId]);
            $flows = $stmt->fetchAll();
        } else {
            $flows = $this->db->query('SELECT * FROM automation_flows WHERE active = 1 AND is_template = 0')->fetchAll();
        }

        foreach ($flows as $flow) {
            $stats['flows']++;
            try {
                foreach ($this->candidates($flow, 200) as $lead) {
                    $result = $this->execute($flow, $lead);
                    $stats['leads']++;
                    if ($result === 'sucesso') {
                        $stats['success']++;
                    } elseif ($result === 'parcial') {
                        $stats['partial']++;
                    } else {
                        $stats['errors']++;
                    }
                }
                $this->db->prepare('UPDATE automation_flows SET last_run_at = NOW() WHERE id = :id')->execute([':id' => $flow['id']]);
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log('AutomationRunner::run - fluxo #' . $flow['id'] . ': ' . $e->getMessage());
            }
        }
        return $stats;
    }

    /** Teste sem efeito colateral: retorna total e uma amostra dos leads que acionariam o fluxo. */
    public function preview(array $definition): array
    {
        $flow = [
            'id' => (int) ($definition['id'] ?? 0),
            'trigger_type' => (string) ($definition['trigger_type'] ?? 'lead_stale'),
            'trigger_config' => is_string($definition['trigger_config'] ?? null)
                ? $definition['trigger_config'] : json_encode($definition['trigger_config'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
        $total = $this->candidateCount($flow);
        $sample = array_map(static fn(array $lead) => [
            'id' => (int) $lead['id'], 'name' => $lead['name'] ?: ('Lead #' . $lead['id']),
            'status' => $lead['status'], 'source' => $lead['source'], 'created_at' => $lead['created_at'],
        ], $this->candidates($flow, 8, false));
        return ['total' => $total, 'sample' => $sample];
    }

    private function config(array $flow): array
    {
        $config = json_decode((string) ($flow['trigger_config'] ?? '{}'), true);
        return is_array($config) ? $config : [];
    }

    private function candidates(array $flow, int $limit, bool $applyRunGuard = true): array
    {
        [$conditions, $params] = $this->candidateConditions($flow, $applyRunGuard);
        $sql = 'SELECT l.* FROM leads l WHERE ' . implode(' AND ', $conditions) . ' ORDER BY l.updated_at DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function candidateCount(array $flow): int
    {
        [$conditions, $params] = $this->candidateConditions($flow, false);
        $stmt = $this->db->prepare('SELECT COUNT(*) AS total FROM leads l WHERE ' . implode(' AND ', $conditions));
        $stmt->execute($params);
        return (int) (($stmt->fetch() ?: [])['total'] ?? 0);
    }

    /** Monta somente SQL com valores normalizados pelo servidor. */
    private function candidateConditions(array $flow, bool $applyRunGuard): array
    {
        $config = $this->config($flow);
        $trigger = (string) ($flow['trigger_type'] ?? 'lead_stale');
        if (!array_key_exists($trigger, self::triggerCatalog())) {
            $trigger = 'lead_stale';
        }
        $conditions = [];
        $params = [];
        $terminalList = "'" . implode("','", self::TERMINAL_STATUSES) . "'";
        $hours = max(1, min(720, (int) ($config['window_hours'] ?? $config['value'] ?? 24)));
        $days = max(1, min(365, (int) ($config['days'] ?? $config['value'] ?? 10)));
        $cooldown = max(1, min(720, (int) ($config['cooldown_hours'] ?? 24)));

        if ($trigger === 'lead_stale') {
            $conditions[] = "l.status NOT IN ({$terminalList})";
            $conditions[] = "COALESCE(l.last_contact_at, l.created_at) <= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), true, $cooldown, $applyRunGuard);
        } elseif ($trigger === 'lead_new') {
            $conditions[] = "l.status NOT IN ({$terminalList})";
            $conditions[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), false, $cooldown, $applyRunGuard);
        } elseif ($trigger === 'lead_overdue') {
            $conditions[] = "l.status NOT IN ({$terminalList})";
            $conditions[] = 'l.next_contact_at IS NOT NULL AND l.next_contact_at < NOW()';
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), true, $cooldown, $applyRunGuard);
        } elseif ($trigger === 'lead_status') {
            $statuses = array_values(array_intersect((array) ($config['statuses'] ?? []), self::LEAD_STATUSES));
            if (!$statuses) {
                $conditions[] = '1 = 0';
            } else {
                $placeholders = [];
                foreach ($statuses as $index => $status) {
                    $key = ':trigger_status_' . $index;
                    $placeholders[] = $key;
                    $params[$key] = $status;
                }
                $conditions[] = 'l.status IN (' . implode(',', $placeholders) . ')';
            }
            $conditions[] = "l.updated_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), false, $cooldown, $applyRunGuard);
        } elseif ($trigger === 'lead_score') {
            $score = max(0, min(100, (int) ($config['min_score'] ?? $config['value'] ?? 70)));
            $conditions[] = "l.status NOT IN ({$terminalList})";
            $conditions[] = 'l.lead_score >= :trigger_score';
            $conditions[] = "l.updated_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
            $params[':trigger_score'] = $score;
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), false, $cooldown, $applyRunGuard);
        } elseif ($trigger === 'lead_closed') {
            $conditions[] = "l.status = 'fechado'";
            $conditions[] = "COALESCE(l.closed_at, l.updated_at) >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
            $this->addRunGuard($conditions, $params, (int) ($flow['id'] ?? 0), false, $cooldown, $applyRunGuard);
        }

        if (!empty($config['source'])) {
            $conditions[] = 'l.source = :condition_source';
            $params[':condition_source'] = (string) $config['source'];
        }
        if (!empty($config['assigned_to'])) {
            $conditions[] = 'l.assigned_to = :condition_assigned';
            $params[':condition_assigned'] = (int) $config['assigned_to'];
        }
        if (!empty($config['only_unassigned'])) {
            $conditions[] = 'l.assigned_to IS NULL';
        }
        return [$conditions ?: ['1 = 0'], $params];
    }

    private function addRunGuard(array &$conditions, array &$params, int $flowId, bool $repeatAfterCooldown, int $cooldown, bool $enabled): void
    {
        if (!$enabled || $flowId <= 0) {
            return;
        }
        $guard = 'ar.flow_id = :guard_flow AND ar.lead_id = l.id';
        if ($repeatAfterCooldown) {
            $guard .= " AND ar.created_at >= DATE_SUB(NOW(), INTERVAL {$cooldown} HOUR)";
        }
        $conditions[] = 'NOT EXISTS (SELECT 1 FROM automation_runs ar WHERE ' . $guard . ')';
        $params[':guard_flow'] = $flowId;
    }

    private function execute(array $flow, array $lead): string
    {
        $config = $this->config($flow);
        $storedActions = json_decode((string) ($flow['actions_json'] ?? '[]'), true);
        $actions = [];
        foreach (is_array($storedActions) ? $storedActions : [] as $stored) {
            $key = self::normalizeAction((string) $stored);
            if ($key && !in_array($key, $actions, true)) {
                $actions[] = $key;
            }
        }

        $details = [];
        $failures = 0;
        foreach ($actions as $action) {
            try {
                $this->performAction($action, $flow, $lead, $config);
                $details[] = self::actionLabel($action);
            } catch (Throwable $e) {
                $failures++;
                $details[] = self::actionLabel($action) . ': ' . $e->getMessage();
                error_log('AutomationRunner::execute - ' . $action . ': ' . $e->getMessage());
            }
        }

        $status = !$actions || $failures === count($actions) ? 'erro' : ($failures ? 'parcial' : 'sucesso');
        $this->db->prepare('INSERT INTO automation_runs(flow_id, lead_id, status, details) VALUES(:flow, :lead, :status, :details)')
            ->execute([':flow' => $flow['id'], ':lead' => $lead['id'], ':status' => $status, ':details' => implode('; ', $details)]);
        return $status;
    }

    private function performAction(string $action, array $flow, array $lead, array $config): void
    {
        switch ($action) {
            case 'send_whatsapp':
                $this->sendWhatsapp($lead, $config);
                return;
            case 'send_email':
                $this->sendEmail($lead, $config);
                return;
            case 'create_task':
                $this->createTask($flow, $lead, $config);
                return;
            case 'create_calendar_event':
                $this->createCalendarEvent($flow, $lead, $config);
                return;
            case 'notify_manager':
                $this->notifyManagers($flow, $lead);
                return;
            case 'notify_owner':
                if (empty($lead['assigned_to'])) {
                    throw new RuntimeException('lead sem responsável');
                }
                (new Notification())->create((int) $lead['assigned_to'], 'Automação: acompanhamento necessário', $this->leadName($lead) . ' entrou no fluxo ' . $flow['name'] . '.', 'leads/' . $lead['id']);
                return;
            case 'set_priority':
                $priority = in_array($config['priority_to'] ?? '', self::PRIORITIES, true) ? $config['priority_to'] : 'alta';
                $this->db->prepare('UPDATE leads SET priority = :priority WHERE id = :id')->execute([':priority' => $priority, ':id' => $lead['id']]);
                return;
            case 'add_tag':
                $this->addTag($lead, $config);
                return;
            case 'change_status':
                $this->changeStatus($lead, $config);
                return;
            case 'reassign_owner':
                $this->reassignOwner($lead, $config);
                return;
            case 'schedule_followup':
                $hours = max(1, min(720, (int) ($config['followup_hours'] ?? 24)));
                $this->db->prepare("UPDATE leads SET next_contact_at = DATE_ADD(NOW(), INTERVAL {$hours} HOUR) WHERE id = :id")->execute([':id' => $lead['id']]);
                return;
            case 'log_history':
                $description = $this->render((string) ($config['history_message'] ?? 'Fluxo automático executado: {{fluxo}}.'), $flow, $lead);
                $this->db->prepare("INSERT INTO lead_history(lead_id, user_id, type, description, created_at) VALUES(:lead, :user, 'observacao', :description, NOW())")
                    ->execute([':lead' => $lead['id'], ':user' => (int) $flow['created_by'], ':description' => $description]);
                return;
        }
        throw new RuntimeException('ação não reconhecida');
    }

    private function sendWhatsapp(array $lead, array $config): void
    {
        $phone = $lead['whatsapp'] ?: $lead['phone'];
        $settings = (new Setting())->allAsMap();
        $template = trim((string) ($config['whatsapp_template'] ?? $settings['automation_whatsapp_template'] ?? ''));
        $language = trim((string) ($config['whatsapp_language'] ?? $settings['automation_whatsapp_language'] ?? 'pt_BR')) ?: 'pt_BR';
        if (!$phone || $template === '') {
            throw new RuntimeException('configure telefone e template aprovado do WhatsApp');
        }
        $result = WhatsappClient::fromSettings()->sendTemplateMessage($phone, $template, $language);
        if (empty($result['success'])) {
            throw new RuntimeException((string) ($result['message'] ?? 'envio não confirmado'));
        }
    }

    private function sendEmail(array $lead, array $config): void
    {
        if (empty($lead['email'])) {
            throw new RuntimeException('lead sem e-mail');
        }
        require_once APP_PATH . '/core/Mailer.php';
        $mailer = Mailer::fromSettings();
        if (!$mailer->isConfigured()) {
            throw new RuntimeException('SMTP não configurado');
        }
        $subject = $this->render((string) ($config['email_subject'] ?? 'Atualização sobre seu atendimento'), [], $lead);
        $body = nl2br(e($this->render((string) ($config['email_body'] ?? 'Olá, {{lead.nome}}. Vamos dar continuidade ao seu atendimento?'), [], $lead)));
        if (!$mailer->send((string) $lead['email'], $subject, '<p>' . $body . '</p>', (string) ($lead['name'] ?? ''))) {
            throw new RuntimeException('o SMTP não confirmou o envio');
        }
    }

    private function createTask(array $flow, array $lead, array $config): void
    {
        $task = (array) ($config['task'] ?? []);
        $priority = in_array($task['priority'] ?? '', self::PRIORITIES, true) ? $task['priority'] : 'alta';
        $hours = max(1, min(720, (int) ($task['due_hours'] ?? 24)));
        $assigned = (int) ($task['assigned_to'] ?? 0) ?: (int) ($lead['assigned_to'] ?? 0) ?: (int) $flow['created_by'];
        $title = $this->render((string) ($task['title'] ?? 'Retomar contato: {{lead.nome}}'), $flow, $lead);
        $description = $this->render((string) ($task['description'] ?? 'Criada automaticamente pelo fluxo {{fluxo}}.'), $flow, $lead);
        $this->db->prepare("INSERT INTO tasks(title, description, lead_id, creator_id, assigned_to, priority, status, due_at, created_at, updated_at) VALUES(:title, :description, :lead, :creator, :assigned, :priority, 'pendente', DATE_ADD(NOW(), INTERVAL {$hours} HOUR), NOW(), NOW())")
            ->execute([':title' => mb_substr($title, 0, 200), ':description' => $description, ':lead' => $lead['id'], ':creator' => $flow['created_by'], ':assigned' => $assigned, ':priority' => $priority]);
    }

    private function createCalendarEvent(array $flow, array $lead, array $config): void
    {
        $event = (array) ($config['event'] ?? []);
        $hours = max(1, min(720, (int) ($event['in_hours'] ?? 24)));
        $duration = max(15, min(480, (int) ($event['duration_minutes'] ?? 30)));
        $assigned = (int) ($event['assigned_to'] ?? 0) ?: (int) ($lead['assigned_to'] ?? 0) ?: (int) $flow['created_by'];
        $title = $this->render((string) ($event['title'] ?? 'Follow-up automático: {{lead.nome}}'), $flow, $lead);
        $description = $this->render((string) ($event['description'] ?? 'Criado pelo fluxo {{fluxo}}.'), $flow, $lead);
        $this->db->prepare("INSERT INTO calendar_events(title, description, guidance, start_at, end_at, all_day, color, priority, event_type, lead_id, person_name, assigned_to, created_by) VALUES(:title, :description, :guidance, DATE_ADD(NOW(), INTERVAL {$hours} HOUR), DATE_ADD(DATE_ADD(NOW(), INTERVAL {$hours} HOUR), INTERVAL {$duration} MINUTE), 0, '#7c3aed', 'alta', 'follow_up', :lead, :person, :assigned, :creator)")
            ->execute([':title' => mb_substr($title, 0, 180), ':description' => $description, ':guidance' => $description, ':lead' => $lead['id'], ':person' => $lead['name'] ?: null, ':assigned' => $assigned, ':creator' => $flow['created_by']]);
    }

    private function notifyManagers(array $flow, array $lead): void
    {
        $admins = $this->db->query("SELECT id FROM users WHERE active = 1 AND role IN ('admin','supervisor')")->fetchAll();
        $notification = new Notification();
        foreach ($admins as $admin) {
            $notification->create((int) $admin['id'], 'Automação: atenção necessária', $this->leadName($lead) . ' entrou no fluxo ' . $flow['name'] . '.', 'leads/' . $lead['id']);
        }
    }

    private function addTag(array $lead, array $config): void
    {
        $tag = (array) ($config['tag'] ?? []);
        $name = mb_substr(trim((string) ($tag['name'] ?? 'Automação')), 0, 80) ?: 'Automação';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($tag['color'] ?? '')) ? $tag['color'] : '#7c3aed';
        $this->db->prepare('INSERT IGNORE INTO tags(name, color) VALUES(:name, :color)')->execute([':name' => $name, ':color' => $color]);
        $tagId = (int) $this->db->lastInsertId();
        if (!$tagId) {
            $find = $this->db->prepare('SELECT id FROM tags WHERE name = :name LIMIT 1');
            $find->execute([':name' => $name]);
            $tagId = (int) $find->fetchColumn();
        }
        if (!$tagId) {
            throw new RuntimeException('não foi possível criar a etiqueta');
        }
        $this->db->prepare('INSERT IGNORE INTO lead_tags(lead_id, tag_id) VALUES(:lead, :tag)')->execute([':lead' => $lead['id'], ':tag' => $tagId]);
    }

    private function changeStatus(array $lead, array $config): void
    {
        $status = (string) ($config['status_to'] ?? '');
        if (!in_array($status, self::LEAD_STATUSES, true) || $status === 'fechado') {
            throw new RuntimeException('status de destino inválido');
        }
        $this->db->prepare('UPDATE leads SET status = :status WHERE id = :id')->execute([':status' => $status, ':id' => $lead['id']]);
    }

    private function reassignOwner(array $lead, array $config): void
    {
        $userId = (int) ($config['reassign_to'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('selecione o novo responsável');
        }
        $check = $this->db->prepare('SELECT id FROM users WHERE id = :id AND active = 1');
        $check->execute([':id' => $userId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('responsável indisponível');
        }
        $this->db->prepare('UPDATE leads SET assigned_to = :user WHERE id = :id')->execute([':user' => $userId, ':id' => $lead['id']]);
    }

    private function render(string $text, array $flow, array $lead): string
    {
        return strtr($text, [
            '{{lead.nome}}' => $this->leadName($lead),
            '{{lead.codigo}}' => (string) ($lead['lead_code'] ?? ('#' . ($lead['id'] ?? ''))),
            '{{lead.status}}' => status_label($lead['status'] ?? ''),
            '{{fluxo}}' => (string) ($flow['name'] ?? 'automação'),
        ]);
    }

    private function leadName(array $lead): string
    {
        return trim((string) ($lead['name'] ?? '')) ?: ('Lead #' . ($lead['id'] ?? ''));
    }
}
