<?php
/**
 * app/controllers/TaskController.php
 * Módulo de Tarefas: demandas de trabalho genéricas delegadas entre
 * colaboradores, podendo ou não estar vinculadas a um lead específico
 * (diferente de "próximo contato de um lead", já coberto pela Agenda).
 * Ver database/sql/migration_tasks.sql.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Task.php';
require_once APP_PATH . '/models/TaskComment.php';
require_once APP_PATH . '/models/TaskHistory.php';
require_once APP_PATH . '/models/TaskWatcher.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/Lead.php';

class TaskController extends Controller
{
    private Task $taskModel;
    private TaskComment $commentModel;
    private TaskHistory $historyModel;
    private TaskWatcher $watcherModel;
    private Notification $notificationModel;

    public function __construct()
    {
        $this->taskModel = new Task();
        $this->commentModel = new TaskComment();
        $this->historyModel = new TaskHistory();
        $this->watcherModel = new TaskWatcher();
        $this->notificationModel = new Notification();
    }

    public function index(): void
    {
        $this->requireLogin();

        $userId = Auth::id();
        $canViewAll = Auth::can('tasks.view_all');

        $tab = (string) $this->input('tab', 'mine');
        if (!in_array($tab, ['mine', 'created', 'all'], true) || ($tab === 'all' && !$canViewAll)) {
            $tab = 'mine';
        }
        $scope = $tab === 'all' ? 'all' : $tab;

        $filters = [
            'status'      => $this->input('status', ''),
            'priority'    => $this->input('priority', ''),
            'assigned_to' => $this->input('assigned_to', ''),
            'overdue'     => $this->input('overdue', ''),
            'search'      => trim((string) $this->input('search', '')),
        ];

        $page    = (int) $this->input('page', 1);
        // O Kanban precisa de uma visão mais ampla que a tabela antiga para
        // não esconder cartões em várias páginas. Ainda respeitamos o limite
        // de 100 definido no modelo e mantemos paginação para bases maiores.
        $perPage = (int) $this->input('per_page', 100);
        $sortBy  = (string) $this->input('sort', 'due_at');
        $sortDir = (string) $this->input('dir', 'ASC');

        $result = $this->taskModel->paginate($filters, $scope, $userId, $page, $perPage, $sortBy, $sortDir);

        $userModel = new User();

        $this->view('tasks/index', [
            'pageTitle'  => 'Tarefas',
            'tasks'      => $result['items'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'perPage'    => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters'    => $filters,
            'sortBy'     => $sortBy,
            'sortDir'    => $sortDir,
            'tab'        => $tab,
            'canViewAll' => $canViewAll,
            'users'      => $userModel->allActive(),
            'taskModel'  => $this->taskModel,
        ]);
    }

    public function create(): void
    {
        $this->requireLogin();

        if (!Auth::can('tasks.create')) {
            flash('error', 'Você não tem permissão para criar tarefas.');
            $this->redirect('tarefas');
            return;
        }

        $userModel = new User();
        $leadModel = new Lead();

        $leadId = (int) $this->input('lead_id', 0);
        $preselectedLead = $leadId ? $leadModel->find($leadId) : null;

        $this->view('tasks/form', [
            'pageTitle'       => 'Nova Tarefa',
            'task'            => null,
            'users'           => $userModel->allActive(),
            'preselectedLead' => $preselectedLead,
            'formAction'      => url('tarefas'),
        ]);
    }

    public function store(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('tasks.create')) {
            flash('error', 'Você não tem permissão para criar tarefas.');
            $this->redirect('tarefas');
            return;
        }

        $data = $this->sanitizeInput($_POST);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            flash('error', 'Informe um título para a tarefa.');
            $this->redirect('tarefas/nova');
            return;
        }

        $data['creator_id'] = Auth::id();

        $taskId = $this->taskModel->create($data);

        $this->historyModel->add($taskId, Auth::id(), 'criacao', 'Tarefa criada.');

        // O criador é sempre watcher automático
        $this->watcherModel->add($taskId, Auth::id());

        if (!empty($data['assigned_to'])) {
            $this->historyModel->add($taskId, Auth::id(), 'atribuicao', 'Tarefa atribuída no momento da criação.');
            $this->notifyUsers([(int) $data['assigned_to']], Auth::id(), $taskId, 'Nova tarefa atribuída a você', 'Você recebeu a tarefa "' . $title . '".');
        }

        // Fase 7: sincronização OPCIONAL Tarefas -> Agenda. Só acontece se o
        // usuário marcar explicitamente a checkbox "Também atualizar o
        // próximo contato deste lead" no formulário (ver app/views/tasks/form.php).
        // Nunca é automática, para não surpreender o fluxo de Agenda.
        if (!empty($data['lead_id']) && !empty($data['due_at']) && $this->input('sync_next_contact') === '1') {
            $this->syncLeadNextContact((int) $data['lead_id'], (string) $data['due_at'], $taskId);
        }

        log_activity('tarefa_criada', 'Tarefa #' . $taskId . ' ("' . $title . '") criada.');

        flash('success', 'Tarefa criada com sucesso.');
        $this->redirect('tarefas/' . $taskId);
    }

    public function show(string $id): void
    {
        $this->requireLogin();

        $task = $this->taskModel->findWithRelations((int) $id);
        if (!$task || !$this->canView($task)) {
            flash('error', 'Tarefa não encontrada.');
            $this->redirect('tarefas');
            return;
        }

        $userModel = new User();

        $this->view('tasks/show', [
            'pageTitle' => $task['title'],
            'task'      => $task,
            'sla'       => $this->taskModel->slaStatus($task),
            'comments'  => $this->commentModel->forTask($task['id']),
            'history'   => $this->historyModel->forTask($task['id']),
            'canManage' => $this->canManage($task),
            'users'     => $userModel->allActive(),
        ]);
    }

    public function edit(string $id): void
    {
        $this->requireLogin();

        $task = $this->taskModel->findWithRelations((int) $id);
        if (!$task || !$this->canManage($task)) {
            flash('error', 'Você não tem permissão para editar esta tarefa.');
            $this->redirect('tarefas/' . (int) $id);
            return;
        }

        $userModel = new User();

        $this->view('tasks/form', [
            'pageTitle'       => 'Editar Tarefa',
            'task'            => $task,
            'users'           => $userModel->allActive(),
            'preselectedLead' => null,
            'formAction'      => url('tarefas/' . $task['id']),
        ]);
    }

    /** POST /tarefas/{id} — atualização geral (título, descrição, prioridade, prazo, SLA, responsável, lead). */
    public function update(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $taskId = (int) $id;
        $existing = $this->taskModel->find($taskId);
        if (!$existing) {
            flash('error', 'Tarefa não encontrada.');
            $this->redirect('tarefas');
            return;
        }

        if (!$this->canManage($existing)) {
            flash('error', 'Você não tem permissão para editar esta tarefa.');
            $this->redirect('tarefas/' . $taskId);
            return;
        }

        $data = $this->sanitizeInput($_POST);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            flash('error', 'Informe um título para a tarefa.');
            $this->redirect('tarefas/' . $taskId . '/edit');
            return;
        }

        $this->taskModel->updateTask($taskId, $data);

        if ((string) ($data['priority'] ?? '') !== (string) $existing['priority']) {
            $this->historyModel->add($taskId, Auth::id(), 'prioridade', 'Prioridade alterada de "' . $existing['priority'] . '" para "' . $data['priority'] . '".');
        }
        if ((string) ($data['due_at'] ?? '') !== (string) ($existing['due_at'] ?? '')) {
            $this->historyModel->add($taskId, Auth::id(), 'prazo', 'Prazo alterado para ' . (($data['due_at'] ?? null) ? format_date($data['due_at'], true) : 'sem prazo definido') . '.');
        }

        // Reatribuição de responsável, se veio no formulário e mudou
        if (array_key_exists('assigned_to', $data) && (int) ($data['assigned_to'] ?? 0) !== (int) ($existing['assigned_to'] ?? 0)) {
            $this->taskModel->reassign($taskId, $data['assigned_to'] ? (int) $data['assigned_to'] : null);
            $this->historyModel->add($taskId, Auth::id(), 'atribuicao', 'Tarefa reatribuída.');
            if (!empty($data['assigned_to'])) {
                $this->notifyOthers($taskId, Auth::id(), 'Tarefa atribuída a você', 'Você foi definido como responsável pela tarefa "' . $title . '".', [(int) $data['assigned_to']]);
            }
        }

        log_activity('tarefa_atualizada', 'Tarefa #' . $taskId . ' atualizada.');

        flash('success', 'Tarefa atualizada com sucesso.');
        $this->redirect('tarefas/' . $taskId);
    }

    /** POST /tarefas/{id}/comentarios */
    public function addComment(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $taskId = (int) $id;
        $task = $this->taskModel->find($taskId);
        if (!$task || !$this->canView($task)) {
            flash('error', 'Tarefa não encontrada.');
            $this->redirect('tarefas');
            return;
        }

        $comment = trim((string) $this->input('comment', ''));
        if ($comment === '') {
            flash('error', 'Escreva um comentário antes de enviar.');
            $this->redirect('tarefas/' . $taskId);
            return;
        }

        $this->commentModel->add($taskId, Auth::id(), $comment);
        $this->historyModel->add($taskId, Auth::id(), 'comentario', 'Novo comentário adicionado.');

        $this->notifyOthers($taskId, Auth::id(), 'Novo comentário em uma tarefa', 'Comentário em "' . $task['title'] . '": ' . mb_substr($comment, 0, 140));

        $this->redirect('tarefas/' . $taskId);
    }

    /** POST /tarefas/{id}/status — mudança rápida de status (AJAX). */
    public function changeStatus(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $taskId = (int) $id;
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            $this->json(['success' => false, 'message' => 'Tarefa não encontrada.'], 404);
            return;
        }

        if (!$this->canView($task)) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para alterar esta tarefa.'], 403);
            return;
        }

        $status = (string) $this->input('status', '');
        $validStatuses = ['pendente', 'em_andamento', 'aguardando', 'concluida', 'cancelada'];
        if (!in_array($status, $validStatuses, true)) {
            $this->json(['success' => false, 'message' => 'Status inválido.'], 422);
            return;
        }

        $this->taskModel->changeStatus($taskId, $status, $task);

        $statusLabels = [
            'pendente' => 'Pendente', 'em_andamento' => 'Em Andamento', 'aguardando' => 'Aguardando',
            'concluida' => 'Concluída', 'cancelada' => 'Cancelada',
        ];
        $this->historyModel->add(
            $taskId,
            Auth::id(),
            $status === 'concluida' ? 'conclusao' : 'status',
            'Status alterado de "' . ($statusLabels[$task['status']] ?? $task['status']) . '" para "' . ($statusLabels[$status] ?? $status) . '".'
        );

        // Fase 7: sincronização OPCIONAL Tarefas -> Agenda ao concluir. Só
        // acontece se o chamador enviar sync_lead_contact=1 (checkbox/ação
        // explícita, ver app/views/tasks/show.php e app/views/today/index.php).
        // Nunca é automática. Atualiza last_contact_at do lead vinculado e
        // limpa next_contact_at (o compromisso foi cumprido agora).
        if ($status === 'concluida' && !empty($task['lead_id']) && $this->input('sync_lead_contact') === '1') {
            $this->syncLeadOnTaskCompleted((int) $task['lead_id'], $taskId);
        }

        $this->notifyOthers($taskId, Auth::id(), 'Status de tarefa atualizado', 'A tarefa "' . $task['title'] . '" agora está "' . ($statusLabels[$status] ?? $status) . '".');

        log_activity('tarefa_status', 'Tarefa #' . $taskId . ' teve status alterado para ' . $status . '.');

        $updated = $this->taskModel->find($taskId);

        $this->json([
            'success' => true,
            'status'  => $status,
            'label'   => $statusLabels[$status] ?? $status,
            'sla'     => $this->taskModel->slaStatus($updated),
        ]);
    }

    /** POST /tarefas/{id}/excluir */
    public function destroy(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $taskId = (int) $id;
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            flash('error', 'Tarefa não encontrada.');
            $this->redirect('tarefas');
            return;
        }

        if (!Auth::can('tasks.manage') && (int) $task['creator_id'] !== Auth::id()) {
            flash('error', 'Você não tem permissão para excluir esta tarefa.');
            $this->redirect('tarefas/' . $taskId);
            return;
        }

        $this->taskModel->delete($taskId);
        log_activity('tarefa_excluida', 'Tarefa #' . $taskId . ' ("' . $task['title'] . '") excluída.');

        flash('success', 'Tarefa excluída com sucesso.');
        $this->redirect('tarefas');
    }

    // ---------------- Visibilidade / permissões ----------------

    private function canView(array $task): bool
    {
        if (Auth::can('tasks.view_all')) {
            return true;
        }
        $userId = Auth::id();
        if ((int) ($task['creator_id'] ?? 0) === $userId || (int) ($task['assigned_to'] ?? 0) === $userId) {
            return true;
        }
        return $this->watcherModel->isWatching((int) $task['id'], $userId);
    }

    private function canManage(array $task): bool
    {
        if (Auth::can('tasks.manage')) {
            return true;
        }
        return (int) ($task['creator_id'] ?? 0) === Auth::id();
    }

    // ---------------- Sincronização Tarefas <-> Agenda (Fase 7) ----------------
    // Ver README.md, seção "Sincronização Tarefas <-> Agenda": integração
    // deliberadamente conservadora - nada aqui roda sem uma ação explícita
    // do usuário (checkbox no formulário ou clique em "concluir com sincronia").

    /**
     * Atualiza leads.next_contact_at para bater com o due_at de uma tarefa
     * recém-criada, registrando o evento no histórico do lead.
     */
    private function syncLeadNextContact(int $leadId, string $dueAt, int $taskId): void
    {
        try {
            $leadModel = new Lead();
            $lead = $leadModel->find($leadId);
            if (!$lead) {
                return;
            }
            $leadModel->update($leadId, ['next_contact_at' => $dueAt]);

            require_once APP_PATH . '/models/LeadHistory.php';
            (new LeadHistory())->add(
                $leadId,
                Auth::id(),
                'agendamento',
                'Próximo contato atualizado para ' . format_date($dueAt, true) . ' (sincronizado com a tarefa #' . $taskId . ').'
            );
        } catch (Throwable $e) {
            error_log('TaskController::syncLeadNextContact - ' . $e->getMessage());
        }
    }

    /**
     * Ao concluir uma tarefa vinculada a um lead (com confirmação explícita
     * do usuário), marca last_contact_at = agora e limpa next_contact_at
     * (o compromisso foi cumprido). Não é destrutivo: apenas atualiza datas,
     * nunca muda status/dados do lead.
     */
    private function syncLeadOnTaskCompleted(int $leadId, int $taskId): void
    {
        try {
            $leadModel = new Lead();
            $lead = $leadModel->find($leadId);
            if (!$lead) {
                return;
            }
            $leadModel->update($leadId, [
                'last_contact_at' => date('Y-m-d H:i:s'),
                'next_contact_at' => null,
            ]);

            require_once APP_PATH . '/models/LeadHistory.php';
            (new LeadHistory())->add(
                $leadId,
                Auth::id(),
                'observacao',
                'Último contato atualizado automaticamente após conclusão da tarefa #' . $taskId . '.'
            );
        } catch (Throwable $e) {
            error_log('TaskController::syncLeadOnTaskCompleted - ' . $e->getMessage());
        }
    }

    // ---------------- Notificações ----------------

    /**
     * Notifica um conjunto de usuários (notificação interna, reaproveitando
     * o model/tela já existente do sino - ver NotificationController).
     * Nunca notifica quem executou a própria ação.
     */
    private function notifyUsers(array $userIds, int $actorId, int $taskId, string $title, string $message): void
    {
        $link = 'tarefas/' . $taskId;
        foreach (array_unique($userIds) as $userId) {
            $userId = (int) $userId;
            if ($userId === $actorId || $userId <= 0) {
                continue;
            }
            try {
                $this->notificationModel->create($userId, $title, $message, $link);
            } catch (Throwable $e) {
                error_log('TaskController::notifyUsers - ' . $e->getMessage());
            }
        }
    }

    /**
     * Notifica o responsável atual da tarefa + todos os watchers, exceto
     * quem executou a ação. Usado em toda mudança relevante (status,
     * prioridade, prazo, reatribuição, comentário).
     *
     * @param array $extraUserIds IDs adicionais a notificar (ex: novo responsável)
     */
    private function notifyOthers(int $taskId, int $actorId, string $title, string $message, array $extraUserIds = []): void
    {
        $task = $this->taskModel->find($taskId);
        $recipients = $extraUserIds;

        if ($task && !empty($task['assigned_to'])) {
            $recipients[] = (int) $task['assigned_to'];
        }

        $recipients = array_merge($recipients, $this->watcherModel->userIdsForTask($taskId));

        $this->notifyUsers($recipients, $actorId, $taskId, $title, $message);
    }

    // ---------------- Sanitização ----------------

    private array $fillable = [
        'title', 'description', 'assigned_to', 'lead_id', 'priority', 'status', 'due_at', 'sla_hours',
    ];

    private function sanitizeInput(array $input): array
    {
        $data = [];

        foreach ($this->fillable as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $data[$field] = null;
                continue;
            }

            switch ($field) {
                case 'assigned_to':
                case 'lead_id':
                case 'sla_hours':
                    $data[$field] = (int) $value;
                    break;
                case 'due_at':
                    $data[$field] = str_replace('T', ' ', (string) $value);
                    break;
                case 'priority':
                    $data[$field] = in_array($value, ['baixa', 'media', 'alta', 'urgente'], true) ? $value : 'media';
                    break;
                case 'status':
                    $data[$field] = in_array($value, ['pendente', 'em_andamento', 'aguardando', 'concluida', 'cancelada'], true) ? $value : 'pendente';
                    break;
                default:
                    $data[$field] = $value;
            }
        }

        return $data;
    }
}
