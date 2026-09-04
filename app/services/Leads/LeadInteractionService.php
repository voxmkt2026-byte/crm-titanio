<?php

declare(strict_types=1);

require_once __DIR__ . '/LeadInteractionPolicy.php';

final class LeadInteractionService
{
    private const CONTACT_TYPES = ['observacao', 'contato', 'whatsapp', 'ligacao'];

    public function __construct(
        private PDO $db,
        private LeadInteractionPolicy $policy
    ) {
    }

    public function recordContact(
        int $leadId,
        int $userId,
        string $type,
        string $observation,
        ?string $nextContactAt = null,
        string $prefix = ''
    ): array {
        if (!in_array($type, self::CONTACT_TYPES, true)) {
            throw new DomainException('Tipo de contato inválido.');
        }

        $note = $this->policy->validateObservation($observation);
        $description = trim($prefix) !== '' ? '(' . trim($prefix) . ') ' . $note : $note;
        $contactedAt = date('Y-m-d H:i:s');
        $replaceNextContact = func_num_args() >= 5;

        return $this->transaction(function () use (
            $leadId,
            $userId,
            $type,
            $description,
            $contactedAt,
            $replaceNextContact,
            $nextContactAt
        ): array {
            $this->assertLeadExists($leadId);

            $history = $this->db->prepare(
                'INSERT INTO lead_history (lead_id,user_id,type,description,created_at) '
                . 'VALUES (:lead_id,:user_id,:type,:description,:created_at)'
            );
            $history->execute([
                ':lead_id' => $leadId,
                ':user_id' => $userId,
                ':type' => $type,
                ':description' => $description,
                ':created_at' => $contactedAt,
            ]);

            $sql = 'UPDATE leads SET last_contact_at=:last_contact_at';
            $params = [':last_contact_at' => $contactedAt, ':id' => $leadId];
            if ($replaceNextContact) {
                $sql .= ', next_contact_at=:next_contact_at';
                $params[':next_contact_at'] = $nextContactAt;
            }
            $sql .= ' WHERE id=:id';
            $this->db->prepare($sql)->execute($params);

            return [
                'history_id' => (int) $this->db->lastInsertId(),
                'contacted_at' => $contactedAt,
                'description' => $description,
            ];
        });
    }

    public function transitionStatus(
        int $leadId,
        int $userId,
        string $newStatus,
        ?int $lossReasonId,
        string $lossNote,
        string $context
    ): array {
        $validatedLossNote = $this->policy->validateLoss($newStatus, $lossReasonId, $lossNote);

        return $this->transaction(function () use (
            $leadId,
            $userId,
            $newStatus,
            $lossReasonId,
            $validatedLossNote,
            $context
        ): array {
            $lead = $this->findLead($leadId);
            $oldStatus = (string) $lead['status'];
            $reasonName = null;

            if ($this->policy->isNegativeStatus($newStatus)) {
                $reason = $this->activeLossReason((int) $lossReasonId);
                if (!$reason) {
                    throw new DomainException('Selecione um motivo de perda ativo.');
                }
                $reasonName = (string) $reason['name'];
            } else {
                $lossReasonId = null;
            }

            $update = $this->db->prepare(
                'UPDATE leads SET status=:status,loss_reason_id=:loss_reason_id WHERE id=:id'
            );
            $update->execute([
                ':status' => $newStatus,
                ':loss_reason_id' => $lossReasonId,
                ':id' => $leadId,
            ]);

            $now = date('Y-m-d H:i:s');
            $source = trim($context) !== '' ? ' via ' . trim($context) : '';
            $this->insertHistory(
                $leadId,
                $userId,
                'status',
                'Status alterado de "' . $oldStatus . '" para "' . $newStatus . '"' . $source . '.',
                $now
            );

            if ($this->policy->isNegativeStatus($newStatus)) {
                $this->insertHistory(
                    $leadId,
                    $userId,
                    'observacao',
                    'Motivo da perda: ' . $reasonName . '. Contexto: ' . $validatedLossNote,
                    $now
                );
            }

            return [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'loss_reason_id' => $lossReasonId,
                'changed_at' => $now,
            ];
        });
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function assertLeadExists(int $leadId): void
    {
        $this->findLead($leadId);
    }

    private function findLead(int $leadId): array
    {
        $statement = $this->db->prepare('SELECT id,status FROM leads WHERE id=:id LIMIT 1');
        $statement->execute([':id' => $leadId]);
        $lead = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            throw new DomainException('Lead não encontrado.');
        }
        return $lead;
    }

    private function activeLossReason(int $lossReasonId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,name FROM loss_reasons WHERE id=:id AND active=1 LIMIT 1'
        );
        $statement->execute([':id' => $lossReasonId]);
        $reason = $statement->fetch(PDO::FETCH_ASSOC);
        return $reason ?: null;
    }

    private function insertHistory(
        int $leadId,
        int $userId,
        string $type,
        string $description,
        string $createdAt
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO lead_history (lead_id,user_id,type,description,created_at) '
            . 'VALUES (:lead_id,:user_id,:type,:description,:created_at)'
        );
        $statement->execute([
            ':lead_id' => $leadId,
            ':user_id' => $userId,
            ':type' => $type,
            ':description' => $description,
            ':created_at' => $createdAt,
        ]);
    }
}
