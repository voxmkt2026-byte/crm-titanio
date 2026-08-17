<?php
/**
 * app/helpers/insights.php
 * Motor de insights automáticos (Fase 2) exibido no Dashboard.
 * Gera comparações período a período, melhor DDD/origem/vendedor por
 * conversão e alertas de queda de conversão, além dos insights simples
 * já calculados na Fase 1 (top estado, sem contato, taxa de conversão).
 *
 * Todas as consultas usam PDO com prepared statements.
 */

if (!function_exists('generate_insights')) {
    /**
     * @param PDO   $db    Conexão PDO (Database::getInstance())
     * @param array $base  Insights simples já calculados (Fase 1), para reaproveitar.
     *                     Cada item pode ser uma string simples ou um array
     *                     ['text' => ..., 'url' => ...] (Fase 5) quando o insight
     *                     representa uma lista específica de leads acionável.
     * @return array Lista de insights: ['type' => 'up'|'down'|'info'|'warning', 'icon' => string, 'text' => string, 'url' => ?string]
     */
    function generate_insights(PDO $db, array $base = []): array
    {
        $insights = [];

        foreach ($base as $item) {
            $text = is_array($item) ? ($item['text'] ?? '') : $item;
            $url = is_array($item) ? ($item['url'] ?? null) : null;
            $insights[] = ['type' => 'info', 'icon' => 'fa-lightbulb', 'text' => $text, 'url' => $url];
        }

        // ---- Comparação de volume de leads: semana atual x semana anterior ----
        $stmt = $db->query(
            "SELECT
                SUM(CASE WHEN YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1) THEN 1 ELSE 0 END) AS atual,
                SUM(CASE WHEN YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1) - 1 THEN 1 ELSE 0 END) AS anterior
             FROM leads"
        );
        $row = $stmt->fetch();
        $atual = (int) ($row['atual'] ?? 0);
        $anterior = (int) ($row['anterior'] ?? 0);
        if ($anterior > 0) {
            $variacao = round((($atual - $anterior) / $anterior) * 100, 1);
            if ($variacao >= 0) {
                $insights[] = [
                    'type' => 'up',
                    'icon' => 'fa-arrow-trend-up',
                    'text' => sprintf('O interesse cresceu %s%% esta semana em relação à semana anterior (%d vs %d leads).', str_replace('.', ',', (string) $variacao), $atual, $anterior),
                ];
            } else {
                $insights[] = [
                    'type' => 'down',
                    'icon' => 'fa-arrow-trend-down',
                    'text' => sprintf('O volume de leads caiu %s%% esta semana em relação à semana anterior (%d vs %d leads).', str_replace('.', ',', (string) abs($variacao)), $atual, $anterior),
                ];
            }
        } elseif ($atual > 0) {
            $insights[] = ['type' => 'info', 'icon' => 'fa-circle-info', 'text' => sprintf('%d lead(s) captados esta semana (sem dados da semana anterior para comparar).', $atual)];
        }

        // ---- Melhor DDD por taxa de conversão (mínimo 3 leads) ----
        $stmt = $db->query(
            "SELECT ddd,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) AS fechados
             FROM leads
             WHERE ddd IS NOT NULL AND ddd <> ''
             GROUP BY ddd
             HAVING COUNT(*) >= 3
             ORDER BY (SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) / COUNT(*)) DESC, total DESC
             LIMIT 1"
        );
        $bestDdd = $stmt->fetch();
        if ($bestDdd && (int) $bestDdd['fechados'] > 0) {
            $rate = round(((int) $bestDdd['fechados'] / (int) $bestDdd['total']) * 100, 1);
            $insights[] = [
                'type' => 'info',
                'icon' => 'fa-phone',
                'text' => sprintf('O DDD %s tem a melhor taxa de conversão: %s%% (%d de %d leads fechados).', $bestDdd['ddd'], str_replace('.', ',', (string) $rate), $bestDdd['fechados'], $bestDdd['total']),
            ];
        }

        // ---- Melhor origem por taxa de conversão (mínimo 3 leads) ----
        $stmt = $db->query(
            "SELECT source,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) AS fechados
             FROM leads
             WHERE source IS NOT NULL
             GROUP BY source
             HAVING COUNT(*) >= 3
             ORDER BY (SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) / COUNT(*)) DESC, total DESC
             LIMIT 1"
        );
        $bestSource = $stmt->fetch();
        if ($bestSource && (int) $bestSource['fechados'] > 0) {
            $rate = round(((int) $bestSource['fechados'] / (int) $bestSource['total']) * 100, 1);
            $insights[] = [
                'type' => 'info',
                'icon' => 'fa-bullhorn',
                'text' => sprintf('%s é a origem com melhor conversão: %s%% (%d de %d leads fechados).', source_label($bestSource['source']), str_replace('.', ',', (string) $rate), $bestSource['fechados'], $bestSource['total']),
            ];
        }

        // ---- Melhor vendedor (responsável) por taxa de conversão (mínimo 3 leads) ----
        $stmt = $db->query(
            "SELECT u.name,
                COUNT(*) AS total,
                SUM(CASE WHEN l.status = 'fechado' THEN 1 ELSE 0 END) AS fechados
             FROM leads l
             INNER JOIN users u ON u.id = l.assigned_to
             GROUP BY u.id, u.name
             HAVING COUNT(*) >= 3
             ORDER BY (SUM(CASE WHEN l.status = 'fechado' THEN 1 ELSE 0 END) / COUNT(*)) DESC, total DESC
             LIMIT 1"
        );
        $bestUser = $stmt->fetch();
        if ($bestUser && (int) $bestUser['fechados'] > 0) {
            $rate = round(((int) $bestUser['fechados'] / (int) $bestUser['total']) * 100, 1);
            $insights[] = [
                'type' => 'up',
                'icon' => 'fa-trophy',
                'text' => sprintf('%s é o consultor com melhor conversão: %s%% (%d de %d leads fechados).', $bestUser['name'], str_replace('.', ',', (string) $rate), $bestUser['fechados'], $bestUser['total']),
            ];
        }

        // ---- Alerta de queda de conversão: mês atual x mês anterior ----
        $stmt = $db->query(
            "SELECT
                SUM(CASE WHEN YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) THEN 1 ELSE 0 END) AS total_atual,
                SUM(CASE WHEN YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) AND status='fechado' THEN 1 ELSE 0 END) AS fechados_atual,
                SUM(CASE WHEN YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS total_anterior,
                SUM(CASE WHEN YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH) AND status='fechado' THEN 1 ELSE 0 END) AS fechados_anterior
             FROM leads"
        );
        $row = $stmt->fetch();
        $totalAtual = (int) ($row['total_atual'] ?? 0);
        $fechadosAtual = (int) ($row['fechados_atual'] ?? 0);
        $totalAnterior = (int) ($row['total_anterior'] ?? 0);
        $fechadosAnterior = (int) ($row['fechados_anterior'] ?? 0);

        if ($totalAtual > 0 && $totalAnterior > 0) {
            $taxaAtual = round(($fechadosAtual / $totalAtual) * 100, 1);
            $taxaAnterior = round(($fechadosAnterior / $totalAnterior) * 100, 1);
            if ($taxaAtual < $taxaAnterior) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => 'fa-triangle-exclamation',
                    'text' => sprintf('Alerta: a taxa de conversão do mês caiu de %s%% para %s%% em relação ao mês anterior.', str_replace('.', ',', (string) $taxaAnterior), str_replace('.', ',', (string) $taxaAtual)),
                ];
            }
        }

        // ---- Leads "vencidos": next_contact_at já passou e o lead segue ativo (Fase 5) ----
        $stmt = $db->query(
            "SELECT COUNT(*) AS total FROM leads
             WHERE next_contact_at IS NOT NULL AND next_contact_at < NOW()
             AND status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')"
        );
        $overdue = (int) ($stmt->fetch()['total'] ?? 0);
        if ($overdue > 0) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-calendar-xmark',
                'text' => sprintf('%d lead(s) com contato agendado para uma data que já passou (vencidos).', $overdue),
                'url'  => url('leads?vencidos=1'),
            ];
        }

        // ---- Leads parados há muito tempo no mesmo status ----
        $stmt = $db->query(
            "SELECT COUNT(*) AS total FROM leads
             WHERE status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')
             AND updated_at <= DATE_SUB(NOW(), INTERVAL 10 DAY)"
        );
        $stale = (int) ($stmt->fetch()['total'] ?? 0);
        if ($stale > 0) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-hourglass-half',
                'text' => sprintf('%d lead(s) sem nenhuma atualização de status há mais de 10 dias — considere revisá-los.', $stale),
            ];
        }

        return $insights;
    }
}
