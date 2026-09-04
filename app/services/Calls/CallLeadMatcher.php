<?php

declare(strict_types=1);

require_once __DIR__ . '/PhoneNormalizer.php';

final class CallLeadMatcher
{
    private ?array $phoneIndex = null;

    public function __construct(private PDO $db)
    {
    }

    public function match(array $call): array
    {
        $direction = $this->direction($call);
        [$rawPhone, $normalizedPhone] = $this->contactPhone($call, $direction);
        $leadIds = [];
        foreach (PhoneNormalizer::candidates($normalizedPhone) as $candidate) {
            $leadIds = array_merge($leadIds, $this->phoneIndex()[$candidate] ?? []);
        }
        $leadIds = array_values(array_unique($leadIds));
        $leadId = count($leadIds) === 1 ? (int) $leadIds[0] : null;
        $linkStatus = count($leadIds) > 1 ? 'ambiguous' : ($leadId === null ? 'unmatched' : 'matched');

        $userId = $this->agentUserId($call);
        if ($userId === null && $leadId !== null) {
            $query = $this->db->prepare('SELECT assigned_to FROM leads WHERE id=:id LIMIT 1');
            $query->execute([':id' => $leadId]);
            $assignedTo = $query->fetchColumn();
            $userId = $assignedTo === false || (int) $assignedTo <= 0 ? null : (int) $assignedTo;
        }

        return [
            'lead_id' => $leadId,
            'user_id' => $userId,
            'link_status' => $linkStatus,
            'contact_phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'direction' => $direction,
        ];
    }

    private function direction(array $call): string
    {
        $type = strtolower(trim((string) ($call['call_type'] ?? $call['direction'] ?? '')));
        if (str_contains($type, 'inbound') || $type === 'in' || str_contains($type, 'incoming')) {
            return 'inbound';
        }
        if (str_contains($type, 'outbound') || $type === 'out' || str_contains($type, 'outgoing')) {
            return 'outbound';
        }
        return 'unknown';
    }

    private function contactPhone(array $call, string $direction): array
    {
        $ordered = $direction === 'inbound'
            ? [$call['from'] ?? null, $call['BINA'] ?? $call['bina'] ?? null, $call['to'] ?? null]
            : [$call['to'] ?? null, $call['BINA'] ?? $call['bina'] ?? null, $call['from'] ?? null];
        $fallback = null;
        foreach ($ordered as $value) {
            $raw = is_scalar($value) ? trim((string) $value) : '';
            $digits = preg_replace('/\D+/', '', $raw) ?: '';
            if ($fallback === null && strlen($digits) >= 8) $fallback = $raw;
            $normalized = PhoneNormalizer::canonical($raw);
            if ($normalized !== null) {
                return [$raw, $normalized];
            }
        }
        return [$fallback, null];
    }

    private function phoneIndex(): array
    {
        if ($this->phoneIndex !== null) {
            return $this->phoneIndex;
        }
        $this->phoneIndex = [];
        foreach ($this->db->query('SELECT id,ddd,phone,whatsapp FROM leads')->fetchAll(PDO::FETCH_ASSOC) as $lead) {
            foreach (['phone', 'whatsapp'] as $field) {
                $raw = trim((string) ($lead[$field] ?? ''));
                $candidates = [$raw];
                $ddd = preg_replace('/\D+/', '', (string) ($lead['ddd'] ?? '')) ?: '';
                $digits = preg_replace('/\D+/', '', $raw) ?: '';
                if ($ddd !== '' && in_array(strlen($digits), [8, 9], true)) {
                    $candidates[] = $ddd . $digits;
                }
                foreach ($candidates as $candidate) {
                    foreach (PhoneNormalizer::candidates($candidate) as $phone) {
                        $this->phoneIndex[$phone][] = (int) $lead['id'];
                    }
                }
            }
        }
        foreach ($this->phoneIndex as $phone => $ids) {
            $this->phoneIndex[$phone] = array_values(array_unique($ids));
        }
        return $this->phoneIndex;
    }

    private function agentUserId(array $call): ?int
    {
        $externalKey = strtolower(trim((string) ($call['email'] ?? $call['agent_external_key'] ?? '')));
        if ($externalKey === '') {
            return null;
        }
        try {
            $mapping = $this->db->prepare('SELECT user_id FROM call_agent_mappings WHERE provider=:provider AND external_key=:external_key LIMIT 1');
            $mapping->execute([':provider' => 'api4com', ':external_key' => $externalKey]);
            $mapped = $mapping->fetchColumn();
            if ($mapped !== false && (int) $mapped > 0) {
                return (int) $mapped;
            }
        } catch (Throwable) {
            // A migration pode ainda estar sendo aplicada; o e-mail continua sendo um fallback seguro.
        }
        $query = $this->db->prepare('SELECT id FROM users WHERE LOWER(email)=:email AND active=1 LIMIT 1');
        $query->execute([':email' => $externalKey]);
        $id = $query->fetchColumn();
        return $id === false || (int) $id <= 0 ? null : (int) $id;
    }
}
