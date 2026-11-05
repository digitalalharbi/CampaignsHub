<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the two facts about a delivery that ordering and replay protection need.
 *
 * Read only from the shapes the providers document. A recursive search for «anything that looks like
 * a date» would let a crafted payload pick which timestamp the guard believes.
 */
final class WebhookEventFacts
{
    /**
     * The payload as it may be KEPT: every value under a key that names a credential is masked.
     *
     * Salla's `app.store.authorize` carries the merchant's access and refresh tokens in `data`. The handler
     * receives the payload in memory and keeps the tokens where tokens belong (the vault); the inbox row is
     * evidence of a delivery and must not become a second, unencrypted copy of a credential.
     *
     * @param  array<array-key,mixed>  $payload
     * @return array<array-key,mixed>
     */
    public static function redacted(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|password|passwd|authorization|api[_-]?key|signature|credential/i', $key) === 1) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = self::redacted($value);
            }
        }

        return $payload;
    }

    /**
     * The provider's own time for this event, or null when the body carries none we can read.
     *
     * Top-level `created_at` is Salla's; `entry[0].time` is Meta's (epoch seconds). A value that does not
     * parse is «no time», never «now»: guessing would let a malformed body slip past the replay window.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function eventTime(array $payload): ?Carbon
    {
        $candidates = [];

        foreach (['created_at', 'timestamp', 'occurred_at', 'event_time'] as $key) {
            if (array_key_exists($key, $payload)) {
                $candidates[] = $payload[$key];
            }
        }

        $entryTime = $payload['entry'][0]['time'] ?? null;
        if ($entryTime !== null) {
            $candidates[] = $entryTime;
        }

        foreach ($candidates as $value) {
            $time = self::parse($value);
            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    /**
     * Which order (or other entity) the event is about, scoped to one store so two stores that happen
     * to reuse an id never share an ordering.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function entityKey(array $payload, ?string $accountId, ?string $topic): ?string
    {
        if ($accountId === null || $topic === null || ! str_contains($topic, '.')) {
            return null;
        }

        $entity = strstr($topic, '.', true);
        $id = $payload['data']['id'] ?? null;

        if (! is_string($entity) || $entity === '' || (! is_string($id) && ! is_int($id)) || (string) $id === '') {
            return null;
        }

        return mb_substr($accountId.':'.$entity.':'.$id, 0, 191);
    }

    /** Whether the body's time says which zone it is in (an epoch, or a string ending in Z, an offset, UTC or GMT). */
    public static function hasZone(array $payload): bool
    {
        $values = [$payload['created_at'] ?? null, $payload['timestamp'] ?? null, $payload['occurred_at'] ?? null, $payload['event_time'] ?? null, $payload['entry'][0]['time'] ?? null];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_int($value) || is_array($value)) {
                return true;
            }

            return is_string($value) && (ctype_digit($value) || preg_match('/(Z|UTC|GMT|[+-]\d{2}:?\d{2})$/i', $value) === 1);
        }

        return false;
    }

    public static function parse(mixed $value): ?Carbon
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $n = (int) $value;

                return Carbon::createFromTimestampUTC($n > 99_999_999_999 ? intdiv($n, 1000) : $n);
            }

            if (is_array($value) && is_string($value['date'] ?? null)) {
                return Carbon::parse($value['date'], is_string($value['timezone'] ?? null) ? $value['timezone'] : 'UTC');
            }

            if (is_string($value) && $value !== '') {
                return Carbon::parse($value, 'UTC');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
