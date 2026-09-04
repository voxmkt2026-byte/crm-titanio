<?php

declare(strict_types=1);

final class LeadInteractionPolicy
{
    private const NEGATIVE_STATUSES = [
        'perdido',
        'sem_interesse',
        'sem_entrada',
        'numero_invalido',
        'nao_responde',
        'bloqueou',
        'duplicado',
    ];

    private int $minimumCharacters;

    public function __construct(int $minimumCharacters = 50)
    {
        $this->minimumCharacters = max(50, min(500, $minimumCharacters));
    }

    public function minimumCharacters(): int
    {
        return $this->minimumCharacters;
    }

    public function validateObservation(string $text): string
    {
        $normalized = trim($text);
        if (mb_strlen($normalized, 'UTF-8') < $this->minimumCharacters) {
            throw new DomainException(
                'A observação deve conter pelo menos ' . $this->minimumCharacters . ' caracteres.'
            );
        }

        return $normalized;
    }

    public function isNegativeStatus(string $status): bool
    {
        return in_array($status, self::NEGATIVE_STATUSES, true);
    }

    public function validateLoss(string $status, ?int $lossReasonId, string $note): string
    {
        if (!$this->isNegativeStatus($status)) {
            return trim($note);
        }
        if ($lossReasonId === null || $lossReasonId <= 0) {
            throw new DomainException('Selecione um motivo de perda válido.');
        }

        return $this->validateObservation($note);
    }
}
