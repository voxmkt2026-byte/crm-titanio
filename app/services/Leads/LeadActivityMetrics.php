<?php

declare(strict_types=1);

final class LeadActivityMetrics
{
    private const INTERACTION_TYPES = ['observacao', 'contato', 'whatsapp', 'ligacao'];
    private const CLOSED_STATUSES = [
        'fechado', 'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido',
        'nao_responde', 'bloqueou', 'duplicado',
    ];
    private const NEGATIVE_STATUSES = [
        'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido',
        'nao_responde', 'bloqueou', 'duplicado',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function dailyBySeller(string $date, ?int $sellerId = null): array
    {
        $start = (new DateTimeImmutable($date . ' 00:00:00'))->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d H:i:s');
        $interactionPlaceholders = implode(',', array_fill(0, count(self::INTERACTION_TYPES), '?'));
        $closedPlaceholders = implode(',', array_fill(0, count(self::CLOSED_STATUSES), '?'));
        $sql = "SELECT u.id AS user_id,u.name AS user_name,
                    COUNT(DISTINCT h.lead_id) AS updated_leads,
                    COUNT(h.id) AS interactions,
                    (SELECT COUNT(*) FROM leads owned
                     WHERE owned.assigned_to=u.id AND owned.status NOT IN ({$closedPlaceholders})) AS active_leads
                FROM users u
                LEFT JOIN lead_history h ON h.user_id=u.id
                    AND h.created_at>=? AND h.created_at<?
                    AND h.type IN ({$interactionPlaceholders})
                WHERE u.active=1 AND u.role IN ('consultor','supervisor','admin')";
        $params = array_merge(self::CLOSED_STATUSES, [$start, $end], self::INTERACTION_TYPES);
        if ($sellerId !== null) {
            $sql .= ' AND u.id=?';
            $params[] = $sellerId;
        }
        $sql .= ' GROUP BY u.id,u.name ORDER BY updated_leads DESC,interactions DESC,u.name ASC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function staleCount(int $days, ?int $sellerId = null, ?string $now = null): int
    {
        $days = max(1, min(365, $days));
        $reference = new DateTimeImmutable($now ?? 'now');
        $cutoff = $reference->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $closedPlaceholders = implode(',', array_fill(0, count(self::CLOSED_STATUSES), '?'));
        $sql = "SELECT COUNT(*) FROM leads
                WHERE status NOT IN ({$closedPlaceholders})
                  AND COALESCE(last_contact_at,created_at)<=?";
        $params = array_merge(self::CLOSED_STATUSES, [$cutoff]);
        if ($sellerId !== null) {
            $sql .= ' AND assigned_to=?';
            $params[] = $sellerId;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function lossesBySeller(string $dateFrom, string $dateTo, ?int $sellerId = null): array
    {
        $start = (new DateTimeImmutable($dateFrom . ' 00:00:00'))->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($dateTo . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
        $statusPlaceholders = implode(',', array_fill(0, count(self::NEGATIVE_STATUSES), '?'));
        $sql = "SELECT u.id AS user_id,u.name AS user_name,
                       COALESCE(lr.name,'Não informado') AS reason_name,COUNT(l.id) AS total
                FROM leads l
                LEFT JOIN users u ON u.id=l.assigned_to
                LEFT JOIN loss_reasons lr ON lr.id=l.loss_reason_id
                WHERE l.status IN ({$statusPlaceholders})
                  AND l.updated_at>=? AND l.updated_at<?";
        $params = array_merge(self::NEGATIVE_STATUSES, [$start, $end]);
        if ($sellerId !== null) {
            $sql .= ' AND l.assigned_to=?';
            $params[] = $sellerId;
        }
        $sql .= " GROUP BY u.id,u.name,COALESCE(lr.name,'Não informado') ORDER BY total DESC,u.name ASC";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
