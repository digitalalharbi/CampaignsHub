<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

/**
 * PAY-WEBHOOK-SECRET-REDACT-001 — a webhook body is kept as evidence, never with the secret that authenticated it.
 *
 * Moyasar authenticates a webhook with a shared secret carried INSIDE the JSON body (`secret_token`). The ledger
 * (`payment_webhook_events.payload`) stored that body whole, so every row held the live webhook secret: anyone who
 * could read the table, a backup or an admin export could forge «paid» events. The secret is checked first, on the
 * raw body, exactly as before (`MoyasarPaymentProvider::verifyWebhook`); only the copy that is STORED is redacted.
 *
 * Keys are matched case-insensitively at any depth. A body that is not JSON is kept as text with any
 * `key=value` / `"key": "value"` occurrence of the same keys masked.
 */
final class WebhookPayloadRedactor
{
    public const MASK = '[redacted]';

    /** Names that carry a credential rather than a fact about the payment. */
    public const KEYS = ['secret_token', 'secret', 'webhook_secret', 'webhook_token', 'api_key', 'password'];

    /** @return array<string, mixed>|null */
    public static function forStorage(string $rawBody): ?array
    {
        if ($rawBody === '') {
            return null;
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? self::redact($decoded) : ['raw' => self::redactText(mb_substr($rawBody, 0, 2000))];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function redactText(string $text): string
    {
        $names = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), self::KEYS));

        return (string) preg_replace(
            ['/(\b(?:'.$names.')\s*=\s*)[^&\s]*/i', '/("(?:'.$names.')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i'],
            ['$1'.self::MASK, '$1"'.self::MASK.'"'],
            $text,
        );
    }

    /** Does this stored payload still carry a credential? Used by the one-off cleanup command. */
    public static function carriesSecret(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        return self::redact($payload) !== $payload
            || (isset($payload['raw']) && is_string($payload['raw']) && self::redactText($payload['raw']) !== $payload['raw']);
    }
}
