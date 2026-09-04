<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $publicCode = 'INTERNAL_ERROR',
        private int $httpStatus = 500,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
