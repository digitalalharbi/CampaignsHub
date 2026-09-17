<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\ProviderRequestRefused;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * META-CANDIDATE-001 — the Candidate app's whole round trip, one recorded step at a time.
 *
 *     oauth_start → consent → token_exchange → account_discovery → ads_read
 *
 * Every Meta credential used here is `PlatformCredentials::forMeta(Candidate)`, and the profile is read
 * from nowhere but the signed state. The result lands in `meta_candidate_connections` — never in
 * `provider_connections` or `external_accounts` — so nothing this proves can bind to, sync into or
 * overwrite a customer's Live connection.
 *
 * A step records ok, or a failure carrying Meta's own code, subcode, type, fbtrace_id and a redacted
 * message. Never a token, never a secret.
 */
final class MetaCandidateRoundTrip
{
    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly MetaCandidateCredentials $credentials,
    ) {}

    /**
     * @return array{run: MetaCandidateConnection, authorization_url: string}
     *
     * @throws RuntimeException when the candidate is not fully configured
     */
    public function start(int $ownerId): array
    {
        $creds = PlatformCredentials::forMeta(MetaCredentialProfile::Candidate);

        if (! $creds->isConfigured()) {
            throw new RuntimeException('The Candidate Meta app is missing: '.implode(', ', $creds->missing()).'.');
        }

        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => MetaCredentialProfile::Candidate->value,
            'started_by' => $ownerId,
            'status' => 'started',
            'credential_fingerprint' => $this->credentials->fingerprint(),
            'app_id_hint' => mb_substr((string) $creds->get('client_id'), -4),
            'started_at' => Carbon::now(),
        ]);
        $run->recordStep('oauth_start', 'ok', ['detail' => 'Dialog built from the FLfB configuration; scope not sent.']);
        $run->save();

        $state = AuthorizationState::issueMetaCandidate($ownerId, (string) $run->getKey());

        return ['run' => $run, 'authorization_url' => $this->oauth->authorizationUrl($creds, $state)];
    }

    /**
     * Finish the flow from a VERIFIED candidate state record.
     *
     * @param  array<string,mixed>  $record  from `AuthorizationState::claim()`, profile already checked
     * @param  array<string,mixed>  $query  the callback's query string
     */
    public function complete(array $record, array $query): ?MetaCandidateConnection
    {
        $run = MetaCandidateConnection::query()->find((string) ($record['candidate_run_id'] ?? ''));

        // A run that is gone, belongs to another owner, or already finished cannot be completed again.
        if ($run === null || $run->status !== 'started' || (int) $run->started_by !== (int) ($record['user_id'] ?? 0)) {
            return null;
        }

        try {
            $this->proceed($run, $query);
        } catch (Throwable $e) {
            // Anything unforeseen still ends the run as a recorded failure rather than a hanging one.
            $this->fail($run, $this->firstPending($run), ['message' => $this->redact($e->getMessage(), null)]);
        }

        return $run;
    }

    /** @param array<string,mixed> $query */
    private function proceed(MetaCandidateConnection $run, array $query): void
    {
        if (isset($query['error'])) {
            $this->fail($run, 'consent', ['error' => [
                'type' => $this->redact((string) $query['error'], null),
                'code' => isset($query['error_code']) ? (string) $query['error_code'] : null,
                'reason' => isset($query['error_reason']) ? $this->redact((string) $query['error_reason'], null) : null,
                'message' => isset($query['error_description']) ? $this->redact((string) $query['error_description'], null) : null,
            ]]);

            return;
        }

        $code = is_string($query['code'] ?? null) ? $query['code'] : '';

        if ($code === '') {
            $this->fail($run, 'consent', ['error' => ['message' => 'Meta returned no authorisation code.']]);

            return;
        }

        $run->recordStep('consent', 'ok');

        $creds = PlatformCredentials::forMeta(MetaCredentialProfile::Candidate);

        // The secret about to be sent must be the one the run started with.
        if (! $creds->isConfigured() || ! hash_equals((string) $run->credential_fingerprint, (string) $this->credentials->fingerprint())) {
            $this->fail($run, 'token_exchange', ['error' => [
                'message' => 'The Candidate credentials changed after this test started. Start it again.',
            ]]);

            return;
        }

        try {
            $short = $this->oauth->exchangeCode($creds, $code);
            /*
             * Meta's long-lived exchange, with the SAME candidate app id and secret — for a token that
             * expires. A configuration issuing a system-user token returns one with no expiry, which
             * has nothing to extend, and exchanging it would record a refusal that is not a failure.
             */
            $longLived = $short->expiresAt !== null;
            $tokens = $longLived ? $this->oauth->refresh($creds, $short) : $short;
        } catch (Throwable $e) {
            $this->fail($run, 'token_exchange', ['error' => $this->errorFrom($e, null)]);

            return;
        }

        $run->forceFill([
            'encrypted_token' => json_encode($tokens->toStorage(), JSON_THROW_ON_ERROR),
            'token_expires_at' => $tokens->expiresAt,
            'granted_scopes' => $tokens->scope === null ? null : array_values(array_filter(preg_split('/[\s,]+/', $tokens->scope) ?: [])),
        ]);
        $run->recordStep('token_exchange', 'ok', ['detail' => [
            'long_lived_exchange' => $longLived ? 'ok' : 'not_needed (token has no expiry)',
            'expires_at' => $tokens->expiresAt?->toIso8601String(),
        ]]);
        $run->save();

        $accounts = $this->discover($run, $creds, $tokens);

        if ($accounts === null) {
            return;
        }

        $this->readAds($run, $creds, $tokens, $accounts);
    }

    /** @return list<array<string,mixed>>|null null when the step failed */
    private function discover(MetaCandidateConnection $run, PlatformCredentials $creds, OAuthTokens $tokens): ?array
    {
        $response = PlatformHttp::client('meta')->withToken($tokens->accessToken)->get($creds->apiBase().'/me/adaccounts', [
            'fields' => 'id,account_id,name,account_status,currency',
            'limit' => 50,
        ]);

        if ($response->failed()) {
            $this->fail($run, 'account_discovery', ['error' => $this->errorFromResponse($response, $tokens)]);

            return null;
        }

        $accounts = array_values(array_map(static fn ($a) => [
            'id' => (string) ($a['id'] ?? ''),
            'name' => isset($a['name']) ? mb_substr((string) $a['name'], 0, 120) : null,
            'account_status' => $a['account_status'] ?? null,
            'currency' => $a['currency'] ?? null,
        ], array_filter((array) $response->json('data', []), 'is_array')));

        $run->forceFill(['discovered_accounts' => $accounts]);

        if ($accounts === []) {
            $this->fail($run, 'account_discovery', ['error' => [
                'message' => 'The token is valid but me/adaccounts returned no ad account, so ads_read cannot be exercised.',
            ]]);

            return null;
        }

        $run->recordStep('account_discovery', 'ok', ['detail' => ['accounts' => count($accounts)]]);
        $run->save();

        return $accounts;
    }

    /** @param list<array<string,mixed>> $accounts */
    private function readAds(MetaCandidateConnection $run, PlatformCredentials $creds, OAuthTokens $tokens, array $accounts): void
    {
        $account = $accounts[0]['id'];

        $response = PlatformHttp::client('meta')->withToken($tokens->accessToken)->get($creds->apiBase().'/'.$account.'/insights', [
            'level' => 'account',
            'date_preset' => 'last_7d',
            'fields' => 'spend,impressions,clicks',
        ]);

        if ($response->failed()) {
            $this->fail($run, 'ads_read', ['error' => $this->errorFromResponse($response, $tokens), 'account' => $account]);

            return;
        }

        $run->recordStep('ads_read', 'ok', ['detail' => [
            'account' => $account,
            'request' => 'GET /{ad-account}/insights · level=account · date_preset=last_7d',
            'rows' => count((array) $response->json('data', [])),
            // Meta reports rate-limit usage in this header rather than with a 429.
            'business_use_case_usage' => MetaUsageHeader::summarise($response->header('X-Business-Use-Case-Usage')),
        ]]);
        $run->forceFill(['status' => 'succeeded', 'finished_at' => Carbon::now()])->save();
    }

    /** @param array<string,mixed> $detail */
    private function fail(MetaCandidateConnection $run, string $step, array $detail): void
    {
        $run->recordStep($step, 'failed', $detail);
        $run->forceFill(['status' => 'failed', 'finished_at' => Carbon::now()])->save();
    }

    private function firstPending(MetaCandidateConnection $run): string
    {
        foreach ($run->steps ?? [] as $step) {
            if (($step['status'] ?? null) === 'pending') {
                return (string) $step['key'];
            }
        }

        return 'ads_read';
    }

    /** @return array<string,mixed> */
    private function errorFrom(Throwable $e, ?OAuthTokens $tokens): array
    {
        if ($e instanceof ProviderRequestRefused && is_array($e->body['error'] ?? null)) {
            return $this->metaError($e->body['error'], $e->status, $tokens);
        }

        return ['message' => $this->redact($e->getMessage(), $tokens)];
    }

    /** @return array<string,mixed> */
    private function errorFromResponse(Response $response, OAuthTokens $tokens): array
    {
        $error = $response->json('error');

        return is_array($error)
            ? $this->metaError($error, $response->status(), $tokens)
            : ['http_status' => $response->status(), 'message' => $this->redact(mb_substr($response->body(), 0, 300), $tokens)];
    }

    /**
     * @param  array<string,mixed>  $error
     * @return array<string,mixed>
     */
    private function metaError(array $error, int $status, ?OAuthTokens $tokens): array
    {
        return [
            'http_status' => $status,
            'code' => $error['code'] ?? null,
            'subcode' => $error['error_subcode'] ?? null,
            'type' => isset($error['type']) ? (string) $error['type'] : null,
            'fbtrace_id' => isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
            'message' => $this->redact((string) ($error['message'] ?? ''), $tokens),
        ];
    }

    private function redact(string $text, ?OAuthTokens $tokens): string
    {
        $secrets = array_filter([$tokens?->accessToken, $this->credentials->value('client_secret')]);

        return mb_substr(ProviderErrorText::forReceipt((string) ProviderErrorText::forDisplay($text), array_values($secrets)), 0, 500);
    }
}
