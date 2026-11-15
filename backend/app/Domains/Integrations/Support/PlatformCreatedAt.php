<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * CONS-I-AGE — a platform's «created» stamp, normalised to one ISO-8601 UTC string.
 *
 * Platforms spell it four ways: an ISO string (Meta, Snapchat, X), a «Y-m-d H:i:s» UTC string (TikTok), epoch
 * milliseconds (LinkedIn) and epoch seconds. Anything that is not clearly a date is null — an age is never guessed.
 */
final class PlatformCreatedAt
{
    public static function from(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }

        try {
            if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
                $n = (float) $value;
                if ($n <= 0) {
                    return null;
                }
                $at = $n > 100_000_000_000 ? Carbon::createFromTimestampMsUTC((int) $n) : Carbon::createFromTimestampUTC((int) $n);
            } else {
                $at = Carbon::parse((string) $value, 'UTC');
            }
        } catch (Throwable) {
            return null;
        }

        return $at->year < 2000 ? null : $at->utc()->toIso8601String();
    }
}
