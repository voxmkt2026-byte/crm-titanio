<?php

declare(strict_types=1);

final class PhoneNormalizer
{
    public static function canonical(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if (str_starts_with($digits, '55') && in_array(strlen(substr($digits, 2)), [10, 11, 12], true)) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            if (in_array(strlen(substr($digits, 1)), [10, 11], true)) {
                $digits = substr($digits, 1);
            } elseif (strlen($digits) >= 3 && in_array(strlen(substr($digits, 3)), [10, 11], true)) {
                // Discagem interurbana brasileira: 0 + código da operadora + DDD/número.
                $digits = substr($digits, 3);
            }
        }
        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }

    /** @return list<string> */
    public static function candidates(?string $value): array
    {
        $phone = self::canonical($value);
        if ($phone === null) return [];
        $candidates = [$phone];
        if (strlen($phone) === 11 && $phone[2] === '9') {
            $candidates[] = substr($phone, 0, 2) . substr($phone, 3);
        } elseif (strlen($phone) === 10) {
            $candidates[] = substr($phone, 0, 2) . '9' . substr($phone, 2);
        }
        return array_values(array_unique($candidates));
    }
}
