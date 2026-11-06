<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * An address as a log may hold it: enough to recognise whose it is, and not enough to mail it.
 */
final class MailAddress
{
    public static function mask(string $address): string
    {
        return str_contains($address, '@')
            ? mb_substr($address, 0, 1).'***@'.substr($address, (int) strrpos($address, '@') + 1)
            : '***';
    }
}
