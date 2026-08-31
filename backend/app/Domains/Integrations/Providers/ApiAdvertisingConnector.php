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

    /**
     * One entry per call: what was asked, and what the wire said back.
     *
     * INTEG-RUNTIME §7 — «the provider returned 0 rows» is a claim about a REQUEST as much as about
     * an account, and the request is the half that was never written down. An empty body and a 200
     * look identical in the retained payload; the URL, the status and the platform's own request id
     * are what turn «they had nothing» into «they had nothing, for THIS question, and here is the
     * receipt they can look up».
     *
     * The URL carries no secret — every platform here authenticates in a header — so it is recorded
     * whole rather than sanitised into uselessness.
     *
     * @var list<array{url:string,status:int,request_id:?string,keys:list<string>}>
     */
    protected array $callLog = [];

    /**
     * How many data records the platform actually handed this connector in the current sync.
     *
     * INTEG-RUNTIME §7 — the first of the four numbers a run has to be able to state. Without it,
     * «zero metrics» is unreadable: it is equally true of a provider that sent nothing and of a
     * parser that dropped everything, and those have different owners. Each connector increments this
     * where it iterates the provider's OWN records, before any of our guards can drop one — so the
     * count is what arrived, not what survived.
     *
     * The unit is whatever the platform returns a record IN, and that differs by design: a Snapchat
     * timeseries point, a Meta insight row, an X entity. The number is not comparable across
     * platforms and is not meant to be. It answers one question — «did they send us anything?» — and
     * `parsed_rows` beside it answers «and what did we make of it?».
     */
    protected int $rawInsightRows = 0;

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
     * The layer beneath a campaign, in this platform's own words (STRUCT-001).
     *
     * `campaign_external_id` is the PLATFORM's campaign id, because the connector has never seen our
     * rows and cannot resolve one. A platform with no such level returns `[]`.
     *
     * @return list<array{external_id:string,campaign_external_id:string,name:string,status:string,optimization_goal?:?string,bid_strategy?:?string,daily_budget?:?float,lifetime_budget?:?float,currency?:?string,targeting?:?array<string,mixed>,starts_at?:?string,ends_at?:?string,raw:array<string,mixed>}>
     */
    abstract protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array;

    /**
     * The ads, each with whatever the platform says about its creative.
     *
     * `creative` is present only when the platform actually identifies one. A thumbnail or preview URL
     * is passed through or left null — never constructed, because a fabricated preview is indist-
     * inguishable from a real one at a glance and wrong in a way nobody checks.
     *
     * @return list<array{external_id:string,ad_set_external_id:?string,campaign_external_id:?string,name:string,status:string,review_status?:?string,destination_url?:?string,creative?:array{external_id:string,name?:?string,format?:?string,thumbnail_url?:?string,preview_url?:?string,asset_url?:?string,video_url?:?string,asset_expires_at?:?string,media_id?:?string,source_updated_at?:?string},raw:array<string,mixed>}>
     */
    abstract protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array;

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

    /**
     * What THIS token can reach — the operation an OAuth callback performs before anything is saved.
     *
     * Separate from `listAdAccounts()` because the two ask different questions. That one reads a
     * stored connection and answers «what does this workspace already have»; this one takes a token
     * that has just come back from the provider and answers «what did this person actually authorise
     * us to see», which is the only honest basis for the account-selection step.
     *
     * It is also the seam the tenancy tests drive: two tokens, two answers, proven rather than
     * assumed (SNAP-ORG-001).
     */
    public function discoverAdAccounts(OAuthTokens $tokens): array
    {
        return $this->fetchAdAccounts($tokens);
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

    public function syncAdSets(string $adAccountId): SyncResult
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return SyncResult::of($this->fetchAdSets($this->tokens(), $adAccountId));
        } catch (Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    public function syncAds(string $adAccountId): SyncResult
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return SyncResult::of($this->fetchAds($this->tokens(), $adAccountId));
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

            /*
             * Google Ads needs the developer token on every call — that one IS ours, it identifies
             * this application to Google and is approved separately from the OAuth client.
             *
             * `login-customer-id` is not (GADS-MCC-001). It names the manager account through which
             * the caller reaches the client account being queried, so it varies per customer and is
             * asked of the connector rather than read from platform configuration.
             */
            'google' => $request->withToken($tokens->accessToken)->withHeaders(array_filter([
                'developer-token' => $creds->get('developer_token'),
                'login-customer-id' => $this->loginCustomerId(),
            ], static fn ($v) => $v !== null)),

            // LinkedIn rejects an unpinned REST call outright.
            'linkedin' => $request->withToken($tokens->accessToken)->withHeaders([
                'LinkedIn-Version' => (string) $creds->get('version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ]),

            default => $request->withToken($tokens->accessToken),
        };
    }

    /**
     * The manager account the CURRENT call is being made through, when the provider needs one.
     *
     * Null everywhere except Google Ads, which overrides it. It is a method rather than a credential
     * because the answer depends on which customer is being queried — see GADS-MCC-001.
     */
    protected function loginCustomerId(): ?string
    {
        return null;
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
        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        /*
         * Recorded BEFORE the success check, deliberately.
         *
         * A refusal is the case a diagnosis most needs the receipt for, and the old order threw the
         * exception first — so the one call anybody wanted to see was the one call that left no trace
         * of its status or its request id.
         */
        $this->callLog[] = [
            'url' => (string) $response->effectiveUri(),
            'status' => $response->status(),
            // Snapchat and TikTok both return one; the others do not, and null says so.
            'request_id' => isset($body['request_id']) && is_scalar($body['request_id'])
                ? (string) $body['request_id']
                : null,
            'keys' => array_map(strval(...), array_keys($body)),
        ];

        if (! PlatformHttp::succeeded($response)) {
            throw new RuntimeException($this->label()." could not return {$what}: ".PlatformHttp::reason($response));
        }

        $this->rawResponses[] = $body;

        return $body;
    }

    /**
     * Take the call log for this sync, and forget it.
     *
     * Drained like the bodies and the row count, and for the same reason: one connector instance is
     * bound per sync, and a call carried into the next window would be attributed to it.
     *
     * @return list<array{url:string,status:int,request_id:?string,keys:list<string>}>
     */
    public function takeCallLog(): array
    {
        $log = $this->callLog;
        $this->callLog = [];

        return $log;
    }

    /** Record that the platform returned `$count` of its own data records. */
    protected function countRawInsightRows(int $count): void
    {
        $this->rawInsightRows += max(0, $count);
    }

    /**
     * Take the raw record count for this sync, and reset it.
     *
     * Drained for the same reason the bodies are: one connector instance is bound per sync, and a
     * count carried into the next window would attribute January's rows to February's run.
     */
    public function takeRawInsightRows(): int
    {
        $count = $this->rawInsightRows;
        $this->rawInsightRows = 0;

        return $count;
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
