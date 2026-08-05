<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * INTEG-OAUTH-001 — the real adapter every ad platform now extends.
 *
 * It replaces `AwaitingCredentialsConnector` for the six platforms, and keeps that class's promise
 * exactly: **an unconfigured platform still reports `awaiting_credentials`, still refuses to call out,
 * and still fabricates nothing.** What changes is that a CONFIGURED platform now does the real thing
 * instead of throwing — the OAuth exchange, the account listing, the campaign discovery and the daily
 * insights, against the platform's published API.
 *
 * ## Three states this class can be in, and they are not the same
 *
 * 1. **Not configured** — no client id, secret, or whatever else that platform demands. `status()` is
 *    `awaiting_credentials`; every sync refuses with the list of what is missing.
 * 2. **Configured, no connection bound** — the platform CAN be reached, but nobody has authorised us
 *    for this tenant. `status()` is `disconnected`. This is the state the connect button acts on.
 * 3. **Configured and bound** — a real `ProviderConnection` with real tokens. Calls go out for real.
 *
 * Conflating 1 and 2 is what makes an integrations page lie: "connect" offered for a platform with no
 * app registered leads to an authorise URL that cannot be built, and "awaiting credentials" shown for a
 * platform that is fully configured tells an operator to go and find keys that are already there.
 *
 * ## What each platform still has to say for itself
 *
 * Everything above the wire is shared. Below it, the six APIs agree on nothing — not the auth header,
 * not the pagination, not the field names, not even whether HTTP 200 means success. So each connector
 * implements only `fetchAdAccounts`, `fetchCampaigns` and `fetchInsights`, and returns rows in the one
 * shape the pipeline already understands.
 */
abstract class ApiAdvertisingConnector implements AdvertisingConnector
{
    protected ?ProviderConnection $connection = null;

    /**
     * Every body this connector has been handed since the last time somebody took them.
     *
     * Collected here rather than in each connector because it must be impossible to add a platform
     * and forget: `read()` is the only way any of the six is allowed to look at a response, so
     * everything that arrives is recorded by construction (INTEG-RAW-001).
     *
     * @var list<array<string,mixed>>
     */
    protected array $rawResponses = [];

    /** The platform key in `config/ad_platforms.php` — usually the same as `key()`. */
    abstract protected function platform(): string;

    /**
     * @return list<array{external_id:string,name:string,currency:?string,timezone:?string,status:string,parent_external_id:?string,raw:array<string,mixed>}>
     */
    abstract protected function fetchAdAccounts(OAuthTokens $tokens): array;

    /**
     * @return list<array{external_id:string,name:string,status:string,objective:?string,daily_budget:?float,lifetime_budget:?float,currency:?string,raw:array<string,mixed>}>
     */
    abstract protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array;

    /**
     * Daily rows, one per campaign per day, in the shape `AccountMetricsSyncer::ingest()` reads.
     *
     * @return list<array{campaign_id:string,date:string,spend?:float,impressions?:float,clicks?:float,conversions?:float,revenue?:float,reach?:float,video_views?:float}>
     */
    abstract protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array;

    public function key(): string
    {
        return $this->platform();
    }

    public function label(): string
    {
        return $this->credentials()->label();
    }

    public function status(): ConnectorStatus
    {
        if (! $this->credentials()->isConfigured()) {
            return ConnectorStatus::AwaitingCredentials;
        }

        if ($this->connection === null) {
            return ConnectorStatus::Disconnected;
        }

        return match ($this->connection->status) {
            'connected' => ConnectorStatus::Connected,
            'error' => ConnectorStatus::Error,
            default => ConnectorStatus::Disconnected,
        };
    }

    /**
     * Bind this connector to a tenant's connection.
     *
     * Returns a CLONE, because the registry hands out one instance per platform for the whole process
     * and binding the shared instance would leak one tenant's connection into another tenant's call —
     * a fail-open of exactly the kind the isolation rules exist to prevent.
     */
    public function withConnection(ProviderConnection $connection): static
    {
        if ($connection->provider !== $this->platform() && $connection->provider !== $this->key()) {
            throw new RuntimeException("A {$connection->provider} connection cannot drive the {$this->key()} connector.");
        }

        $bound = clone $this;
        $bound->connection = $connection;

        return $bound;
    }

    public function authorizationUrl(string $state): string
    {
        return app(PlatformOAuth::class)->authorizationUrl($this->credentials(), $state);
    }

    /**
     * The callback half of the flow.
     *
     * It exchanges and RETURNS a status only; persisting the tokens is the controller's job, because
     * only the request knows which tenant is authorising. A connector that wrote to the database from
     * here would have to guess.
     *
     * @param  array<string,mixed>  $callback
     */
    public function handleCallback(array $callback): ConnectorStatus
    {
        $code = (string) ($callback['code'] ?? '');

        if ($code === '' || ! $this->credentials()->isConfigured()) {
            return ConnectorStatus::AwaitingCredentials;
        }

        try {
            app(PlatformOAuth::class)->exchangeCode($this->credentials(), $code);
        } catch (Throwable) {
            return ConnectorStatus::Error;
        }

        return ConnectorStatus::Connected;
    }

    /**
     * A health check is a real, cheap call — listing accounts — not a ping.
     *
     * A connection whose token has died answers a ping perfectly well at the TCP level and fails every
     * API call, so "reachable" is not the question worth asking.
     */
    public function healthCheck(): HealthResult
    {
        if (! $this->credentials()->isConfigured()) {
            return HealthResult::down($this->label().' is awaiting credentials — missing: '.implode(', ', $this->credentials()->missing()).'.');
        }

        if ($this->connection === null) {
            return HealthResult::down($this->label().' is configured but nobody has authorised it for this workspace yet.');
        }

        try {
            $accounts = $this->fetchAdAccounts($this->tokens());
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        return HealthResult::ok(count($accounts).' ad account(s) reachable.');
    }

    public function listAdAccounts(): array
    {
        if ($this->status() !== ConnectorStatus::Connected) {
            return [];
        }

        return $this->fetchAdAccounts($this->tokens());
    }

    public function syncCampaigns(string $adAccountId): SyncResult
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return SyncResult::of($this->fetchCampaigns($this->tokens(), $adAccountId));
        } catch (Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    public function syncInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return SyncResult::of($this->fetchInsights($this->tokens(), $adAccountId, $from, $to));
        } catch (Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    // ── Shared plumbing ───────────────────────────────────────────────────────────────────────

    protected function credentials(): PlatformCredentials
    {
        return PlatformCredentials::for($this->platform());
    }

    protected function tokens(): OAuthTokens
    {
        if ($this->connection === null) {
            throw new RuntimeException($this->label().' has no connection bound.');
        }

        return app(TokenVault::class)->fresh($this->connection);
    }

    /**
     * The authenticated client for this platform.
     *
     * Each platform's own header conventions live here, in one `match`, rather than being repeated in
     * three methods per connector — a bearer header omitted from `fetchInsights` alone is a bug that
     * only shows up on the numbers, long after the connection has been declared healthy.
     */
    protected function api(OAuthTokens $tokens): PendingRequest
    {
        $creds = $this->credentials();
        $request = PlatformHttp::client($this->platform());

        return match ($this->platform()) {
            // TikTok authenticates with its own header and no scheme.
            'tiktok' => $request->withHeaders(['Access-Token' => $tokens->accessToken]),

            // Google Ads needs the developer token on every call, and the manager account when the
            // authorised identity reaches its customers through one.
            'google' => $request->withToken($tokens->accessToken)->withHeaders(array_filter([
                'developer-token' => $creds->get('developer_token'),
                'login-customer-id' => $creds->get('login_customer_id'),
            ], static fn ($v) => $v !== null)),

            // LinkedIn rejects an unpinned REST call outright.
            'linkedin' => $request->withToken($tokens->accessToken)->withHeaders([
                'LinkedIn-Version' => (string) $creds->get('version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ]),

            default => $request->withToken($tokens->accessToken),
        };
    }

    /** `{api_base}/{path}` without caring who wrote the slash. */
    protected function url(string $path): string
    {
        return $this->credentials()->apiBase().'/'.ltrim($path, '/');
    }

    /**
     * The one honest refusal, shared by both sync methods.
     *
     * It distinguishes "we have no keys" from "nobody has authorised us" from "the last attempt
     * failed", because those are three different things for the person reading the sync log — and one
     * generic «فشلت المزامنة» for all three sends them looking in the wrong place.
     */
    private function refusal(): ?SyncResult
    {
        return match ($this->status()) {
            ConnectorStatus::AwaitingCredentials => SyncResult::failed(
                $this->label().' is awaiting credentials — missing: '.implode(', ', $this->credentials()->missing()).'.',
            ),
            ConnectorStatus::Disconnected => SyncResult::failed(
                $this->label().' is configured but not authorised for this workspace.',
            ),
            ConnectorStatus::Error => SyncResult::failed(
                $this->connection?->last_error ?? ($this->label().' is in an error state.'),
            ),
            ConnectorStatus::Connected => null,
        };
    }

    /**
     * Read a platform answer, or fail with the platform's own words.
     *
     * @return array<string,mixed>
     */
    protected function read(Response $response, string $what): array
    {
        if (! PlatformHttp::succeeded($response)) {
            throw new RuntimeException($this->label()." could not return {$what}: ".PlatformHttp::reason($response));
        }

        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        $this->rawResponses[] = $body;

        return $body;
    }

    /**
     * Take the raw bodies collected so far, and forget them.
     *
     * Draining rather than reading, because a connector instance is bound per sync and holding the
     * previous window's payloads into the next one would attach a January response to a February run.
     *
     * @return list<array<string,mixed>>
     */
    public function takeRawResponses(): array
    {
        $taken = $this->rawResponses;
        $this->rawResponses = [];

        return $taken;
    }
}
