<?php

declare(strict_types=1);

final class CallDashboardMetrics
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(?int $userId = null): array
    {
        $scope = $userId === null ? '' : ' AND c.user_id=:user_id';
        $sql = "SELECT
                    COUNT(DISTINCT c.id) AS visible_calls,
                    COUNT(DISTINCT CASE WHEN c.lead_id IS NOT NULL THEN c.id END) AS matched_calls,
                    COUNT(DISTINCT CASE WHEN c.analysis_status='completed' THEN c.id END) AS analyzed_calls,
                    COUNT(DISTINCT CASE WHEN c.analysis_status IN ('pending','processing') THEN c.id END) AS pending_calls,
                    COUNT(DISTINCT CASE WHEN c.analysis_status='failed' THEN c.id END) AS failed_calls,
                    AVG(CASE WHEN c.analysis_status='completed' THEN a.overall_score END) AS average_score
                FROM call_records c
                LEFT JOIN call_analyses a ON a.call_id=c.id
                WHERE c.outcome<>'voicemail'" . $scope;
        $statement = $this->db->prepare($sql);
        $statement->execute($userId === null ? [] : [':user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'visible_calls' => (int) ($row['visible_calls'] ?? 0),
            'matched_calls' => (int) ($row['matched_calls'] ?? 0),
            'analyzed_calls' => (int) ($row['analyzed_calls'] ?? 0),
            'pending_calls' => (int) ($row['pending_calls'] ?? 0),
            'failed_calls' => (int) ($row['failed_calls'] ?? 0),
            'average_score' => round((float) ($row['average_score'] ?? 0), 1),
        ];
    }
}
