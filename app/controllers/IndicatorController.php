<?php
/**
 * Indicadores comerciais e operacionais: geografia, captação, conversão,
 * receita e velocidade de atendimento. Todos os números partem de registros
 * já existentes no CRM; não há indicadores estimados.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';

class IndicatorController extends Controller
{
    private PDO $db;

    private const CLOSED_STATUSES = ['fechado'];
    private const LOST_STATUSES = ['perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'nao_responde', 'bloqueou', 'duplicado'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->requireLogin();

        $range = $this->currentRange();
        [$capturedConditions, $capturedParams] = $this->dateConditions('l.created_at', $range, 'captured');
        $captureWhere = $this->where($capturedConditions);

        // ---- Mapa: captação e conversão por UF no mesmo período selecionado ----
        $stmt = $this->db->prepare(
            "SELECT l.state, COUNT(*) AS total,
                    SUM(CASE WHEN l.status = 'fechado' THEN 1 ELSE 0 END) AS fechados
             FROM leads l {$captureWhere}" . ($captureWhere ? ' AND ' : ' WHERE ') . "l.state IS NOT NULL AND l.state <> ''
             GROUP BY l.state"
        );
        $stmt->execute($capturedParams);
        $byState = [];
        $maxTotal = 0;
        foreach ($stmt->fetchAll() as $row) {
            $total = (int) $row['total'];
            $fechados = (int) $row['fechados'];
            $byState[$row['state']] = [
                'total' => $total,
                'fechados' => $fechados,
                'conversion' => $total > 0 ? round(($fechados / $total) * 100, 1) : 0.0,
            ];
            $maxTotal = max($maxTotal, $total);
        }

        // ---- Heatmap: dia da semana x hora da captação ----
        $stmt = $this->db->prepare(
            "SELECT DAYOFWEEK(l.created_at) AS dow, HOUR(l.created_at) AS hr, COUNT(*) AS total
             FROM leads l {$captureWhere}
             GROUP BY DAYOFWEEK(l.created_at), HOUR(l.created_at)"
        );
        $stmt->execute($capturedParams);
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $maxHeat = 0;
        foreach ($stmt->fetchAll() as $row) {
            $dow = (int) $row['dow'] - 1;
            $hr = (int) $row['hr'];
            $total = (int) $row['total'];
            $heatmap[$dow][$hr] = $total;
            $maxHeat = max($maxHeat, $total);
        }

        // Subconsulta compartilhada com o módulo de SLA. Primeiro contato é
        // ligação, WhatsApp ou registro manual de contato — não é aproximação.
        $firstContactSql = "
            SELECT lh.lead_id, MIN(lh.created_at) AS first_contact_at
            FROM lead_history lh
            WHERE lh.type IN ('contato', 'whatsapp', 'ligacao')
            GROUP BY lh.lead_id
        ";

        // ---- KPIs do funil de captação ----
        $summaryStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN l.status = 'fechado' THEN 1 ELSE 0 END) AS closed_total,
                    SUM(CASE WHEN l.status = 'em_negociacao' THEN 1 ELSE 0 END) AS negotiation_total,
                    SUM(CASE WHEN l.last_contact_at IS NOT NULL THEN 1 ELSE 0 END) AS contacted_total
             FROM leads l {$captureWhere}"
        );
        $summaryStmt->execute($capturedParams);
        $summary = $summaryStmt->fetch() ?: [];
        $captured = (int) ($summary['total'] ?? 0);
        $closedFromCohort = (int) ($summary['closed_total'] ?? 0);
        $negotiation = (int) ($summary['negotiation_total'] ?? 0);
        $contacted = (int) ($summary['contacted_total'] ?? 0);

        $slaStmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at)) AS avg_minutes,
                    COUNT(fc.lead_id) AS contacted_total,
                    SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at) <= 15 THEN 1 ELSE 0 END) AS within_15
             FROM leads l
             INNER JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
             {$captureWhere}"
        );
        $slaStmt->execute($capturedParams);
        $sla = $slaStmt->fetch() ?: [];
        $slaContacts = (int) ($sla['contacted_total'] ?? 0);
        $averageFirstContact = $sla['avg_minutes'] !== null ? round((float) $sla['avg_minutes'], 1) : null;
        $within15Rate = $slaContacts > 0 ? round(((int) ($sla['within_15'] ?? 0) / $slaContacts) * 100, 1) : 0.0;

        $waitingStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
             FROM leads l
             LEFT JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
             {$captureWhere}" . ($captureWhere ? ' AND ' : ' WHERE ') . "fc.lead_id IS NULL
                AND l.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado')"
        );
        $waitingStmt->execute($capturedParams);
        $waitingOver24h = (int) (($waitingStmt->fetch() ?: [])['total'] ?? 0);

        $followupStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
             FROM leads l {$captureWhere}" . ($captureWhere ? ' AND ' : ' WHERE ') . "l.next_contact_at IS NOT NULL
                AND l.next_contact_at < NOW()
                AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado')"
        );
        $followupStmt->execute($capturedParams);
        $overdueFollowups = (int) (($followupStmt->fetch() ?: [])['total'] ?? 0);

        // Receita tem como referência o fechamento, não o cadastro do lead.
        [$closedConditions, $closedParams] = $this->dateConditions('l.closed_at', $range, 'closed');
        $closedWhere = $this->where($closedConditions);
        $revenueStmt = $this->db->prepare(
            "SELECT COUNT(*) AS deals, COALESCE(SUM(COALESCE(l.closed_value, 0)), 0) AS revenue
             FROM leads l {$closedWhere}" . ($closedWhere ? ' AND ' : ' WHERE ') . "l.status = 'fechado'"
        );
        $revenueStmt->execute($closedParams);
        $revenueData = $revenueStmt->fetch() ?: [];
        $dealsClosed = (int) ($revenueData['deals'] ?? 0);
        $revenue = (float) ($revenueData['revenue'] ?? 0);

        // ---- Séries acionáveis: captação diária e desempenho por origem ----
        $chartRange = $this->chartRange($range);
        [$chartConditions, $chartParams] = $this->dateConditions('l.created_at', $chartRange, 'chart');
        $chartWhere = $this->where($chartConditions);
        $trendStmt = $this->db->prepare(
            "SELECT DATE(l.created_at) AS day, COUNT(*) AS total
             FROM leads l {$chartWhere}
             GROUP BY DATE(l.created_at)
             ORDER BY day ASC"
        );
        $trendStmt->execute($chartParams);
        $trendRows = $trendStmt->fetchAll();
        $captureTrend = $this->fillDays($trendRows, $chartRange);

        $sourceStmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(l.source, ''), 'outros') AS source,
                    COUNT(*) AS total,
                    SUM(CASE WHEN l.status = 'fechado' THEN 1 ELSE 0 END) AS closed_total
             FROM leads l {$captureWhere}
             GROUP BY COALESCE(NULLIF(l.source, ''), 'outros')
             ORDER BY total DESC
             LIMIT 10"
        );
        $sourceStmt->execute($capturedParams);
        $sourcePerformance = [];
        foreach ($sourceStmt->fetchAll() as $row) {
            $total = (int) $row['total'];
            $sourcePerformance[] = [
                'source' => (string) $row['source'],
                'total' => $total,
                'closed' => (int) $row['closed_total'],
                'conversion' => $total > 0 ? round(((int) $row['closed_total'] / $total) * 100, 1) : 0.0,
            ];
        }

        $previousCaptured = $this->previousCaptured($range);
        $taskOverdue = $this->overdueTasks();
        $canViewAll = Auth::hasRole(['admin', 'supervisor']);

        // Leaflet é carregado apenas nesta página; Chart.js já é global do layout.
        $pageStyles = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
            . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">';

        $this->view('indicators/index', [
            'pageTitle' => 'Indicadores',
            'range' => $range,
            'byState' => $byState,
            'maxTotal' => $maxTotal,
            'heatmap' => $heatmap,
            'maxHeat' => $maxHeat,
            'captureTrend' => $captureTrend,
            'sourcePerformance' => $sourcePerformance,
            'kpis' => [
                'captured' => $captured,
                'previous_captured' => $previousCaptured,
                'contact_rate' => $captured > 0 ? round(($contacted / $captured) * 100, 1) : 0.0,
                'conversion_rate' => $captured > 0 ? round(($closedFromCohort / $captured) * 100, 1) : 0.0,
                'negotiation' => $negotiation,
                'deals_closed' => $dealsClosed,
                'revenue' => $revenue,
                'average_first_contact' => $averageFirstContact,
                'within_15_rate' => $within15Rate,
                'waiting_over_24h' => $waitingOver24h,
                'overdue_followups' => $overdueFollowups,
                'overdue_tasks' => $taskOverdue,
            ],
            'kpiLinks' => $this->kpiLinks($range, $canViewAll),
            'pageStyles' => $pageStyles,
            'geoJsonUrl' => asset('data/brazil-states.geojson'),
            'leadMapBaseUrl' => $this->leadUrl($range, [], $canViewAll),
        ]);
    }

    private function currentRange(): array
    {
        $period = (string) $this->input('period', 'all');
        $allowed = ['all', 'today', '7d', '30d', 'month', 'quarter', 'custom'];
        if (!in_array($period, $allowed, true)) {
            $period = 'all';
        }
        $from = (string) $this->input('date_from', '');
        $to = (string) $this->input('date_to', '');
        if ($period === 'all' && ($from !== '' || $to !== '')) {
            $period = 'custom';
        }

        $today = new DateTimeImmutable('today');
        if ($period === 'today') {
            $from = $to = $today->format('Y-m-d');
        } elseif ($period === '7d') {
            $from = $today->modify('-6 days')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        } elseif ($period === '30d') {
            $from = $today->modify('-29 days')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        } elseif ($period === 'month') {
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        } elseif ($period === 'quarter') {
            $startMonth = ((int) floor(((int) $today->format('n') - 1) / 3) * 3) + 1;
            $from = $today->format('Y') . '-' . str_pad((string) $startMonth, 2, '0', STR_PAD_LEFT) . '-01';
            $to = $today->format('Y-m-d');
        } elseif ($period === 'all') {
            $from = $to = '';
        }

        if (!$this->validDate($from)) {
            $from = '';
        }
        if (!$this->validDate($to)) {
            $to = '';
        }
        $label = (!$from && !$to) ? 'Todo o histórico' : (($from && $to) ? format_date($from) . ' a ' . format_date($to) : ($from ? 'A partir de ' . format_date($from) : 'Até ' . format_date($to)));
        return ['period' => $period, 'date_from' => $from, 'date_to' => $to, 'label' => $label];
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Condições indexáveis por intervalo [início, dia seguinte ao fim). */
    private function dateConditions(string $column, array $range, string $prefix): array
    {
        $conditions = [];
        $params = [];
        if (!empty($range['date_from'])) {
            $conditions[] = "{$column} >= :{$prefix}_from";
            $params[":{$prefix}_from"] = $range['date_from'] . ' 00:00:00';
        }
        if (!empty($range['date_to'])) {
            $conditions[] = "{$column} < :{$prefix}_to";
            $params[":{$prefix}_to"] = (new DateTimeImmutable($range['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        return [$conditions, $params];
    }

    private function where(array $conditions): string
    {
        return $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }

    /** Para o gráfico, sem filtro selecionado exibimos os 30 dias mais recentes. */
    private function chartRange(array $range): array
    {
        if (empty($range['date_from']) && empty($range['date_to'])) {
            $today = new DateTimeImmutable('today');
            return ['date_from' => $today->modify('-29 days')->format('Y-m-d'), 'date_to' => $today->format('Y-m-d')];
        }
        $from = !empty($range['date_from']) ? new DateTimeImmutable($range['date_from']) : (new DateTimeImmutable($range['date_to']))->modify('-29 days');
        $to = !empty($range['date_to']) ? new DateTimeImmutable($range['date_to']) : new DateTimeImmutable('today');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diff($to)->days > 89) {
            $from = $to->modify('-89 days');
        }
        return ['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')];
    }

    private function fillDays(array $rows, array $range): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day']] = (int) $row['total'];
        }
        $items = [];
        $start = new DateTimeImmutable($range['date_from']);
        $end = new DateTimeImmutable($range['date_to']);
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $items[] = ['day' => $key, 'total' => $map[$key] ?? 0];
        }
        return $items;
    }

    private function previousCaptured(array $range): ?int
    {
        if (empty($range['date_from']) || empty($range['date_to'])) {
            return null;
        }
        $from = new DateTimeImmutable($range['date_from']);
        $to = new DateTimeImmutable($range['date_to']);
        $days = $from->diff($to)->days + 1;
        $previous = [
            'date_from' => $from->modify('-' . $days . ' days')->format('Y-m-d'),
            'date_to' => $from->modify('-1 day')->format('Y-m-d'),
        ];
        [$conditions, $params] = $this->dateConditions('l.created_at', $previous, 'previous');
        $stmt = $this->db->prepare('SELECT COUNT(*) AS total FROM leads l ' . $this->where($conditions));
        $stmt->execute($params);
        return (int) (($stmt->fetch() ?: [])['total'] ?? 0);
    }

    private function overdueTasks(): ?int
    {
        try {
            $row = $this->db->query("SELECT COUNT(*) AS total FROM tasks WHERE due_at IS NOT NULL AND due_at < NOW() AND status NOT IN ('concluida', 'cancelada')")->fetch();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function leadUrl(array $range, array $extra, bool $canViewAll): string
    {
        $params = $extra;
        if (!empty($range['date_from'])) {
            $params['date_from'] = $range['date_from'];
        }
        if (!empty($range['date_to'])) {
            $params['date_to'] = $range['date_to'];
        }
        if ($canViewAll) {
            $params['view'] = 'all';
        }
        return url('leads') . ($params ? '?' . http_build_query($params) : '');
    }

    private function kpiLinks(array $range, bool $canViewAll): array
    {
        return [
            'captured' => $this->leadUrl($range, [], $canViewAll),
            'negotiation' => $this->leadUrl($range, ['status' => 'em_negociacao'], $canViewAll),
            'conversion' => $this->leadUrl($range, ['status' => 'fechado'], $canViewAll),
            'revenue' => $this->closedLeadUrl($range, $canViewAll),
            'first_contact' => url('sla'),
            'fast_sla' => url('sla'),
            'waiting' => $this->leadUrl($range, ['sem_contato_dias' => 1], $canViewAll),
            'followups' => $this->leadUrl($range, ['vencidos' => 1], $canViewAll),
            'tasks' => url('tarefas?overdue=1' . ($canViewAll ? '&tab=all' : '')),
        ];
    }

    /** Abre exatamente os fechamentos usados no KPI de receita (por closed_at). */
    private function closedLeadUrl(array $range, bool $canViewAll): string
    {
        $params = ['status' => 'fechado'];
        if (!empty($range['date_from'])) {
            $params['closed_from'] = $range['date_from'];
        }
        if (!empty($range['date_to'])) {
            $params['closed_to'] = $range['date_to'];
        }
        if ($canViewAll) {
            $params['view'] = 'all';
        }
        return url('leads?' . http_build_query($params));
    }
}
