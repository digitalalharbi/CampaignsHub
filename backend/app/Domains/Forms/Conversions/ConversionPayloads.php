<?php

declare(strict_types=1);

namespace App\Domains\Forms\Conversions;

use App\Domains\Forms\Models\ConversionDestination;

/**
 * FORMS-DISPATCH-001 — a lead's conversion in each destination's own shape.
 *
 * Identifiers leave this class hashed (SHA-256 of the normalised value, as every one of these interfaces specifies)
 * or not at all, so the payload that is STORED for diagnostics can be read by an operator without reading a person.
 * No secret is ever part of a payload: the transport adds it to the request at the moment of sending.
 *
 * `evidence` is what the submission already holds — nothing here is invented to make a payload look complete, and a
 * field that is absent is left out rather than sent empty.
 *
 * @phpstan-type Evidence array{event_id: string, occurred_at: \Carbon\CarbonInterface, email: ?string, phone: ?string, name: ?string, form_name: string, session_id: ?string, utm: array<string,string>, click_ids: array<string,string>, ga_client_id: ?string, ga_session_id: ?string, source_url: ?string}
 */
final class ConversionPayloads
{
    /**
     * @param  Evidence  $e
     * @return array{url: string, body: array<string,mixed>}
     */
    public static function build(ConversionDestination $d, array $e): array
    {
        $event = (string) ($d->config['event_name'] ?? '');

        return match ($d->kind) {
            ConversionKinds::GA4 => self::ga4($d, $event, $e),
            ConversionKinds::META => self::meta($d, $event, $e),
            ConversionKinds::GTM_SERVER => self::gtm($d, $event, $e),
            ConversionKinds::SNAPCHAT => self::snapchat($d, $event, $e),
            default => throw new \InvalidArgumentException('No payload builder for '.$d->kind),
        };
    }

    /** @param Evidence $e @return array{url: string, body: array<string,mixed>} */
    private static function ga4(ConversionDestination $d, string $event, array $e): array
    {
        $params = array_filter([
            'event_id' => $e['event_id'],
            'form_name' => $e['form_name'],
            'campaign_source' => $e['utm']['utm_source'] ?? null,
            'campaign_medium' => $e['utm']['utm_medium'] ?? null,
            'campaign' => $e['utm']['utm_campaign'] ?? null,
            'session_id' => $e['ga_session_id'],
            'engagement_time_msec' => 1,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $user = array_filter([
            'sha256_email_address' => $e['email'] === null ? null : [self::hash($e['email'])],
            'sha256_phone_number' => $e['phone'] === null ? null : [self::hash(self::digits($e['phone']))],
        ]);

        return [
            'url' => 'https://www.google-analytics.com/mp/collect',
            'body' => array_filter([
                // GA4 joins the event to the visit by the client id of its own cookie; without it the event is a new, unattributed visitor.
                'client_id' => $e['ga_client_id'] ?? $e['session_id'] ?? $e['event_id'],
                'timestamp_micros' => $e['occurred_at']->getTimestamp() * 1_000_000,
                'user_data' => $user === [] ? null : $user,
                'events' => [['name' => $event, 'params' => $params]],
            ], static fn ($v): bool => $v !== null),
        ];
    }

    /** @param Evidence $e @return array{url: string, body: array<string,mixed>} */
    private static function meta(ConversionDestination $d, string $event, array $e): array
    {
        [$first, $last] = self::names($e['name']);
        $user = array_filter([
            'em' => $e['email'] === null ? null : [self::hash($e['email'])],
            'ph' => $e['phone'] === null ? null : [self::hash(self::digits($e['phone']))],
            'fn' => $first === null ? null : [self::hash(mb_strtolower($first))],
            'ln' => $last === null ? null : [self::hash(mb_strtolower($last))],
            // Meta's own cookie format, rebuilt from the click id the visit arrived with.
            'fbc' => isset($e['click_ids']['fbclid']) ? 'fb.1.'.($e['occurred_at']->getTimestamp() * 1000).'.'.$e['click_ids']['fbclid'] : null,
        ]);

        return [
            'url' => 'https://graph.facebook.com/v25.0/'.$d->config['pixel_id'].'/events',
            'body' => ['data' => [array_filter([
                'event_name' => $event,
                'event_time' => $e['occurred_at']->getTimestamp(),
                'event_id' => $e['event_id'],
                'action_source' => 'website',
                'event_source_url' => $e['source_url'],
                'user_data' => $user,
                'custom_data' => array_filter(['form_name' => $e['form_name'], 'utm_source' => $e['utm']['utm_source'] ?? null, 'utm_campaign' => $e['utm']['utm_campaign'] ?? null]),
            ], static fn ($v): bool => $v !== null && $v !== [])]],
        ];
    }

    /**
     * Snapchat Conversions API v3. Every field below is one Snap's reference names: `event_name` from its standard list,
     * `action_source` WEB, `event_time` as an epoch (milliseconds, which Snap encourages), `event_source_url`, `event_id` for
     * deduplication against the browser pixel, and `user_data` with hashed `em` / `ph` and the visit's `sc_click_id`.
     * The page names no hash algorithm for `em` / `ph`; its examples are 64-hex values, which is SHA-256, the same as the other platforms here.
     *
     * @param  Evidence  $e
     * @return array{url: string, body: array<string,mixed>}
     */
    private static function snapchat(ConversionDestination $d, string $event, array $e): array
    {
        $user = array_filter([
            'em' => $e['email'] === null ? null : [self::hash($e['email'])],
            'ph' => $e['phone'] === null ? null : [self::hash(self::digits($e['phone']))],
            'sc_click_id' => $e['click_ids']['sclid'] ?? null,
        ]);

        return [
            'url' => 'https://tr.snapchat.com/v3/'.$d->config['pixel_id'].'/events',
            'body' => ['data' => [array_filter([
                'event_name' => $event,
                'action_source' => 'WEB',
                'event_time' => $e['occurred_at']->getTimestamp() * 1000,
                'event_id' => $e['event_id'],
                // Required by Snap; a conversion with no landing path cannot satisfy it, and is then simply refused by the platform's own check.
                'event_source_url' => $e['source_url'],
                'user_data' => $user,
            ], static fn ($v): bool => $v !== null && $v !== [])]],
        ];
    }

    /** @param Evidence $e @return array{url: string, body: array<string,mixed>} */
    private static function gtm(ConversionDestination $d, string $event, array $e): array
    {
        return [
            'url' => (string) $d->config['endpoint_url'],
            'body' => array_filter([
                'event_name' => $event,
                'event_id' => $e['event_id'],
                'timestamp' => $e['occurred_at']->toIso8601String(),
                'client_id' => $e['ga_client_id'] ?? $e['session_id'],
                'form_name' => $e['form_name'],
                'utm' => $e['utm'] === [] ? null : $e['utm'],
                'click_id_types' => $e['click_ids'] === [] ? null : array_keys($e['click_ids']),
                'user_data' => array_filter([
                    'sha256_email_address' => $e['email'] === null ? null : self::hash($e['email']),
                    'sha256_phone_number' => $e['phone'] === null ? null : self::hash(self::digits($e['phone'])),
                ]),
            ], static fn ($v): bool => $v !== null && $v !== []),
        ];
    }

    public static function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    private static function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function names(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), 2) ?: [];

        return [($parts[0] ?? '') === '' ? null : $parts[0], ($parts[1] ?? '') === '' ? null : $parts[1]];
    }
}
