<?php

declare(strict_types=1);

require_once __DIR__ . '/CallLeadMatcher.php';

final class CallLinkRepairService
{
    private CallLeadMatcher $matcher;

    public function __construct(private PDO $db)
    {
        $this->matcher = new CallLeadMatcher($db);
    }

    public function repairForLead(int $leadId, int $limit = 2000): int
    {
        return $this->repairUnmatched($limit, $leadId);
    }

    public function repairUnmatched(int $limit = 2000, ?int $onlyLeadId = null): int
    {
        $limit = max(1, min(5000, $limit));
        $rows = $this->db->query(
            "SELECT id,user_id,agent_external_key,contact_phone,normalized_phone,direction,started_at,lead_history_id " .
            "FROM call_records WHERE lead_id IS NULL AND link_status<>'manual' " .
            "AND (normalized_phone IS NOT NULL OR contact_phone IS NOT NULL) ORDER BY id DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
        $linked = 0;

        foreach ($rows as $call) {
            $phone = trim((string) ($call['normalized_phone'] ?: $call['contact_phone']));
            if ($phone === '') continue;
            $payload = [
                'call_type' => (string) ($call['direction'] ?? 'unknown'),
                'email' => (string) ($call['agent_external_key'] ?? ''),
                'from' => ($call['direction'] ?? '') === 'inbound' ? $phone : '1000',
                'to' => ($call['direction'] ?? '') === 'inbound' ? '1000' : $phone,
            ];
            $match = $this->matcher->match($payload);
            $leadId = (int) ($match['lead_id'] ?? 0);
            if ($leadId <= 0 || ($onlyLeadId !== null && $leadId !== $onlyLeadId)) continue;

            try {
                $update = $this->db->prepare(
                    "UPDATE call_records SET lead_id=:lead,user_id=COALESCE(user_id,:user),link_status='matched',updated_at=CURRENT_TIMESTAMP " .
                    "WHERE id=:id AND lead_id IS NULL AND link_status<>'manual'"
                );
                $update->execute([':lead' => $leadId, ':user' => $match['user_id'], ':id' => $call['id']]);
            } catch (PDOException $error) {
                // Dumps antigos usados em testes podem não ter updated_at; a produção possui o campo.
                $update = $this->db->prepare("UPDATE call_records SET lead_id=:lead,user_id=COALESCE(user_id,:user),link_status='matched' WHERE id=:id AND lead_id IS NULL AND link_status<>'manual'");
                $update->execute([':lead' => $leadId, ':user' => $match['user_id'], ':id' => $call['id']]);
            }
            if ($update->rowCount() < 1) continue;
            $linked++;
            $this->feedLead((int) $call['id'], $leadId, $match['user_id'], $call);
        }
        return $linked;
    }

    private function feedLead(int $callId, int $leadId, ?int $userId, array $call): void
    {
        if (empty($call['lead_history_id'])) {
            $history = $this->db->prepare(
                "INSERT INTO lead_history(lead_id,user_id,type,description,created_at) " .
                "VALUES(:lead,:user,'ligacao','Ligação associada automaticamente pelo telefone.',COALESCE(:started,CURRENT_TIMESTAMP))"
            );
            $history->execute([':lead' => $leadId, ':user' => $userId, ':started' => $call['started_at']]);
            $historyId = (int) $this->db->lastInsertId();
            $this->db->prepare('UPDATE call_records SET lead_history_id=:history WHERE id=:id AND lead_history_id IS NULL')
                ->execute([':history' => $historyId, ':id' => $callId]);
        }
        if (!empty($call['started_at'])) {
            $this->db->prepare(
                'UPDATE leads SET last_contact_at=CASE WHEN last_contact_at IS NULL OR last_contact_at<:at THEN :at ELSE last_contact_at END WHERE id=:id'
            )->execute([':at' => $call['started_at'], ':id' => $leadId]);
        }
    }
}
