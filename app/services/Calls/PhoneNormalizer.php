<?php

declare(strict_types=1);

final class PhoneNormalizer
{
    public static function canonical(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $digits = substr($digits, 2);
        }
        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }
}
