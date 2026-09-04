<?php

declare(strict_types=1);

namespace App;

interface CallsGateway
{
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function listCalls(int $page, ?string $number): array;

    /** @return array<string, mixed>|null */
    public function findCall(string $id): ?array;
}

