<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * INTEG-OAUTH-001 — connecting a real ad account, both halves.
 *
 * ## The two halves are not equally trusted, and that is the whole design
 *
 * `start` runs inside the session: authenticated, tenant-scoped, permission-checked. It is where we
 * learn WHO is connecting and WHICH workspace they are connecting for, and it writes both into a
 * single-use state.
 *
 * `callback` is public, because the platform redirects a browser to it and nothing of the session
 * survives that hop. It therefore trusts NOTHING in its own request except the state — the tenant, the
 * user and the workspace all come out of the recorded state, never out of the query string. A callback
 * with a state we did not issue is refused before a single token is exchanged.
 *
 * ## What "connected" is allowed to mean here
 *
 * The connection is only opened after a real token exchange AND a real account listing. A token that
 * exchanges cleanly and then cannot list a single ad account is not a working connection — it is a
 * misconfigured app, and calling it connected would put a green light on a workspace that will never
 * receive a figure. That listing is the first genuine API round trip, and it is the thing that earns
 * the word.
 */
final class AdPlatformOAuthController extends Controller
{
    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly TokenVault $vault,
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /**
     * POST /integrations/{provider}/oauth/start — where to send the customer to authorise us.
     *
     * Returns the URL rather than redirecting, because the caller is an SPA doing `fetch`: a 302 to
     * another origin would be followed by the fetch and swallowed, and the customer would sit on a
     * page where nothing happened.
     */
    public function start(Request $request, string $provider, TenantContext $tenant): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $creds = $this->credentialsOr404($provider);

        /*
         * PROVCFG-001 — a provider the platform operator has taken out of service is refused here,
         * BEFORE a state is minted and before a URL exists to be followed. The customer-facing page
         * already hides it, but an interface not drawing a button has never stopped anybody replaying
         * a request, and this is the gate that actually holds.
         *
         * The refusal deliberately does NOT say which system credential is missing. That is the
         * platform operator's business; a tenant learns only that the platform is unavailable, and the
         * console is where the reason lives.
         */
        if (! $this->settings->isEnabled($creds->platform)) {
            return ApiResponse::error(
                message: 'This platform is currently unavailable.',
                errors: ['provider' => [$creds->platform]],
                meta: ['status' => 'disabled', 'provider' => $creds->platform],
                status: 422,
            );
        }

        // An unconfigured platform has no authorise URL to build. Saying so is the honest answer; a
        // half-built URL would send the customer to a platform error page with our name on it.
        if (! $creds->isConfigured()) {
            return ApiResponse::error(
                message: 'This platform is awaiting credentials.',
                errors: ['provider' => [$creds->platform]],
                meta: ['status' => 'awaiting_credentials', 'provider' => $creds->platform],
                status: 422,
            );
        }

        /*
         * OAUTH-WS-001 — the workspace this connection will be filed under must be OURS.
         *
         * This was `['sometimes','nullable','uuid']`: a check on the shape of a string and nothing
         * else. The value went straight into the state, and on the callback it was stamped onto the
         * `ProviderConnection` and onto every discovered `ExternalAccount` — none of which ever asked
         * whether the workspace belonged to this tenant, or existed at all. An operator of tenant A
         * could post tenant B's client-workspace id and file a real, live platform credential under
         * another company's client.
         *
         * `ClientWorkspace` is `BelongsToTenant`, but a global scope defends queries, and this code
         * ran no query — it moved a string from a request body to a column. So the check goes where
         * the string arrives, using the rule `TaskController`, `ProjectController` and
         * `AICredentialController` already use for this same field. `deleted_at` is included because
         * the table is soft-deleted, and a live credential filed against a client the agency has
         * already removed is a connection no surface will ever show.
         */
        $validated = $request->validate([
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
        ]);

        /*
         * X-PKCE-001 — the code verifier, minted here and carried by the state.
         *
         * Deliberately NOT the session. This callback is a public route with nothing of the session
         * left by the time the browser returns, which is the whole reason `AuthorizationState` exists;
         * putting the verifier there gives it the same properties for free — single use, short lived,
         * and bound to the tenant, user and provider that started the flow.
         *
         * Null for every provider that does not publish PKCE, so nothing changes for the other five.
         */
        $verifier = $this->oauth->codeVerifier($creds);

        $state = AuthorizationState::issue(
            tenantId: (string) $tenant->tenantId(),
            provider: $creds->platform,
            userId: $request->user()->getKey(),
            clientWorkspaceId: $validated['client_workspace_id'] ?? null,
            extra: $verifier === null ? [] : ['code_verifier' => $verifier],
        );

        return ApiResponse::success([
            'provider' => $creds->platform,
            'authorization_url' => $this->oauth->authorizationUrl($creds, $state, $verifier),
            'expires_in_minutes' => (int) config('ad_platforms.state_ttl_minutes', 15),
        ], 'Authorization URL issued.');
    }

    /**
     * GET /oauth/ads/{provider}/callback — the platform sends the browser back here.
     *
     * Always ends in a redirect to the SPA, never a JSON body: a human is looking at this, and a page
     * of JSON is how an integration that actually worked gets reported as broken.
     */
    public function callback(Request $request, string $provider, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $creds = $this->credentialsOr404($provider);

        // The platform's own refusal — the customer pressed "cancel", or the app is not approved.
        if ($request->has('error')) {
            return $this->back($creds->platform, 'denied', (string) $request->query('error_description', (string) $request->query('error')));
        }

        $record = AuthorizationState::claim((string) $request->query('state', ''), $creds->platform);

        if ($record === null) {
            // Deliberately vague to the browser, because this is the branch an attacker sees.
            return $this->back($creds->platform, 'invalid_state', 'This authorisation link has expired or was already used.');
        }

        /*
         * TIKTOK-AUTH-001 — which query parameter carries the exchangeable code is the provider's
         * decision, not ours. TikTok's redirect carries `code` AND `auth_code` with different values
         * and only the second can be exchanged; everyone else uses `code`. The knowledge lives beside
         * the other TikTok bends in `PlatformOAuth` rather than as a branch in this controller.
         */
        $parameter = $this->oauth->callbackCodeParameter($creds);
        $code = (string) $request->query($parameter, '');

        if ($code === '') {
            return $this->back($creds->platform, 'failed', 'The platform returned no authorisation code.');
        }

        $tenantId = (string) $record['tenant_id'];
        $tenant->setTenantId($tenantId);

        try {
            // The verifier comes out of the RECORD, never the query string — the same rule as the
            // tenant and the workspace, and for the same reason: nothing in this request is trusted.
            $tokens = $this->oauth->exchangeCode(
                $creds,
                $code,
                isset($record['code_verifier']) ? (string) $record['code_verifier'] : null,
            );

            $connection = $this->vault->open(
                tenantId: $tenantId,
                provider: $creds->platform,
                tokens: $tokens,
                connectionName: $creds->label(),
                clientWorkspaceId: isset($record['client_workspace_id']) ? (string) $record['client_workspace_id'] : null,
                createdBy: isset($record['user_id']) ? (int) $record['user_id'] : null,
            );

            // The first real round trip. Until this returns, nothing is called connected.
            $discovered = $this->discoverAccounts($connection);
        } catch (Throwable $e) {
            return $this->back($creds->platform, 'failed', $e->getMessage());
        }

        $audit->log(
            action: 'integration.connection.opened',
            entityType: ProviderConnection::class,
            entityId: (string) $connection->getKey(),
            after: ['provider' => $creds->platform, 'ad_accounts' => $discovered],
        );

        return $this->back($creds->platform, 'connected', null, $discovered);
    }

    /**
     * Pull the authorised identity's ad accounts into `external_accounts`.
     *
     * Upserted on `(connection, external_id, account_type)` — the table's own unique key — so
     * re-authorising an existing connection updates the accounts it already knows about instead of
     * creating a second copy of each one.
     */
    private function discoverAccounts(ProviderConnection $connection): int
    {
        $connector = $this->registry->get($connection->provider);

        if (! $connector instanceof ApiAdvertisingConnector) {
            return 0;
        }

        $accounts = $connector->withConnection($connection)->listAdAccounts();

        foreach ($accounts as $account) {
            ExternalAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'provider_connection_id' => $connection->getKey(),
                    'external_id' => $account['external_id'],
                    'account_type' => 'ad_account',
                ],
                [
                    'tenant_id' => $connection->tenant_id,
                    'client_workspace_id' => $connection->client_workspace_id,
                    'provider' => $connection->provider,
                    'name' => $account['name'],
                    'currency' => $account['currency'],
                    'timezone' => $account['timezone'],
                    'status' => $account['status'],
                    'parent_external_id' => $account['parent_external_id'],
                    'metadata' => ['discovered_at' => Carbon::now()->toIso8601String()],
                    'last_synced_at' => Carbon::now(),
                ],
            );
        }

        $connection->forceFill(['last_health_check_at' => Carbon::now()])->save();

        return count($accounts);
    }

    private function credentialsOr404(string $provider): PlatformCredentials
    {
        abort_unless(in_array(AdPlatforms::canonical($provider), AdPlatforms::ORDER, true), 404);

        return PlatformCredentials::for($provider);
    }

    /** Back to the SPA's integrations page, carrying an outcome it can render in the reader's language. */
    private function back(string $provider, string $outcome, ?string $reason, int $accounts = 0): RedirectResponse
    {
        return redirect()->away(Frontend::origin().'/app/integrations?'.http_build_query(array_filter([
            'provider' => $provider,
            'outcome' => $outcome,
            'accounts' => $outcome === 'connected' ? (string) $accounts : null,
            // Trimmed: a provider error body can be a page long and this is going in a URL.
            'reason' => $reason === null ? null : mb_substr($reason, 0, 180),
        ], static fn ($v) => $v !== null && $v !== '')));
    }
}
