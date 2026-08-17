<?php
/**
 * app/controllers/DashboardController.php
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/helpers/insights.php';

class DashboardController extends Controller
{
    private Lead $leadModel;

    public function __construct()
    {
        $this->leadModel = new Lead();
    }

    public function index(): void
    {
        $this->requireLogin();

        // Notificações (Fase 3): gera alertas de lead parado sob demanda, sem cron.
        try {
            (new Notification())->generateStaleLeadAlerts(5);
        } catch (Throwable $e) {
            error_log('DashboardController - falha ao gerar notificações de lead parado: ' . $e->getMessage());
        }

        $leadModel = $this->leadModel;

        // ---- KPIs ----
        $totalLeads   = $leadModel->count();
        $newToday     = $leadModel->countToday();
        $newThisWeek  = $leadModel->countThisWeek();
        $newThisMonth = $leadModel->countThisMonth();

        $qualifiedStatuses = ['em_negociacao', 'documentacao', 'aguardando_aprovacao', 'aprovado'];
        $lostStatuses      = ['perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'bloqueou', 'duplicado'];
        $inProgressStatuses = ['primeiro_contato', 'tentando_contato', 'em_negociacao', 'documentacao', 'aguardando_cliente', 'aguardando_aprovacao'];
        $closedStatuses    = ['fechado'];

        $qualified   = $leadModel->countByStatuses($qualifiedStatuses);
        $lost        = $leadModel->countByStatuses($lostStatuses);
        $inProgress  = $leadModel->countByStatuses($inProgressStatuses);
        $closed      = $leadModel->countByStatuses($closedStatuses);
        $withoutContact = $leadModel->countWithoutContact(5);

        $conversionRate = $totalLeads > 0 ? round(($closed / $totalLeads) * 100, 1) : 0.0;

        $kpis = [
            'total'            => $totalLeads,
            'new_today'        => $newToday,
            'new_week'         => $newThisWeek,
            'new_month'        => $newThisMonth,
            'qualified'        => $qualified,
            'lost'             => $lost,
            'in_progress'      => $inProgress,
            'conversion_rate'  => $conversionRate,
            'without_contact'  => $withoutContact,
        ];

        // ---- Dados para gráficos (Chart.js) ----
        $perDayRaw = $leadModel->leadsPerDay(30);
        $perDay = $this->fillMissingDays($perDayRaw, 30);

        $bySource = $leadModel->leadsBySource();
        $byState  = $leadModel->leadsByState();
        $byStatus = $leadModel->leadsByStatus();

        // ---- Insights automáticos (Fase 2: motor mais completo em app/helpers/insights.php) ----
        $baseInsights = [];

        $topState = $leadModel->topStateShare();
        if ($topState && $topState['state'] !== 'N/D') {
            $baseInsights[] = sprintf(
                'O estado %s representa %s%% dos leads cadastrados.',
                $topState['state'],
                str_replace('.', ',', (string) $topState['percentage'])
            );
        }

        if ($withoutContact > 0) {
            $baseInsights[] = [
                'text' => sprintf(
                    '%d lead%s sem nenhum contato há mais de 5 dias.',
                    $withoutContact,
                    $withoutContact > 1 ? 's estão' : ' está'
                ),
                // Insight acionável (Fase 5): clique leva para a listagem de
                // Leads já filtrada, mostrando exatamente quais são esses leads.
                'url' => url('leads?sem_contato_dias=5'),
            ];
        }

        if ($totalLeads > 0) {
            $baseInsights[] = sprintf(
                'Taxa de conversão atual: %s%% dos leads foram fechados.',
                str_replace('.', ',', (string) $conversionRate)
            );
        }

        $insights = generate_insights(Database::getInstance(), $baseInsights);

        $this->view('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'kpis'      => $kpis,
            'perDay'    => $perDay,
            'bySource'  => $bySource,
            'byState'   => $byState,
            'byStatus'  => $byStatus,
            'insights'  => $insights,
        ]);
    }

    /** Endpoint opcional para recarregar dados dos gráficos via AJAX (JSON) */
    public function chartData(): void
    {
        $this->requireLogin();

        $this->json([
            'perDay'   => $this->fillMissingDays($this->leadModel->leadsPerDay(30), 30),
            'bySource' => $this->leadModel->leadsBySource(),
            'byState'  => $this->leadModel->leadsByState(),
            'byStatus' => $this->leadModel->leadsByStatus(),
        ]);
    }

    /** Preenche dias sem leads com total 0, para o gráfico de linha ficar contínuo */
    private function fillMissingDays(array $rows, int $days): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['day']] = (int) $row['total'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = [
                'day'   => $day,
                'total' => $map[$day] ?? 0,
            ];
        }

        return $result;
    }
}
