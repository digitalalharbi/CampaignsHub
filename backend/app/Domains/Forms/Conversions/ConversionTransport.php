<?php

declare(strict_types=1);

namespace App\Domains\Forms\Conversions;

use App\Domains\Forms\Models\ConversionDestination;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * FORMS-DISPATCH-001 — the one place a conversion leaves the product, and the one place a secret is read.
 *
 * Every other class works with payloads that carry no secret; this adds it to the request at the moment of sending and
 * strips it from anything that is written back (an error message is stored and shown, and a platform's error text can
 * echo the URL it was called with).
 *
 * The result says whether to try again: a timeout, a 429 or a 5xx may succeed later; any other 4xx will not, and
 * retrying it only repeats the refusal.
 *
 * `validate` sends to a platform's CHECKING interface where one exists (GA4's debug endpoint reports what it would have
 * recorded and records nothing). It carries only synthetic data — the diagnostics action builds it from a fixture,
 * never from a lead.
 */
final class ConversionTransport
{
    /**
     * @param  array{url: string, body: array<string,mixed>}  $payload
     * @return array{ok: bool, code: ?int, error: ?string, retryable: bool}
     */
    public function send(ConversionDestination $d, array $payload, bool $validate = false): array
    {
        $secrets = (array) $d->secrets;
        [$url, $headers] = $this->target($d, $payload, $validate, $secrets);

        if ($url === null) {
            return ['ok' => false, 'code' => null, 'error' => 'This destination has no validation interface; use test mode to inspect the payload.', 'retryable' => false];
        }

        try {
            $response = Http::timeout(10)->withHeaders($headers)->asJson()->post($url, $payload['body']);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'code' => null, 'error' => $this->clean('Connection failed: '.$e->getMessage(), $secrets), 'retryable' => true];
        }

        $code = $response->status();
        if ($response->successful()) {
            // GA4's debug endpoint answers 200 with the problems it found.
            $problems = $validate && $d->kind === ConversionKinds::GA4 ? (array) ($response->json('validationMessages') ?? []) : [];
            if ($problems !== []) {
                return ['ok' => false, 'code' => $code, 'error' => $this->clean('Rejected by GA4: '.json_encode($problems), $secrets), 'retryable' => false];
            }

            return ['ok' => true, 'code' => $code, 'error' => null, 'retryable' => false];
        }

        return [
            'ok' => false,
            'code' => $code,
            'error' => $this->clean('HTTP '.$code.' '.mb_substr((string) $response->body(), 0, 200), $secrets),
            'retryable' => $code === 429 || $code >= 500,
        ];
    }

    /**
     * @param  array{url: string, body: array<string,mixed>}  $payload
     * @param  array<string,mixed>  $secrets
     * @return array{0: ?string, 1: array<string,string>}
     */
    private function target(ConversionDestination $d, array $payload, bool $validate, array $secrets): array
    {
        switch ($d->kind) {
            case ConversionKinds::GA4:
                $base = $validate ? 'https://www.google-analytics.com/debug/mp/collect' : $payload['url'];

                return [$base.'?'.http_build_query(['measurement_id' => $d->config['measurement_id'], 'api_secret' => $secrets['api_secret'] ?? '']), []];

            case ConversionKinds::META:
                $query = ['access_token' => $secrets['access_token'] ?? ''];
                if ($validate) {
                    if (($d->config['test_event_code'] ?? '') === '') {
                        return [null, []];
                    }
                    $query['test_event_code'] = $d->config['test_event_code'];
                }

                return [$payload['url'].'?'.http_build_query($query), []];

            case ConversionKinds::SNAPCHAT:
                // Snap documents validation as the same call with `/validate` appended to the path.
                return [($validate ? $payload['url'].'/validate' : $payload['url']).'?'.http_build_query(['access_token' => $secrets['access_token'] ?? '']), []];

            default:
                return [$payload['url'], isset($secrets['api_key']) ? ['X-API-Key' => (string) $secrets['api_key']] : []];
        }
    }

    /** @param array<string,mixed> $secrets */
    private function clean(string $message, array $secrets): string
    {
        foreach ($secrets as $value) {
            if (is_string($value) && $value !== '') {
                $message = str_replace([$value, rawurlencode($value)], '***', $message);
            }
        }

        return mb_substr($message, 0, 300);
    }
}
