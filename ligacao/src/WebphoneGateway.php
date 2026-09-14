<?php

declare(strict_types=1);

namespace App;

interface WebphoneGateway
{
    public function findExtension(string $extension): ?array;

    public function dial(string $extension, string $phone, array $metadata = []): array;

    public function hangup(string $callId): array;
}
