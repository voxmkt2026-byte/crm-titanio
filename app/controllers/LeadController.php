<?php
/**
 * app/controllers/LeadController.php
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Tag.php';
require_once APP_PATH . '/models/LossReason.php';
require_once APP_PATH . '/models/LeadScore.php';
require_once APP_PATH . '/models/Notification.php';

class LeadController extends Controller
{
    /** Campos aceitos do formulário de lead (whitelist contra mass assignment) */
    private array $fillable = [
        'name', 'ddd', 'phone', 'whatsapp', 'email', 'cpf', 'city', 'state', 'zipcode',
        'source', 'campaign', 'adset', 'ad', 'utm_source', 'utm_medium', 'utm_campaign',
        'utm_content', 'utm_term', 'interest', 'desired_value', 'has_down_payment',
        'down_payment_value', 'income_range', 'profession', 'company', 'status',
        'last_contact_at', 'next_contact_at', 'notes', 'internal_notes', 'assigned_to',
        'lead_score', 'temperature', 'priority', 'loss_reason_id', 'closed_value',
    ];

    private Lead $leadModel;
    private LeadHistory $historyModel;
    private LeadScore $scoreModel;
    private Notification $notificationModel;

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->historyModel = new LeadHistory();
        $this->scoreModel = new LeadScore();
        $this->notificationModel = new Notification();
    }

    /**
     * Notifica (notificação interna + e-mail via SMTP) o consultor responsável
     * quando um lead é atribuído a ele. Falha graciosamente: se e-mail/SMTP
     * não estiverem configurados, apenas a notificação interna é criada.
     */
    private function notifyAssignment(int $leadId, ?int $assignedTo, string $leadName): void
    {
        if (!$assignedTo) {
            return;
        }

        try {
            $link = 'leads/' . $leadId;
            $this->notificationModel->create(
                $assignedTo,
                'Novo lead atribuído a você',
                'O lead "' . ($leadName ?: 'sem nome') . '" foi atribuído a você.',
                $link
            );

            $userModel = new User();
            $user = $userModel->find($assignedTo);
            if ($user && !empty($user['email'])) {
                require_once APP_PATH . '/core/Mailer.php';
                $mailer = Mailer::fromSettings();
                if ($mailer->isConfigured()) {
                    $html = '<p>Olá, ' . e($user['name']) . '!</p>'
                        . '<p>Um novo lead foi atribuído a você no ' . e(APP_NAME) . ':</p>'
                        . '<p><strong>' . e($leadName ?: 'Lead #' . $leadId) . '</strong></p>'
                        . '<p><a href="' . e(url($link)) . '">Clique aqui para abrir o lead</a></p>';
                    $mailer->send($user['email'], 'Novo lead atribuído a você - ' . APP_NAME, $html, $user['name']);
                }
            }
        } catch (Throwable $e) {
            // Nunca deixa uma falha de notificação/e-mail quebrar o fluxo de salvar o lead
            error_log('LeadController::notifyAssignment - ' . $e->getMessage());
        }
    }

    /**
     * Recalcula automaticamente o lead_score (Fase 2) com base nos dados
     * atuais do lead + quantidade de interações registradas no histórico,
     * e persiste o novo valor.
     */
    private function recalculateScore(int $leadId): void
    {
        // Lógica compartilhada em LeadScore::recalculateForLead() (Fase 5),
        // também reaproveitada por ImportController e AgendaController.
        $this->scoreModel->recalculateForLead($leadId);
    }

    public function index(): void
    {
        $this->requireLogin();

        $filters = [
            'search'           => trim((string) $this->input('search', '')),
            'state'            => $this->input('state', ''),
            'source'           => $this->input('source', ''),
            'interest'         => $this->input('interest', ''),
            'status'           => $this->input('status', ''),
            'assigned_to'      => $this->input('assigned_to', ''),
            'date_from'        => $this->normalizeDateFilter($this->input('date_from', '')),
            'date_to'          => $this->normalizeDateFilter($this->input('date_to', '')),
            // Filtro acionável vindo dos KPIs de receita: data de fechamento,
            // diferente da data de cadastro usada pelos filtros comuns.
            'closed_from'      => $this->normalizeDateFilter($this->input('closed_from', '')),
            'closed_to'        => $this->normalizeDateFilter($this->input('closed_to', '')),
            'created_today'    => $this->input('created_today', '') === '1' ? '1' : '',
            // Filtros "acionáveis" (Fase 5), usados pelos links dos insights do
            // Dashboard: leads sem contato há N dias e leads vencidos (next_contact_at no passado).
            'sem_contato_dias' => $this->input('sem_contato_dias', ''),
            'vencidos'         => $this->input('vencidos', ''),
        ];

        // Atalho da listagem para os cadastros do dia. As datas continuam no
        // filtro para que ordenação e paginação mantenham exatamente o resultado.
        if ($filters['created_today'] === '1') {
            $filters['date_from'] = date('Y-m-d');
            $filters['date_to'] = date('Y-m-d');
        }

        // Escopo "Meus leads" / "Todos os leads" (Fase 7 - auditoria UX): mesma
        // permissão usada pela Agenda (admin/supervisor veem tudo, consultor
        // comum só vê os próprios leads). Padrão é sempre "meus", mesmo para
        // quem pode ver tudo, mas o toggle na tela permite alternar.
        $canViewAll = Auth::hasRole(['admin', 'supervisor']);
        $scope = (string) $this->input('view', 'mine');
        if (!$canViewAll || $scope !== 'all') {
            $scope = 'mine';
            $filters['assigned_to'] = (string) Auth::id();
        }

        $page    = (int) $this->input('page', 1);
        $perPage = (int) $this->input('per_page', 20);
        $sortBy  = (string) $this->input('sort', 'created_at');
        $sortDir = (string) $this->input('dir', 'DESC');

        $result = $this->leadModel->paginate($filters, $page, $perPage, $sortBy, $sortDir);

        $userModel = new User();
        $tagModel = new Tag();

        $this->view('leads/index', [
            'pageTitle' => 'Leads',
            'leads'     => $result['items'],
            'total'     => $result['total'],
            'page'      => $result['page'],
            'perPage'   => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters'   => $filters,
            'sortBy'    => $sortBy,
            'sortDir'   => $sortDir,
            'users'     => $userModel->allActive(),
            'states'    => brazilian_states(),
            'canViewAll' => $canViewAll,
            'scope'      => $scope,
            'allTags'    => $tagModel->all('name ASC'),
        ]);
    }

    /**
     * Busca global do topbar (Fase 7 - auditoria UX): AJAX, retorna JSON com
     * até 8 leads que casam por nome, telefone, e-mail ou lead_code.
     * Respeita o mesmo escopo "meus leads" de quem não pode ver tudo.
     */
    public function quickSearch(): void
    {
        $this->requireLogin();

        $term = trim((string) $this->input('q', ''));
        if (mb_strlen($term) < 1) {
            $this->json(['items' => []]);
            return;
        }

        $canViewAll = Auth::hasRole(['admin', 'supervisor']);
        $onlyAssignedTo = $canViewAll ? null : Auth::id();

        $items = $this->leadModel->quickSearch($term, $onlyAssignedTo, 8);

        $this->json([
            'items' => array_map(function ($lead) {
                return [
                    'id'        => (int) $lead['id'],
                    'lead_code' => $lead['lead_code'],
                    'name'      => $lead['name'] ?: ('Lead #' . $lead['id']),
                    'phone'     => format_phone($lead['whatsapp'] ?: $lead['phone']),
                    'status'    => status_label($lead['status']),
                    'url'       => url('leads/' . $lead['id']),
                ];
            }, $items),
        ]);
    }

    /**
     * Observação rápida via AJAX (Fase 7 - auditoria UX), reaproveitável na
     * listagem, no Pipeline e no perfil do lead: grava em lead_history
     * (tipo 'observacao'), atualiza last_contact_at e recalcula o lead_score,
     * seguindo o mesmo padrão do "Registrar contato agora" da Agenda.
     */
    public function quickNote(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $id;
        $lead = $this->leadModel->find($leadId);

        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        if (!Auth::hasRole(['admin', 'supervisor']) && (int) ($lead['assigned_to'] ?? 0) !== Auth::id()) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para registrar uma observação neste lead.'], 403);
            return;
        }

        $note = trim((string) $this->input('note', ''));
        if ($note === '') {
            $this->json(['success' => false, 'message' => 'Escreva uma observação antes de salvar.'], 422);
            return;
        }

        $this->historyModel->add($leadId, Auth::id(), 'observacao', $note);

        $now = date('Y-m-d H:i:s');
        $this->leadModel->update($leadId, ['last_contact_at' => $now]);

        $this->recalculateScore($leadId);

        log_activity('lead_nota_rapida', 'Observação rápida registrada no lead #' . $leadId . '.');

        $this->json([
            'success'         => true,
            'message'         => 'Observação registrada com sucesso.',
            'last_contact_at' => $now,
            'last_contact_label' => days_since_contact_label($now),
        ]);
    }

    /**
     * Ações em lote na listagem de Leads (Fase 7 - auditoria UX): mudar
     * status, reatribuir responsável ou aplicar tag a vários leads de uma
     * vez. Cada lead afetado recebe um registro individual em lead_history
     * (auditoria não é pulada só porque a ação é em lote).
     */
    public function bulkAction(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('leads.edit')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para executar ações em lote.'], 403);
            return;
        }

        $ids = $this->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));

        $action = (string) $this->input('action', '');
        $value = trim((string) $this->input('value', ''));

        if (empty($ids) || !in_array($action, ['status', 'assigned_to', 'tag'], true) || $value === '') {
            $this->json(['success' => false, 'message' => 'Selecione ao menos um lead, a ação e o valor desejado.'], 422);
            return;
        }

        $tagModel = $action === 'tag' ? new Tag() : null;
        $affected = 0;

        foreach ($ids as $leadId) {
            $lead = $this->leadModel->find($leadId);
            if (!$lead) {
                continue;
            }

            switch ($action) {
                case 'status':
                    $this->leadModel->updateStatus($leadId, $value);
                    $this->historyModel->add(
                        $leadId,
                        Auth::id(),
                        'status',
                        'Status alterado em lote de "' . status_label($lead['status']) . '" para "' . status_label($value) . '".'
                    );
                    break;

                case 'assigned_to':
                    $newAssignedTo = (int) $value;
                    $this->leadModel->update($leadId, ['assigned_to' => $newAssignedTo]);
                    $this->historyModel->add(
                        $leadId,
                        Auth::id(),
                        'responsavel',
                        'Responsável alterado em lote (ação em massa na listagem de Leads).'
                    );
                    if ($newAssignedTo !== (int) ($lead['assigned_to'] ?? 0)) {
                        $this->notifyAssignment($leadId, $newAssignedTo, (string) ($lead['name'] ?? ''));
                    }
                    break;

                case 'tag':
                    $tagId = (int) $value;
                    $currentTagIds = array_map(fn($t) => (int) $t['id'], $tagModel->forLead($leadId));
                    $currentTagIds[] = $tagId;
                    $tagModel->syncForLead($leadId, array_values(array_unique($currentTagIds)));
                    $this->historyModel->add($leadId, Auth::id(), 'observacao', 'Tag aplicada em lote na listagem de Leads.');
                    break;
            }

            $affected++;
        }

        log_activity('leads_acao_em_lote', 'Ação em lote (' . $action . ') aplicada a ' . $affected . ' lead(s).');

        $this->json(['success' => true, 'affected' => $affected]);
    }

    /**
     * Transfere um único lead para outro responsável diretamente pela
     * listagem. A ação é restrita a gestores e preserva a trilha de auditoria
     * e a notificação de atribuição do novo responsável.
     */
    public function transfer(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::hasRole(['admin', 'supervisor']) || !Auth::can('leads.edit')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para transferir leads.'], 403);
            return;
        }

        $leadId = (int) $id;
        $newAssignedTo = (int) $this->input('assigned_to', 0);
        $lead = $this->leadModel->find($leadId);
        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        $userModel = new User();
        $newAssignee = $newAssignedTo > 0 ? $userModel->find($newAssignedTo) : null;
        if (!$newAssignee || empty($newAssignee['active'])) {
            $this->json(['success' => false, 'message' => 'Selecione um responsável ativo.'], 422);
            return;
        }

        if ($newAssignedTo === (int) ($lead['assigned_to'] ?? 0)) {
            $this->json(['success' => false, 'message' => 'Este lead já está atribuído a esse responsável.'], 422);
            return;
        }

        $oldAssigneeName = 'não atribuído';
        if (!empty($lead['assigned_to'])) {
            $oldAssignee = $userModel->find((int) $lead['assigned_to']);
            $oldAssigneeName = $oldAssignee['name'] ?? $oldAssigneeName;
        }

        $this->leadModel->update($leadId, ['assigned_to' => $newAssignedTo]);
        $this->historyModel->add(
            $leadId,
            Auth::id(),
            'responsavel',
            'Lead transferido de "' . $oldAssigneeName . '" para "' . $newAssignee['name'] . '".'
        );
        $this->notifyAssignment($leadId, $newAssignedTo, (string) ($lead['name'] ?? ''));

        log_activity('lead_transferido', 'Lead #' . $leadId . ' transferido para "' . $newAssignee['name'] . '".');

        $this->json([
            'success' => true,
            'message' => 'Lead transferido para ' . $newAssignee['name'] . '.',
        ]);
    }

    public function create(): void
    {
        $this->requireLogin();

        $userModel = new User();
        $lossReasonModel = new LossReason();
        $tagModel = new Tag();

        $this->view('leads/form', [
            'pageTitle'    => 'Novo Lead',
            'lead'         => null,
            'users'        => $userModel->allActive(),
            'lossReasons'  => $lossReasonModel->allActive(),
            'states'       => brazilian_states(),
            'allTags'      => $tagModel->all('name ASC'),
            'leadTags'     => [],
            'formAction'   => url('leads/store'),
        ]);
    }

    public function store(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $data = $this->sanitizeInput($_POST);

        // Origem padrão do cadastro manual pela tela (Fase 4): o <select> do
        // wizard já vem com "Cadastro Manual" pré-selecionado, mas reforçamos
        // aqui como rede de segurança caso o campo chegue vazio.
        if (empty($data['source'])) {
            $data['source'] = 'cadastro_manual';
        }
        if (($data['status'] ?? '') === 'fechado') {
            $data['closed_at'] = date('Y-m-d H:i:s');
            $data['closed_by'] = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        }

        // Gera lead_code único (Fase 4) e cria o registro com retry em caso
        // de colisão por concorrência (ver Lead::createWithLeadCode).
        $result = $this->leadModel->createWithLeadCode($data);
        $leadId = $result['id'];
        $leadCode = $result['lead_code'];

        $this->historyModel->add(
            $leadId,
            Auth::id(),
            'criacao',
            'Lead criado no sistema. Código: ' . $leadCode . '.'
        );

        // Tags (Fase 4): associa tags existentes selecionadas + novas tags digitadas
        $tagModel = new Tag();
        $tagModel->syncForLead($leadId, $this->resolveTagIds($_POST, $tagModel));

        // Lead Score automático (Fase 2): recalcula com base nos dados recém-salvos
        $this->recalculateScore($leadId);

        // Notificação + e-mail (Fase 3) ao consultor responsável, se já atribuído na criação
        if (!empty($data['assigned_to'])) {
            $this->notifyAssignment($leadId, (int) $data['assigned_to'], (string) ($data['name'] ?? ''));
        }

        log_activity('lead_criado', 'Lead #' . $leadId . ' (' . $leadCode . ', "' . ($data['name'] ?? 'sem nome') . '") criado.');

        flash('success', 'Lead ' . $leadCode . ' criado com sucesso.');
        $this->redirect('leads/' . $leadId);
    }

    public function show(string $id): void
    {
        $this->requireLogin();

        $lead = $this->leadModel->findWithAssigned((int) $id);
        if (!$lead) {
            flash('error', 'Lead não encontrado.');
            $this->redirect('leads');
            return;
        }

        $tagModel = new Tag();

        $this->view('leads/show', [
            'pageTitle' => $lead['name'] ?: 'Lead #' . $lead['id'],
            'lead'      => $lead,
            'history'   => $this->historyModel->forLead($lead['id']),
            'tags'      => $tagModel->forLead($lead['id']),
        ]);
    }

    public function edit(string $id): void
    {
        $this->requireLogin();

        $lead = $this->leadModel->find((int) $id);
        if (!$lead) {
            flash('error', 'Lead não encontrado.');
            $this->redirect('leads');
            return;
        }

        $userModel = new User();
        $lossReasonModel = new LossReason();
        $tagModel = new Tag();

        $this->view('leads/form', [
            'pageTitle'   => 'Editar Lead',
            'lead'        => $lead,
            'users'       => $userModel->allActive(),
            'lossReasons' => $lossReasonModel->allActive(),
            'states'      => brazilian_states(),
            'allTags'     => $tagModel->all('name ASC'),
            'leadTags'    => $tagModel->forLead((int) $lead['id']),
            'formAction'  => url('leads/' . $lead['id'] . '/update'),
        ]);
    }

    public function update(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $id;
        $existing = $this->leadModel->find($leadId);
        if (!$existing) {
            flash('error', 'Lead não encontrado.');
            $this->redirect('leads');
            return;
        }

        $data = $this->sanitizeInput($_POST);
        if (($data['status'] ?? '') === 'fechado' && ($existing['status'] ?? '') !== 'fechado') {
            $data['closed_at'] = date('Y-m-d H:i:s');
            $data['closed_by'] = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : ($existing['assigned_to'] ?? null);
        } elseif (($data['status'] ?? '') !== 'fechado' && ($existing['status'] ?? '') === 'fechado') {
            $data['closed_at'] = null;
            $data['closed_by'] = null;
        }
        $this->leadModel->update($leadId, $data);

        // Tags (Fase 4): substitui o conjunto de tags pelo selecionado no formulário
        $tagModel = new Tag();
        $tagModel->syncForLead($leadId, $this->resolveTagIds($_POST, $tagModel));

        // Registra alteração de status separadamente no histórico, se mudou
        if (isset($data['status']) && $data['status'] !== $existing['status']) {
            $this->historyModel->add(
                $leadId,
                Auth::id(),
                'status',
                'Status alterado de "' . status_label($existing['status']) . '" para "' . status_label($data['status']) . '".'
            );
            if ($data['status'] === 'fechado') {
                $this->historyModel->add($leadId, Auth::id(), 'fechamento', 'Venda registrada no valor de ' . format_money($data['closed_value'] ?? null) . '.');
            }
        }

        $this->historyModel->add($leadId, Auth::id(), 'dado_alterado', 'Dados do lead atualizados.');

        // Lead Score automático (Fase 2): recalcula com base nos dados atualizados
        $this->recalculateScore($leadId);

        // Notificação + e-mail (Fase 3) ao consultor responsável, se o responsável mudou
        if (array_key_exists('assigned_to', $data) && (int) $data['assigned_to'] !== (int) ($existing['assigned_to'] ?? 0) && !empty($data['assigned_to'])) {
            $this->notifyAssignment($leadId, (int) $data['assigned_to'], (string) ($data['name'] ?? $existing['name'] ?? ''));
        }

        log_activity('lead_atualizado', 'Lead #' . $leadId . ' atualizado.');

        flash('success', 'Lead atualizado com sucesso.');
        $this->redirect('leads/' . $leadId);
    }

    public function destroy(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('leads.delete')) {
            flash('error', 'Você não tem permissão para excluir leads.');
            $this->redirect('leads/' . (int) $id);
            return;
        }

        $this->leadModel->delete((int) $id);
        log_activity('lead_excluido', 'Lead #' . (int) $id . ' excluído.');

        flash('success', 'Lead excluído com sucesso.');
        $this->redirect('leads');
    }

    public function addNote(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $id;
        $note = trim((string) $this->input('note', ''));

        if ($note !== '') {
            $this->historyModel->add($leadId, Auth::id(), 'observacao', $note);
            $this->recalculateScore($leadId);
        }

        $this->redirect('leads/' . $leadId);
    }

    /**
     * Checagem AJAX de duplicidade (whatsapp/telefone/cpf/email).
     * Retorna JSON: { duplicate: bool, lead: {...}|null }
     */
    public function checkDuplicate(): void
    {
        $this->requireLogin();

        $phone    = $this->normalizeDigits($this->input('phone', ''));
        $whatsapp = $this->normalizeDigits($this->input('whatsapp', ''));
        $cpf      = $this->normalizeDigits($this->input('cpf', ''));
        $email    = trim((string) $this->input('email', ''));
        $excludeId = $this->input('id') ? (int) $this->input('id') : null;

        $duplicate = $this->leadModel->findDuplicate($phone, $whatsapp, $cpf, $email, $excludeId);

        if ($duplicate) {
            $this->json([
                'duplicate' => true,
                'lead' => [
                    'id'        => $duplicate['id'],
                    'name'      => $duplicate['name'] ?: ('Lead #' . $duplicate['id']),
                    'lead_code' => $duplicate['lead_code'] ?? null,
                    'phone'     => format_phone($duplicate['whatsapp'] ?: $duplicate['phone']),
                    'email'     => $duplicate['email'],
                    'status'    => status_label($duplicate['status']),
                    'url'       => url('leads/' . $duplicate['id']),
                    'editUrl'   => url('leads/' . $duplicate['id'] . '/edit'),
                ],
            ]);
            return;
        }

        $this->json(['duplicate' => false]);
    }

    /**
     * Resolve a lista final de IDs de tags a partir do POST do formulário de
     * lead (Fase 4): "tags_existing[]" (ids de tags já cadastradas marcadas
     * no wizard) + "tags_new" (texto livre, nomes separados por vírgula, que
     * são criados na hora via Tag::findOrCreateByName).
     */
    private function resolveTagIds(array $input, Tag $tagModel): array
    {
        $ids = [];

        if (!empty($input['tags_existing']) && is_array($input['tags_existing'])) {
            foreach ($input['tags_existing'] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (!empty($input['tags_new']) && is_string($input['tags_new'])) {
            foreach (explode(',', $input['tags_new']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $ids[] = $tagModel->findOrCreateByName($name);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Sanitiza e filtra os dados recebidos do formulário de lead */
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

            // Campos vazios viram NULL (nenhum campo do lead é obrigatório, exceto nome)
            if ($value === '') {
                $data[$field] = null;
                continue;
            }

            switch ($field) {
                case 'phone':
                case 'whatsapp':
                case 'cpf':
                case 'zipcode':
                case 'ddd':
                    $data[$field] = $this->normalizeDigits($value);
                    break;
                case 'has_down_payment':
                    $data[$field] = ($value === '1' || $value === 1 || $value === true) ? 1 : 0;
                    break;
                case 'desired_value':
                case 'down_payment_value':
                case 'closed_value':
                    $data[$field] = $this->normalizeMoney($value);
                    break;
                case 'lead_score':
                case 'assigned_to':
                case 'loss_reason_id':
                    $data[$field] = (int) $value;
                    break;
                case 'state':
                    $data[$field] = strtoupper(substr($value, 0, 2));
                    break;
                default:
                    $data[$field] = $value;
            }
        }

        // Checkbox não marcado não vem no POST
        if (!array_key_exists('has_down_payment', $data)) {
            $data['has_down_payment'] = array_key_exists('has_down_payment', $input) ? 1 : 0;
        }

        // Metadados automáticos de origem (não editáveis pelo usuário)
        if (!isset($input['_skip_meta'])) {
            $data['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $data['device']     = $this->detectDevice();
            $data['browser']    = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }

        return $data;
    }

    private function normalizeDigits($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);
        return $digits !== '' ? $digits : null;
    }

    /** Normaliza datas recebidas pelos filtros da listagem (formato YYYY-MM-DD). */
    private function normalizeDateFilter($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function normalizeMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Aceita formatos "1.234,56" ou "1234.56"
        $value = str_replace('.', '', (string) $value);
        $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float) $value : null;
    }

    private function detectDevice(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/mobile/i', $ua)) {
            return 'mobile';
        }
        if (preg_match('/tablet/i', $ua)) {
            return 'tablet';
        }
        return 'desktop';
    }
}
