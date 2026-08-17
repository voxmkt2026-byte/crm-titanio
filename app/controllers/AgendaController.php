<?php
/**
 * app/controllers/AgendaController.php
 * Agenda/lista de leads com next_contact_at preenchido, agrupados por dia,
 * com destaque para atrasados, hoje e próximos 7 dias.
 *
 * Fase 5: ação rápida "Registrar contato agora" (AJAX, sem sair da página),
 * filtro por consultor responsável (respeitando permissões - consultor
 * comum só vê a própria agenda), contadores de resumo (atrasados/hoje/
 * próximos 7 dias/sem data) e destaque para leads ativos sem next_contact_at
 * definido há muito tempo ("esquecidos").
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/LeadScore.php';

class AgendaController extends Controller
{
    /** Status considerados "encerrados" (fora do funil ativo) */
    private const CLOSED_STATUSES = ['fechado', 'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'bloqueou', 'duplicado'];

    /** Dias sem next_contact_at definido para um lead ativo ser considerado "esquecido" */
    private const FORGOTTEN_DAYS = 5;

    /** Limite de itens exibidos no grupo "sem data definida", para não sobrecarregar a tela */
    private const NO_DATE_LIMIT = 50;

    /** Tipos de evento aceitos pela ação rápida "Registrar contato agora" */
    private const QUICK_CONTACT_TYPES = ['contato', 'whatsapp', 'ligacao'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->requireLogin();

        // Notificações (Fase 3): gera alertas de lead parado sob demanda, sem cron.
        try {
            (new Notification())->generateStaleLeadAlerts(5);
        } catch (Throwable $e) {
            error_log('AgendaController - falha ao gerar notificações de lead parado: ' . $e->getMessage());
        }

        $canViewAll = Auth::hasRole(['admin', 'supervisor']);
        $assignedTo = (string) $this->input('assigned_to', '');

        // Consultor comum só vê a própria agenda, independente do que vier na querystring.
        if (!$canViewAll) {
            $assignedTo = (string) Auth::id();
        }

        $closedPlaceholders = implode(',', array_fill(0, count(self::CLOSED_STATUSES), '?'));

        // ---- Leads agendados (next_contact_at preenchido) ----
        $sql = "SELECT l.id, l.name, l.phone, l.whatsapp, l.status, l.next_contact_at, l.interest,
                       l.assigned_to, u.name AS assigned_name
                FROM leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                WHERE l.next_contact_at IS NOT NULL";
        $params = [];
        if ($assignedTo !== '') {
            $sql .= " AND l.assigned_to = ?";
            $params[] = $assignedTo;
        }
        $sql .= " ORDER BY l.next_contact_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll();

        $groups = [
            'atrasados' => [],
            'hoje'      => [],
            'semana'    => [],
            'futuro'    => [],
        ];

        $now = time();
        $todayEnd = strtotime('today 23:59:59');
        $weekEnd = strtotime('+7 days', $todayEnd);

        foreach ($leads as $lead) {
            $ts = strtotime((string) $lead['next_contact_at']);
            if ($ts === false) {
                continue;
            }
            if ($ts < $now && $ts < strtotime('today')) {
                $groups['atrasados'][] = $lead;
            } elseif ($ts <= $todayEnd) {
                $groups['hoje'][] = $lead;
            } elseif ($ts <= $weekEnd) {
                $groups['semana'][] = $lead;
            } else {
                $groups['futuro'][] = $lead;
            }
        }

        // ---- Leads ativos "esquecidos": sem next_contact_at e parados há dias (Fase 5) ----
        $noDateSql = "SELECT l.id, l.name, l.phone, l.whatsapp, l.status, l.interest, l.created_at, l.last_contact_at,
                             l.assigned_to, u.name AS assigned_name
                      FROM leads l
                      LEFT JOIN users u ON u.id = l.assigned_to
                      WHERE l.next_contact_at IS NULL
                        AND l.status NOT IN ({$closedPlaceholders})
                        AND l.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $noDateParams = self::CLOSED_STATUSES;
        $noDateParams[] = self::FORGOTTEN_DAYS;
        if ($assignedTo !== '') {
            $noDateSql .= " AND l.assigned_to = ?";
            $noDateParams[] = $assignedTo;
        }
        $noDateSql .= " ORDER BY l.created_at ASC LIMIT " . self::NO_DATE_LIMIT;

        $stmt = $this->db->prepare($noDateSql);
        $stmt->execute($noDateParams);
        $withoutDate = $stmt->fetchAll();

        // ---- Contador total de "esquecidos" (sem LIMIT, para o card de resumo) ----
        $countNoDateSql = "SELECT COUNT(*) AS total FROM leads l
                            WHERE l.next_contact_at IS NULL
                              AND l.status NOT IN ({$closedPlaceholders})
                              AND l.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $countNoDateParams = self::CLOSED_STATUSES;
        $countNoDateParams[] = self::FORGOTTEN_DAYS;
        if ($assignedTo !== '') {
            $countNoDateSql .= " AND l.assigned_to = ?";
            $countNoDateParams[] = $assignedTo;
        }
        $stmt = $this->db->prepare($countNoDateSql);
        $stmt->execute($countNoDateParams);
        $withoutDateTotal = (int) ($stmt->fetch()['total'] ?? 0);

        $userModel = new User();

        $this->view('agenda/index', [
            'pageTitle'        => 'Agenda',
            'groups'           => $groups,
            'withoutDate'      => $withoutDate,
            'withoutDateTotal' => $withoutDateTotal,
            'forgottenDays'    => self::FORGOTTEN_DAYS,
            'users'            => $userModel->allActive(),
            'assignedTo'       => $assignedTo,
            'canViewAll'       => $canViewAll,
            'counts'           => [
                'atrasados' => count($groups['atrasados']),
                'hoje'      => count($groups['hoje']),
                'semana'    => count($groups['semana']),
                'sem_data'  => $withoutDateTotal,
            ],
        ]);
    }

    /**
     * Ação rápida "Registrar contato agora" (AJAX, Fase 5): cria um evento em
     * lead_history, atualiza last_contact_at/next_contact_at do lead e
     * recalcula o lead_score, sem sair da tela de Agenda.
     */
    public function quickContact(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $id;
        $leadModel = new Lead();
        $lead = $leadModel->find($leadId);

        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        // Consultor comum só pode registrar contato nos próprios leads.
        if (!Auth::hasRole(['admin', 'supervisor']) && (int) ($lead['assigned_to'] ?? 0) !== Auth::id()) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para registrar contato neste lead.'], 403);
            return;
        }

        $type = (string) $this->input('type', 'contato');
        if (!in_array($type, self::QUICK_CONTACT_TYPES, true)) {
            $type = 'contato';
        }

        $description = trim((string) $this->input('description', ''));
        if ($description === '') {
            $this->json(['success' => false, 'message' => 'Descreva o que foi tratado no contato.'], 422);
            return;
        }

        $nextContactAt = trim((string) $this->input('next_contact_at', ''));
        $nextContactAt = $nextContactAt !== '' ? str_replace('T', ' ', $nextContactAt) : null;

        $typeLabels = ['contato' => 'Contato', 'whatsapp' => 'WhatsApp', 'ligacao' => 'Ligação'];
        $historyModel = new LeadHistory();
        $historyModel->add(
            $leadId,
            Auth::id(),
            $type,
            '(' . ($typeLabels[$type] ?? 'Contato') . ' via Agenda) ' . $description
        );

        // Se um novo próximo contato foi informado, agenda-o; senão, limpa
        // (o compromisso que estava marcado foi cumprido agora).
        $leadModel->update($leadId, [
            'last_contact_at' => date('Y-m-d H:i:s'),
            'next_contact_at' => $nextContactAt,
        ]);

        // Lead Score automático (Fase 2/5): recalcula com base no novo histórico
        (new LeadScore())->recalculateForLead($leadId);

        log_activity(
            'lead_contato_agenda',
            'Contato (' . ($typeLabels[$type] ?? $type) . ') registrado pela Agenda no lead #' . $leadId . ' ("' . ($lead['name'] ?: 'sem nome') . '").'
        );

        // Fase 7: aviso NÃO destrutivo/opcional - se o lead tiver uma tarefa
        // aberta vinculada a ele, avisa o usuário no retorno AJAX (nunca
        // conclui a tarefa automaticamente, ver README seção "Sincronização
        // Tarefas <-> Agenda").
        $openTaskWarning = null;
        try {
            $stmt = $this->db->prepare(
                "SELECT id, title FROM tasks
                 WHERE lead_id = :lead_id AND status NOT IN ('concluida','cancelada')
                 ORDER BY due_at IS NULL, due_at ASC LIMIT 1"
            );
            $stmt->execute([':lead_id' => $leadId]);
            $openTask = $stmt->fetch();
            if ($openTask) {
                $openTaskWarning = 'Este lead também tem uma tarefa aberta: "' . htmlspecialchars((string) $openTask['title'], ENT_QUOTES, 'UTF-8')
                    . '". Deseja marcá-la como concluída também? Acesse Tarefas para concluir.';
            }
        } catch (Throwable $e) {
            error_log('AgendaController::quickContact - falha ao checar tarefas abertas do lead: ' . $e->getMessage());
        }

        $this->json([
            'success'            => true,
            'message'            => 'Contato registrado com sucesso.',
            'next_contact_at'    => $nextContactAt,
            'open_task_warning'  => $openTaskWarning,
        ]);
    }

    /**
     * "Novo Agendamento" (AJAX): agenda o próximo contato de um lead JÁ
     * CADASTRADO — não cria um evento de calendário solto, apenas define
     * leads.next_contact_at para o lead escolhido (via busca rápida, mesmo
     * endpoint GET /leads/buscar-rapido usado na busca global do topbar) e
     * registra o agendamento em lead_history (tipo 'agendamento'), seguindo
     * o mesmo padrão de quickContact() acima.
     */
    public function schedule(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $this->input('lead_id', 0);
        if ($leadId <= 0) {
            $this->json(['success' => false, 'message' => 'Selecione um lead para agendar.'], 422);
            return;
        }

        $leadModel = new Lead();
        $lead = $leadModel->find($leadId);
        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        // Consultor comum só pode agendar os próprios leads.
        if (!Auth::hasRole(['admin', 'supervisor']) && (int) ($lead['assigned_to'] ?? 0) !== Auth::id()) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para agendar este lead.'], 403);
            return;
        }

        $nextContactAt = trim((string) $this->input('next_contact_at', ''));
        $nextContactAt = $nextContactAt !== '' ? str_replace('T', ' ', $nextContactAt) : '';
        if ($nextContactAt === '' || strtotime($nextContactAt) === false) {
            $this->json(['success' => false, 'message' => 'Informe uma data/hora válida para o agendamento.'], 422);
            return;
        }
        // Normaliza para o formato aceito pela coluna DATETIME.
        $nextContactAt = date('Y-m-d H:i:s', strtotime($nextContactAt));

        $note = trim((string) $this->input('note', ''));

        $leadModel->update($leadId, [
            'next_contact_at' => $nextContactAt,
        ]);

        $description = 'Agendamento criado pela Agenda para ' . format_date($nextContactAt, true) . '.';
        if ($note !== '') {
            $description .= ' Observação: ' . $note;
        }

        $historyModel = new LeadHistory();
        $historyModel->add($leadId, Auth::id(), 'agendamento', $description);

        log_activity(
            'lead_agendamento_criado',
            'Agendamento criado pela Agenda para o lead #' . $leadId . ' ("' . ($lead['name'] ?: 'sem nome') . '") em ' . format_date($nextContactAt, true) . '.'
        );

        $this->json([
            'success'         => true,
            'message'         => 'Agendamento criado com sucesso.',
            'lead_id'         => $leadId,
            'next_contact_at' => $nextContactAt,
        ]);
    }
}
