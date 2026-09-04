<?php

declare(strict_types=1);

namespace Tests\Support;

use App\CallsGateway;

final class FakeCallsGateway implements CallsGateway
{
    /** @param array<string, array<string, mixed>> $calls */
    public function __construct(private array $calls = [])
    {
    }

    public function listCalls(int $page, ?string $number): array
    {
        return [
            'data' => array_values($this->calls),
            'meta' => [
                'totalItemCount' => count($this->calls),
                'totalPageCount' => $this->calls === [] ? 0 : 1,
                'itemsPerPage' => 20,
                'currentPage' => $page,
                'nextPage' => null,
                'previousPage' => null,
            ],
        ];
    }

    public function findCall(string $id): ?array
    {
        return $this->calls[$id] ?? null;
    }
}

