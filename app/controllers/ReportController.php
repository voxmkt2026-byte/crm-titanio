<?php
/**
 * app/controllers/ReportController.php
 * Relatórios exportáveis (Fase 2): filtros por período, estado, origem e
 * responsável, com exportação em CSV, Excel (HTML table com Content-Type
 * application/vnd.ms-excel, técnica leve sem libs externas) e impressão
 * formatada para PDF via window.print() (ver app/views/reports/print.php).
 *
 * Fase 6: personalização do relatório (logo, cor primária, informações
 * adicionais de cabeçalho/rodapé e seleção de colunas). As preferências são
 * globais (uma única configuração para todo o sistema, como já ocorre em
 * Configurações/SettingController) e reaproveitam a tabela `settings`
 * existente (padrão chave/valor), em vez de criar uma tabela nova só para
 * isso — não há necessidade de múltiplos templates salvos neste momento, e
 * manter tudo em `settings` evita uma migration nova. Ver README.md,
 * seção "Personalização de Relatórios (Fase 6)".
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/services/GeminiService.php';

class ReportController extends Controller
{
    private Lead $leadModel;
    private Setting $settingModel;

    /** Chaves de configuração de personalização do relatório (tabela settings) */
    private const SETTING_LOGO         = 'report_logo';
    private const SETTING_COLOR        = 'report_primary_color';
    private const SETTING_CNPJ         = 'report_cnpj';
    private const SETTING_ADDRESS      = 'report_address';
    private const SETTING_PHONE        = 'report_phone';
    private const SETTING_FOOTER       = 'report_footer_text';
    private const SETTING_COLUMNS      = 'report_columns';

    private const DEFAULT_COLOR = '#1e3a5f';

    /** Colunas padrão exibidas quando o usuário ainda não personalizou nada */
    private const DEFAULT_COLUMNS = [
        'lead_code', 'name', 'phone', 'whatsapp', 'email', 'city', 'state',
        'source', 'interest', 'desired_value', 'status', 'assigned_name',
        'lead_score', 'temperature', 'created_at', 'last_contact_at',
        'closed_value', 'closed_at',
    ];

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->settingModel = new Setting();
    }

    private function currentFilters(): array
    {
        $dateFrom = (string) $this->input('date_from', '');
        $dateTo = (string) $this->input('date_to', '');
        $period = (string) $this->input('period', '');
        $allowedPeriods = ['all', 'today', '7d', '30d', 'month', 'quarter', 'custom'];

        if (!in_array($period, $allowedPeriods, true)) {
            $period = ($dateFrom !== '' || $dateTo !== '') ? 'custom' : 'all';
        }
        // Se o usuário preencheu as datas manualmente sem trocar o seletor,
        // o recorte passa a ser personalizado em vez de descartar a escolha.
        if ($period === 'all' && ($dateFrom !== '' || $dateTo !== '')) {
            $period = 'custom';
        }

        $today = new DateTimeImmutable('today');
        if ($period === 'today') {
            $dateFrom = $today->format('Y-m-d');
            $dateTo = $dateFrom;
        } elseif ($period === '7d') {
            $dateFrom = $today->modify('-6 days')->format('Y-m-d');
            $dateTo = $today->format('Y-m-d');
        } elseif ($period === '30d') {
            $dateFrom = $today->modify('-29 days')->format('Y-m-d');
            $dateTo = $today->format('Y-m-d');
        } elseif ($period === 'month') {
            $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
            $dateTo = $today->format('Y-m-d');
        } elseif ($period === 'quarter') {
            $month = (int) $today->format('n');
            $quarterStart = ((int) floor(($month - 1) / 3) * 3) + 1;
            $dateFrom = $today->format('Y') . '-' . str_pad((string) $quarterStart, 2, '0', STR_PAD_LEFT) . '-01';
            $dateTo = $today->format('Y-m-d');
        } elseif ($period === 'all') {
            $dateFrom = '';
            $dateTo = '';
        }

        if (!$this->isValidDate($dateFrom)) {
            $dateFrom = '';
        }
        if (!$this->isValidDate($dateTo)) {
            $dateTo = '';
        }

        return [
            'period'      => $period,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'state'       => (string) $this->input('state', ''),
            'source'      => (string) $this->input('source', ''),
            'interest'    => (string) $this->input('interest', ''),
            'status'      => (string) $this->input('status', ''),
            'assigned_to' => (string) $this->input('assigned_to', ''),
        ];
    }

    private function isValidDate(string $date): bool
    {
        if ($date === '') {
            return true;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function requireExportPermission(): bool
    {
        if (!Auth::can('leads.export')) {
            flash('error', 'Você não tem permissão para exportar relatórios.');
            $this->redirect('relatorios');
            return false;
        }
        return true;
    }

    /** Acesso à tela de personalização: mesma permissão de exportação + reports.view */
    private function requireCustomizePermission(): bool
    {
        if (!Auth::can('reports.view') || !Auth::can('leads.export')) {
            flash('error', 'Você não tem permissão para personalizar relatórios.');
            $this->redirect('relatorios');
            return false;
        }
        return true;
    }

    public function index(): void
    {
        $this->requireLogin();

        if (!Auth::can('reports.view')) {
            flash('error', 'Você não tem permissão para acessar Relatórios.');
            $this->redirect('dashboard');
            return;
        }

        $filters = $this->currentFilters();
        $hasFilters = count(array_filter(array_diff_key($filters, ['period' => true]))) > 0;
        $leads = $this->leadModel->filtered($filters);
        $previewCount = count($leads);
        $report = $this->buildDashboard($leads, $filters);
        $preview = array_slice($leads, 0, 50);

        $aiReportAvailable = false;
        try {
            $aiReportAvailable = (new GeminiService())->isConfigured();
        } catch (Throwable $e) {
            // A indisponibilidade da IA nunca pode impedir a leitura dos relatórios.
        }

        $userModel = new User();

        $this->view('reports/index', [
            'pageTitle'    => 'Relatórios',
            'filters'      => $filters,
            'states'       => brazilian_states(),
            'users'        => $userModel->allActive(),
            'preview'      => $preview,
            'previewCount' => $previewCount,
            'hasFilters'   => $hasFilters,
            'periodLabel'  => $this->periodLabel($filters),
            'kpis'         => $report['kpis'],
            'statusChart'  => $report['statusChart'],
            'sourceChart'  => $report['sourceChart'],
            'stateChart'   => $report['stateChart'],
            'lossChart'    => $report['lossChart'],
            'leadSeries'   => $report['leadSeries'],
            'revenueSeries' => $report['revenueSeries'],
            'performance'  => $report['performance'],
            'sourcePerformance' => $report['sourcePerformance'],
            'whatsappSummary' => $this->whatsappSummary(),
            'taskSummary'  => $this->taskSummary(),
            'aiReportAvailable' => $aiReportAvailable,
        ]);
    }

    /** Endpoint opcional: envia somente agregados do relatório atual para a IA. */
    public function analyze(): void
    {
        $this->requireLogin();
        if (!Auth::can('reports.view')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para analisar relatórios.'], 403);
        }
        Csrf::verifyRequest();

        $filters = $this->currentFilters();
        $report = $this->buildDashboard($this->leadModel->filtered($filters), $filters);
        $snapshot = $report['aiSnapshot'];
        $snapshot['operacao'] = [
            'whatsapp' => $this->whatsappSummary(),
            'tarefas' => $this->taskSummary(),
        ];

        try {
            $service = new GeminiService();
            if (!$service->isConfigured()) {
                $this->json(['success' => false, 'message' => 'A análise por IA é opcional e ainda não foi configurada em Configurações.'], 422);
            }
            $question = trim((string) $this->input('question', ''));
            $result = $service->analyzeReport($snapshot, $question);
            $this->json($result, !empty($result['success']) ? 200 : 502);
        } catch (Throwable $e) {
            error_log('ReportController::analyze - falha na análise por IA: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Não foi possível analisar o relatório agora.'], 502);
        }
    }

    /** Consolida indicadores sem expor dados pessoais dos leads. */
    private function buildDashboard(array $leads, array $filters): array
    {
        $lostStatuses = ['perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'nao_responde', 'bloqueou', 'duplicado'];
        $statusChart = [];
        $sourceChart = [];
        $stateChart = [];
        $lossChart = [];
        $leadDates = [];
        $revenueDates = [];
        $performance = [];
        $sourcePerformance = [];
        $kpis = [
            'total' => 0, 'contacted' => 0, 'closed' => 0, 'lost' => 0,
            'active' => 0, 'revenue' => 0.0, 'hot' => 0, 'unassigned' => 0,
        ];

        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?: 'sem_status');
            $source = (string) ($lead['source'] ?: 'outros');
            $state = (string) ($lead['state'] ?: 'N/D');
            $consultantKey = (int) ($lead['assigned_to'] ?? 0);
            $consultantName = (string) ($lead['assigned_name'] ?: 'Sem responsável');
            $closed = $status === 'fechado';
            $lost = in_array($status, $lostStatuses, true);
            $revenue = $closed ? (float) ($lead['closed_value'] ?? 0) : 0.0;

            $kpis['total']++;
            $kpis['contacted'] += !empty($lead['last_contact_at']) ? 1 : 0;
            $kpis['closed'] += $closed ? 1 : 0;
            $kpis['lost'] += $lost ? 1 : 0;
            $kpis['revenue'] += $revenue;
            $kpis['hot'] += in_array($lead['temperature'] ?? '', ['quente', 'muito_quente'], true) ? 1 : 0;
            $kpis['unassigned'] += $consultantKey === 0 ? 1 : 0;

            $statusChart[$status] = ($statusChart[$status] ?? 0) + 1;
            $sourceChart[$source] = ($sourceChart[$source] ?? 0) + 1;
            $stateChart[$state] = ($stateChart[$state] ?? 0) + 1;

            $createdDay = substr((string) ($lead['created_at'] ?? ''), 0, 10);
            if ($this->isValidDate($createdDay) && $createdDay !== '') {
                $leadDates[$createdDay] = ($leadDates[$createdDay] ?? 0) + 1;
            }
            $closedDay = substr((string) ($lead['closed_at'] ?? ''), 0, 10);
            if ($closed && $this->isValidDate($closedDay) && $closedDay !== '') {
                $revenueDates[$closedDay] = ($revenueDates[$closedDay] ?? 0.0) + $revenue;
            }

            if (!isset($performance[$consultantKey])) {
                $performance[$consultantKey] = [
                    'name' => $consultantName, 'total' => 0, 'contacted' => 0,
                    'fechado' => 0, 'perdido' => 0, 'revenue' => 0.0, 'score_sum' => 0,
                ];
            }
            $performance[$consultantKey]['total']++;
            $performance[$consultantKey]['contacted'] += !empty($lead['last_contact_at']) ? 1 : 0;
            $performance[$consultantKey]['fechado'] += $closed ? 1 : 0;
            $performance[$consultantKey]['perdido'] += $lost ? 1 : 0;
            $performance[$consultantKey]['revenue'] += $revenue;
            $performance[$consultantKey]['score_sum'] += (int) ($lead['lead_score'] ?? 0);

            if (!isset($sourcePerformance[$source])) {
                $sourcePerformance[$source] = ['total' => 0, 'fechado' => 0, 'perdido' => 0, 'revenue' => 0.0];
            }
            $sourcePerformance[$source]['total']++;
            $sourcePerformance[$source]['fechado'] += $closed ? 1 : 0;
            $sourcePerformance[$source]['perdido'] += $lost ? 1 : 0;
            $sourcePerformance[$source]['revenue'] += $revenue;

            if ($lost) {
                $reason = trim((string) ($lead['loss_reason_name'] ?? '')) ?: 'Não informado';
                $lossChart[$reason] = ($lossChart[$reason] ?? 0) + 1;
            }
        }

        $kpis['active'] = max(0, $kpis['total'] - $kpis['closed'] - $kpis['lost']);
        $kpis['conversion_rate'] = $kpis['total'] > 0 ? round(($kpis['closed'] / $kpis['total']) * 100, 1) : 0.0;
        $kpis['contact_rate'] = $kpis['total'] > 0 ? round(($kpis['contacted'] / $kpis['total']) * 100, 1) : 0.0;
        $kpis['average_ticket'] = $kpis['closed'] > 0 ? $kpis['revenue'] / $kpis['closed'] : 0.0;

        arsort($statusChart);
        arsort($sourceChart);
        arsort($stateChart);
        arsort($lossChart);
        $stateChart = array_slice($stateChart, 0, 10, true);
        $lossChart = array_slice($lossChart, 0, 8, true);

        foreach ($performance as &$row) {
            $row['conversion_rate'] = $row['total'] > 0 ? round(($row['fechado'] / $row['total']) * 100, 1) : 0.0;
            $row['contact_rate'] = $row['total'] > 0 ? round(($row['contacted'] / $row['total']) * 100, 1) : 0.0;
            $row['avg_score'] = $row['total'] > 0 ? round($row['score_sum'] / $row['total'], 1) : 0.0;
            $row['average_ticket'] = $row['fechado'] > 0 ? $row['revenue'] / $row['fechado'] : 0.0;
        }
        unset($row);
        usort($performance, fn(array $a, array $b) => ($b['revenue'] <=> $a['revenue']) ?: ($b['total'] <=> $a['total']));

        foreach ($sourcePerformance as &$row) {
            $row['conversion_rate'] = $row['total'] > 0 ? round(($row['fechado'] / $row['total']) * 100, 1) : 0.0;
            $row['average_ticket'] = $row['fechado'] > 0 ? $row['revenue'] / $row['fechado'] : 0.0;
        }
        unset($row);
        uasort($sourcePerformance, fn(array $a, array $b) => $b['total'] <=> $a['total']);

        // A IA recebe posições anonimizadas da equipe; os nomes seguem visíveis
        // apenas para o usuário autorizado na tabela deste painel.
        $aiTeam = [];
        foreach (array_values(array_slice($performance, 0, 12)) as $index => $row) {
            $aiTeam[] = [
                'grupo' => 'Responsável ' . ($index + 1), 'leads' => $row['total'],
                'contatados' => $row['contacted'], 'fechados' => $row['fechado'],
                'conversao_pct' => $row['conversion_rate'], 'receita' => $row['revenue'],
            ];
        }

        return [
            'kpis' => $kpis,
            'statusChart' => $statusChart,
            'sourceChart' => $sourceChart,
            'stateChart' => $stateChart,
            'lossChart' => $lossChart,
            'leadSeries' => $this->fillDateSeries($leadDates, $filters),
            'revenueSeries' => $this->fillDateSeries($revenueDates, $filters),
            'performance' => $performance,
            'sourcePerformance' => $sourcePerformance,
            'aiSnapshot' => [
                'periodo' => $this->periodLabel($filters),
                'indicadores' => $kpis,
                'funil_por_status' => $statusChart,
                'origens' => $sourcePerformance,
                'equipe' => $aiTeam,
                'perdas' => $lossChart,
            ],
        ];
    }

    /** Série contínua para os gráficos; sem período selecionado, mostra os últimos 30 dias. */
    private function fillDateSeries(array $values, array $filters): array
    {
        $end = !empty($filters['date_to']) ? new DateTimeImmutable($filters['date_to']) : new DateTimeImmutable('today');
        $start = !empty($filters['date_from']) ? new DateTimeImmutable($filters['date_from']) : $end->modify('-29 days');
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diff($end)->days > 89) {
            $start = $end->modify('-89 days');
        }

        $series = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $series[] = ['day' => $key, 'total' => (float) ($values[$key] ?? 0)];
        }
        return $series;
    }

    private function periodLabel(array $filters): string
    {
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            return 'Todo o histórico';
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            return format_date($filters['date_from']) . ' a ' . format_date($filters['date_to']);
        }
        return !empty($filters['date_from']) ? 'A partir de ' . format_date($filters['date_from']) : 'Até ' . format_date($filters['date_to']);
    }

    /** Visão operacional independente do filtro de leads, para não misturar os conceitos. */
    private function taskSummary(): ?array
    {
        try {
            require_once APP_PATH . '/core/Database.php';
            $db = Database::getInstance();
            $rows = $db->query('SELECT status, COUNT(*) AS total FROM tasks GROUP BY status')->fetchAll();
            $byStatus = [];
            foreach ($rows as $row) {
                $byStatus[(string) $row['status']] = (int) $row['total'];
            }
            $overdue = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status NOT IN ('concluida', 'cancelada') AND due_at IS NOT NULL AND due_at < NOW()")
                ->fetch();
            $total = array_sum($byStatus);
            return [
                'total' => $total,
                'open' => $total - (int) ($byStatus['concluida'] ?? 0) - (int) ($byStatus['cancelada'] ?? 0),
                'completed' => (int) ($byStatus['concluida'] ?? 0),
                'overdue' => (int) ($overdue['total'] ?? 0),
                'by_status' => $byStatus,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Resumo do Atendimento WhatsApp (Fase 8) para o painel de indicadores dos Relatórios. Independente dos filtros de lead (é uma visão geral da operação). */
    private function whatsappSummary(): ?array
    {
        try {
            require_once APP_PATH . '/core/Database.php';
            $db = Database::getInstance();
            $conversations = $db->query('SELECT COUNT(*) total, SUM(unread_count>0) unread, SUM(assigned_to IS NULL) unassigned, SUM(lead_id IS NOT NULL) linked FROM evolution_conversation_links')->fetch();
            $messages = $db->query("SELECT SUM(from_me=0) recebidas, SUM(from_me=1 AND is_private=0) enviadas FROM evolution_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();
            return [
                'conversations' => (int) ($conversations['total'] ?? 0),
                'unread'        => (int) ($conversations['unread'] ?? 0),
                'unassigned'    => (int) ($conversations['unassigned'] ?? 0),
                'linked'        => (int) ($conversations['linked'] ?? 0),
                'received_30d'  => (int) ($messages['recebidas'] ?? 0),
                'sent_30d'      => (int) ($messages['enviadas'] ?? 0),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Tela de personalização do relatório (logo, cor, textos e colunas) */
    public function customize(): void
    {
        $this->requireLogin();
        if (!$this->requireCustomizePermission()) {
            return;
        }

        $settings = $this->settingModel->allAsMap();

        $this->view('reports/customize', [
            'pageTitle'       => 'Personalizar Relatório',
            'settings'        => $settings,
            'columnCatalog'   => $this->columnCatalog(),
            'selectedColumns' => $this->selectedColumns($settings),
        ]);
    }

    public function customizeUpdate(): void
    {
        $this->requireLogin();
        if (!$this->requireCustomizePermission()) {
            return;
        }
        Csrf::verifyRequest();

        $catalog = $this->columnCatalog();
        $postedColumns = (array) $this->input('columns', []);
        // Preserva a ORDEM em que as colunas aparecem no catálogo (mais previsível
        // para o usuário do que a ordem de chegada dos checkboxes marcados),
        // filtrando qualquer chave inválida vinda do POST.
        $selectedColumns = array_values(array_filter(
            array_keys($catalog),
            fn($key) => in_array($key, $postedColumns, true)
        ));
        if (empty($selectedColumns)) {
            $selectedColumns = self::DEFAULT_COLUMNS;
        }

        $color = trim((string) $this->input('report_primary_color', self::DEFAULT_COLOR));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = self::DEFAULT_COLOR;
        }

        $data = [
            self::SETTING_COLOR   => $color,
            self::SETTING_CNPJ    => trim((string) $this->input('report_cnpj', '')),
            self::SETTING_ADDRESS => trim((string) $this->input('report_address', '')),
            self::SETTING_PHONE   => trim((string) $this->input('report_phone', '')),
            self::SETTING_FOOTER  => trim((string) $this->input('report_footer_text', '')),
            self::SETTING_COLUMNS => json_encode($selectedColumns, JSON_UNESCAPED_UNICODE),
        ];

        $logo = $this->handleLogoUpload();
        if ($logo !== null) {
            $data[self::SETTING_LOGO] = $logo;
        }
        // "Remover logo do relatório" (usa o logo padrão do sistema)
        if ($this->input('remove_report_logo') === '1') {
            $data[self::SETTING_LOGO] = '';
        }

        $this->settingModel->setMany($data);
        log_activity('relatorio_personalizado', 'Configurações de personalização de relatórios atualizadas.');

        flash('success', 'Personalização do relatório salva com sucesso.');
        $this->redirect('relatorios/personalizar');
    }

    /** Upload simples do logo específico do relatório (mesmo padrão de SettingController) */
    private function handleLogoUpload(): ?string
    {
        if (empty($_FILES['report_logo']) || $_FILES['report_logo']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($_FILES['report_logo']['tmp_name']);

        if (!isset($allowed[$mime]) || $_FILES['report_logo']['size'] > 1 * 1024 * 1024) {
            flash('error', 'Arquivo de logo do relatório inválido (use JPG/PNG/WEBP até 1MB). As demais configurações foram salvas.');
            return null;
        }

        if (!is_dir(UPLOADS_PATH)) {
            @mkdir(UPLOADS_PATH, 0755, true);
        }

        $filename = 'report_logo_' . time() . '.' . $allowed[$mime];
        $destination = UPLOADS_PATH . '/' . $filename;

        if (!move_uploaded_file($_FILES['report_logo']['tmp_name'], $destination)) {
            return null;
        }

        return UPLOADS_URL . '/' . $filename;
    }

    /** Exportação CSV nativa (fputcsv) */
    public function exportCsv(): void
    {
        $this->requireLogin();
        if (!$this->requireExportPermission()) {
            return;
        }

        $filters = $this->currentFilters();
        $leads = $this->leadModel->filtered($filters);
        $columns = $this->selectedColumns($this->settingModel->allAsMap());

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="relatorio-leads-' . date('Y-m-d-His') . '.csv"');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 para o Excel abrir acentuação corretamente
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $this->columnHeaders($columns), ';');
        foreach ($leads as $lead) {
            fputcsv($out, $this->rowValues($lead, $columns), ';');
        }
        fclose($out);
        exit;
    }

    /** Exportação "Excel" leve: HTML table com Content-Type application/vnd.ms-excel (sem libs externas) */
    public function exportExcel(): void
    {
        $this->requireLogin();
        if (!$this->requireExportPermission()) {
            return;
        }

        $filters = $this->currentFilters();
        $leads = $this->leadModel->filtered($filters);
        $columns = $this->selectedColumns($this->settingModel->allAsMap());

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="relatorio-leads-' . date('Y-m-d-His') . '.xls"');

        echo "\xEF\xBB\xBF";
        echo '<table border="1"><thead><tr>';
        foreach ($this->columnHeaders($columns) as $h) {
            echo '<th>' . e($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($leads as $lead) {
            echo '<tr>';
            foreach ($this->rowValues($lead, $columns) as $val) {
                echo '<td>' . e((string) $val) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        exit;
    }

    /** Versão para impressão (o usuário usa Ctrl+P / window.print() -> "Salvar como PDF" do navegador) */
    public function printView(): void
    {
        $this->requireLogin();
        if (!$this->requireExportPermission()) {
            return;
        }

        $filters = $this->currentFilters();
        $leads = $this->leadModel->filtered($filters);
        $settings = $this->settingModel->allAsMap();
        $columns = $this->selectedColumns($settings);

        $color = $settings[self::SETTING_COLOR] ?? self::DEFAULT_COLOR;
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)) {
            $color = self::DEFAULT_COLOR;
        }

        $this->view('reports/print', [
            'pageTitle'    => 'Relatório de Leads',
            'leads'        => $leads,
            'filters'      => $filters,
            'generatedAt'  => date('d/m/Y H:i'),
            'generatedBy'  => Auth::user()['name'] ?? '',
            'columns'      => $columns,
            'columnLabels' => $this->columnCatalog(),
            'reportLogo'   => $settings[self::SETTING_LOGO] ?? ($settings['company_logo'] ?? ''),
            'reportColor'  => $color,
            'reportCnpj'   => $settings[self::SETTING_CNPJ] ?? '',
            'reportAddress' => $settings[self::SETTING_ADDRESS] ?? '',
            'reportPhone'  => $settings[self::SETTING_PHONE] ?? '',
            'reportFooter' => $settings[self::SETTING_FOOTER] ?? '',
        ], null);
    }

    /** Catálogo completo de colunas disponíveis para o relatório: chave => rótulo */
    private function columnCatalog(): array
    {
        return [
            'lead_code'        => 'Código',
            'name'             => 'Nome',
            'phone'            => 'Telefone',
            'whatsapp'         => 'WhatsApp',
            'email'            => 'E-mail',
            'cpf'              => 'CPF',
            'city'             => 'Cidade',
            'state'            => 'UF',
            'source'           => 'Origem',
            'campaign'         => 'Campanha',
            'interest'         => 'Interesse',
            'desired_value'    => 'Valor desejado',
            'closed_value'     => 'Valor realizado',
            'income_range'     => 'Faixa de renda',
            'profession'       => 'Profissão',
            'status'           => 'Status',
            'assigned_name'    => 'Responsável',
            'lead_score'       => 'Lead Score',
            'temperature'      => 'Temperatura',
            'priority'         => 'Prioridade',
            'created_at'       => 'Data de cadastro',
            'closed_at'        => 'Data do fechamento',
            'last_contact_at'  => 'Último contato',
            'next_contact_at'  => 'Próximo contato',
        ];
    }

    /** Lê a seleção de colunas salva em settings (JSON), com fallback para o padrão */
    private function selectedColumns(array $settings): array
    {
        $catalog = $this->columnCatalog();
        $raw = $settings[self::SETTING_COLUMNS] ?? null;

        $columns = self::DEFAULT_COLUMNS;
        if ($raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $columns = $decoded;
            }
        }

        // Mantém só chaves válidas do catálogo, na ordem salva
        $columns = array_values(array_filter($columns, fn($key) => isset($catalog[$key])));

        return empty($columns) ? self::DEFAULT_COLUMNS : $columns;
    }

    private function columnHeaders(array $columns): array
    {
        $catalog = $this->columnCatalog();
        return array_map(fn($key) => $catalog[$key] ?? $key, $columns);
    }

    /** Retorna o valor de uma coluna específica já formatado para exibição/exportação */
    private function columnValue(array $lead, string $key): string
    {
        switch ($key) {
            case 'lead_code':
                return $lead['lead_code'] ?? '';
            case 'name':
                return $lead['name'] ?: '';
            case 'phone':
                return format_phone($lead['phone'] ?? null);
            case 'whatsapp':
                return format_phone($lead['whatsapp'] ?? null);
            case 'email':
                return $lead['email'] ?: '';
            case 'cpf':
                return format_cpf($lead['cpf'] ?? null);
            case 'city':
                return $lead['city'] ?: '';
            case 'state':
                return $lead['state'] ?: '';
            case 'source':
                return source_label($lead['source'] ?? null);
            case 'campaign':
                return $lead['campaign'] ?: '';
            case 'interest':
                return interest_label($lead['interest'] ?? null);
            case 'desired_value':
                return $lead['desired_value'] !== null ? number_format((float) $lead['desired_value'], 2, ',', '.') : '';
            case 'closed_value':
                return $lead['closed_value'] !== null ? number_format((float) $lead['closed_value'], 2, ',', '.') : '';
            case 'income_range':
                return $lead['income_range'] ?: '';
            case 'profession':
                return $lead['profession'] ?: '';
            case 'status':
                return status_label($lead['status'] ?? null);
            case 'assigned_name':
                return $lead['assigned_name'] ?? '';
            case 'lead_score':
                return (string) ($lead['lead_score'] ?? 0);
            case 'temperature':
                return $lead['temperature'] ?: '';
            case 'priority':
                return $lead['priority'] ?: '';
            case 'created_at':
                return format_date($lead['created_at'] ?? null, true);
            case 'closed_at':
                return format_date($lead['closed_at'] ?? null, true);
            case 'last_contact_at':
                return format_date($lead['last_contact_at'] ?? null, true);
            case 'next_contact_at':
                return format_date($lead['next_contact_at'] ?? null, true);
            default:
                return '';
        }
    }

    private function rowValues(array $lead, array $columns): array
    {
        return array_map(fn($key) => $this->columnValue($lead, $key), $columns);
    }
}
