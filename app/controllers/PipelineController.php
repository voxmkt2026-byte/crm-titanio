<?php
/**
 * app/controllers/PipelineController.php
 * Kanban de pipeline: exibe leads agrupados por estágio e move leads entre
 * colunas via requisição AJAX (drag-and-drop).
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/PipelineStage.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Notification.php';

class PipelineController extends Controller
{
    /**
     * Mapeia o "name" de cada coluna do pipeline (pipeline_stages) para os
     * status de lead que devem aparecer naquela coluna. Mantém o Kanban
     * simples (7 colunas) mesmo com o enum de status mais granular do CRM.
     */
    private array $stageStatusMap = [
        'Novo'          => ['novo'],
        'Contato'       => ['primeiro_contato', 'tentando_contato', 'nao_responde'],
        'Qualificado'   => ['aguardando_cliente'],
        'Negociação'    => ['em_negociacao'],
        'Documentação'  => ['documentacao', 'aguardando_aprovacao', 'aprovado'],
        'Fechado'       => ['fechado'],
        'Perdido'       => ['perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'bloqueou', 'duplicado'],
    ];

    /** Status "principal" usado ao mover um card para dentro de uma coluna */
    private array $stageDefaultStatus = [
        'Novo'          => 'novo',
        'Contato'       => 'primeiro_contato',
        'Qualificado'   => 'aguardando_cliente',
        'Negociação'    => 'em_negociacao',
        'Documentação'  => 'documentacao',
        'Fechado'       => 'fechado',
        'Perdido'       => 'perdido',
    ];

    /** Limite de leads exibidos por status dentro de uma coluna do Kanban (mantido igual ao comportamento anterior) */
    private const LEADS_PER_STATUS_LIMIT = 200;

    private Lead $leadModel;
    private LeadHistory $historyModel;
    private PipelineStage $stageModel;
    private Notification $notificationModel;
    private PDO $db;

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->historyModel = new LeadHistory();
        $this->stageModel = new PipelineStage();
        $this->notificationModel = new Notification();
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->requireLogin();

        $stages = $this->stageModel->allOrdered();

        // Escopo "Meus leads" / "Todos os leads" (Fase 7 - auditoria UX): mesma
        // permissão/lógica usada pela Agenda e por LeadController::index().
        $canViewAll = Auth::hasRole(['admin', 'supervisor']);
        $scope = (string) $this->input('view', 'mine');
        if (!$canViewAll) {
            $scope = 'mine';
        }

        // Filtro opcional por responsável (disponível só em "Todos os leads",
        // para o supervisor focar em um consultor específico da equipe)
        $assignedTo = (string) $this->input('assigned_to', '');
        if ($scope !== 'all') {
            $assignedTo = (string) Auth::id();
        }

        // ---- Fase 7 (auditoria UX): elimina o N+1 do loop paginate() por status ----
        // Busca TODOS os leads dos status usados no Kanban em uma única query,
        // limitando por status via ROW_NUMBER() (MySQL 8), reproduzindo o mesmo
        // limite de 200 por status que o paginate() aplicava individualmente antes.
        $allStatuses = [];
        foreach ($stages as $stage) {
            foreach (($this->stageStatusMap[$stage['name']] ?? []) as $status) {
                $allStatuses[] = $status;
            }
        }
        $allStatuses = array_values(array_unique($allStatuses));

        $leadsByStatus = array_fill_keys($allStatuses, []);

        if (!empty($allStatuses)) {
            $placeholders = implode(',', array_fill(0, count($allStatuses), '?'));
            $sql = "SELECT * FROM (
                        SELECT l.*, u.name AS assigned_name,
                               ROW_NUMBER() OVER (PARTITION BY l.status ORDER BY l.updated_at DESC) AS tc_rn
                        FROM leads l
                        LEFT JOIN users u ON u.id = l.assigned_to
                        WHERE l.status IN ({$placeholders})";
            $params = $allStatuses;

            if ($assignedTo !== '') {
                $sql .= " AND l.assigned_to = ?";
                $params[] = $assignedTo;
            }

            $sql .= "  ) t
                    WHERE t.tc_rn <= " . self::LEADS_PER_STATUS_LIMIT . "
                    ORDER BY t.status ASC, t.updated_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $lead) {
                $leadsByStatus[$lead['status']][] = $lead;
            }
        }

        $columns = [];
        foreach ($stages as $stage) {
            $statuses = $this->stageStatusMap[$stage['name']] ?? [];
            $leads = [];
            foreach ($statuses as $status) {
                $leads = array_merge($leads, $leadsByStatus[$status] ?? []);
            }

            $columns[] = [
                'stage' => $stage,
                'leads' => $leads,
            ];
        }

        $userModel = new User();

        $this->view('pipeline/index', [
            'pageTitle'  => 'Pipeline',
            'columns'    => $columns,
            'users'      => $userModel->allActive(),
            'assignedTo' => $assignedTo,
            'canViewAll' => $canViewAll,
            'scope'      => $scope,
        ]);
    }

    /**
     * Endpoint AJAX chamado ao soltar um card em outra coluna.
     * Espera POST: lead_id, stage_name, csrf_token.
     * Atualiza o status do lead via prepared statement e grava no histórico.
     */
    public function move(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('pipeline.manage')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para mover leads no pipeline.'], 403);
            return;
        }

        $leadId = (int) $this->input('lead_id');
        $stageName = (string) $this->input('stage_name', '');

        if ($leadId <= 0 || !isset($this->stageDefaultStatus[$stageName])) {
            $this->json(['success' => false, 'message' => 'Dados inválidos.'], 422);
            return;
        }

        $lead = $this->leadModel->find($leadId);
        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        $newStatus = $this->stageDefaultStatus[$stageName];
        $oldStatus = $lead['status'];

        $this->leadModel->updateStatus($leadId, $newStatus);

        if ($newStatus !== $oldStatus) {
            $this->historyModel->add(
                $leadId,
                Auth::id(),
                'status',
                'Movido no pipeline de "' . status_label($oldStatus) . '" para "' . status_label($newStatus) . '" (coluna: ' . $stageName . ').'
            );
            log_activity('pipeline_status_alterado', 'Lead #' . $leadId . ' movido de "' . status_label($oldStatus) . '" para "' . status_label($newStatus) . '" via Kanban.');

            // Notificação (Fase 3) ao responsável em mudanças de status críticas
            if (in_array($newStatus, ['fechado', 'perdido'], true) && !empty($lead['assigned_to']) && (int) $lead['assigned_to'] !== Auth::id()) {
                try {
                    $title = $newStatus === 'fechado' ? 'Negócio fechado!' : 'Lead marcado como perdido';
                    $this->notificationModel->create(
                        (int) $lead['assigned_to'],
                        $title,
                        'O lead "' . ($lead['name'] ?: 'sem nome') . '" foi movido para "' . status_label($newStatus) . '" no pipeline.',
                        'leads/' . $leadId
                    );
                } catch (Throwable $e) {
                    error_log('PipelineController::move - falha ao criar notificação: ' . $e->getMessage());
                }
            }
        }

        $this->json(['success' => true, 'status' => $newStatus]);
    }
}
