<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Commerce\Install\SallaInstalls;
use App\Domains\Commerce\Install\SallaMagicLink;
use App\Domains\Commerce\Install\SallaOnboarding;
use App\Domains\Commerce\Services\StoreDiscovery;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * COMMERCE-001 — a merchant connecting their own Salla or Zid store.
 *
 * The same two-halves design as the ad-platform flow, for the same reasons, and it is worth restating
 * because this controller is a second public entry point into the same trust boundary:
 *
 * `start` runs inside the session — authenticated, tenant-scoped, permission-checked — and is where we
 * learn WHO is connecting and for WHICH client workspace. Both go into a single-use state.
 *
 * `callback` is public, because the provider redirects a BROWSER to it and nothing of the session
 * survives that hop. It therefore trusts nothing in its own request except the state: the tenant, the
 * user and the workspace all come out of the recorded state, never out of the query string.
 *
 * ## The word "connected" is earned by the store listing, not by the token
 *
 * A token that exchanges cleanly and then cannot name a single store is not a working connection — it
 * is a misconfigured app or a merchant who authorised a scope set that reads nothing. Calling that
 * connected would put a green light on a workspace that will never receive an order. The store listing
 * is the first genuine API round trip and it is what earns the word.
 *
 * ## Layer separation, restated
 *
 * Nothing on this route reads, writes or reveals a Client Secret or a Webhook Secret. Those belong to
 * `/admin` and are never returned to a tenant, in any state, including in an error message.
 */
final class StoreOAuthController extends Controller
{
    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly TokenVault $vault,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /** POST /integrations/commerce/{provider}/oauth/start */
    public function start(Request $request, string $provider, TenantContext $tenant): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $creds = $this->credentialsOr404($provider);

        // Taken out of service by the platform operator: refused before a state is minted, because an
        // interface not drawing a button has never stopped anybody replaying a request.
        if (! $this->settings->isEnabled($creds->platform)) {
            return ApiResponse::error(
                message: 'This platform is currently unavailable.',
                errors: ['provider' => [$creds->platform]],
                meta: ['status' => 'disabled', 'provider' => $creds->platform],
                status: 422,
            );
        }

        // No app registered on this install. Said without naming which system key is absent — that is
        // an instruction for the console at `/admin`, addressed to the wrong reader here.
        if (! $creds->isConfigured()) {
            return ApiResponse::error(
                message: 'This platform is awaiting credentials.',
                errors: ['provider' => [$creds->platform]],
                meta: ['status' => 'awaiting_credentials', 'provider' => $creds->platform],
                status: 422,
            );
        }

        /*
         * OAUTH-WS-001 — the same gate as the ad-platform flow, and it matters more here.
         *
         * A store connection carries orders, customers and revenue. Accepting a bare uuid meant an
         * operator of tenant A could file a live Salla or Zid credential — and everything that then
         * syncs through it — under a client workspace belonging to another company. See the fuller
         * note in `AdPlatformOAuthController::start`; this is one rule that was missing from two
         * places, so it is fixed in both rather than left half-closed.
         */
        $validated = $request->validate([
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
        ]);

        abort_unless(
            ($validated['client_workspace_id'] ?? null) === null
                || app(ClientScopeResolver::class)->canReach($request->user(), (string) $validated['client_workspace_id']),
            403,
            'You cannot connect a store for this client.',
        );

        /*
         * X-PKCE-001 — neither Salla nor Zid publishes PKCE, so this is null for both today. It is
         * threaded through anyway because the mechanism belongs to the flow rather than to one
         * provider: the defect being fixed is a catalogue that DECLARED PKCE where no code produced
         * it, and a second entry point that could not honour the declaration would recreate it.
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
     * GET /oauth/commerce/{provider}/callback — the store platform sends the browser back here.
     *
     * Always ends in a redirect to the SPA, never a JSON body: a human is looking at this page.
     */
    public function callback(Request $request, string $provider, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $creds = $this->credentialsOr404($provider);

        if ($request->has('error')) {
            return $this->back($creds->platform, 'denied', (string) $request->query('error_description', (string) $request->query('error')));
        }

        /*
         * SALLA-INSTALL-001 — a merchant who installs from Salla's side arrives with Salla's code and
         * none of our state. That is how real merchants install, so it is not «invalid state»: the
         * code is exchanged, the store kept as a pending install, and the merchant sent to claim it.
         */
        if ($creds->platform === 'salla' && ! $request->filled('state') && $request->filled('code')) {
            try {
                $claim = app(SallaInstalls::class)->fromCode((string) $request->query('code'));
            } catch (Throwable $e) {
                return $this->back($creds->platform, 'failed', ProviderErrorText::forDisplay($e->getMessage()));
            }

            /*
             * SALLA-ONBOARD-001 — try to finish the install without asking anything of the merchant.
             *
             * The pending install is turned into a workspace, a project and a connected store when Salla says
             * enough to do so safely, and the merchant is handed a single-use sign-in link. Whatever it cannot
             * decide stays a pending install for the claim page below — the path that always worked.
             */
            try {
                $install = app(SallaInstalls::class)->preview($claim);
                $result = $install === null ? null : app(SallaOnboarding::class)->handle($install, $claim);
            } catch (Throwable $e) {
                $result = null;
            }

            if ($result !== null && $result['user'] !== null) {
                $audit->log(action: 'salla.install.'.$result['outcome'], entityType: User::class, entityId: (string) $result['user']->getKey(), after: ['store' => $result['store']]);

                return redirect()->to(url('/api/v1/salla/enter').'?'.http_build_query(['t' => SallaMagicLink::issue($result['user'], (string) $result['store'])]));
            }
            if ($result !== null && $result['outcome'] === 'entered') {
                return redirect()->away(Frontend::origin().'/login?salla=signin');
            }
            if ($result !== null && in_array($result['outcome'], ['provisioned', 'attached'], true)) {
                return redirect()->away(Frontend::origin().'/login?salla=connected');
            }

            return redirect()->away(Frontend::origin().'/app/store-install?'.http_build_query(['provider' => 'salla', 'claim' => $claim]));
        }

        $record = AuthorizationState::claim((string) $request->query('state', ''), $creds->platform);

        if ($record === null) {
            // Deliberately vague, because this is the branch an attacker sees.
            return $this->back($creds->platform, 'invalid_state', 'This authorisation link has expired or was already used.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->back($creds->platform, 'failed', 'The platform returned no authorisation code.');
        }

        $tenantId = (string) $record['tenant_id'];
        $tenant->setTenantId($tenantId);

        try {
            // Out of the RECORD, never the query string — the same rule as the tenant and workspace.
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

            // The first real round trip. Until this returns a store, nothing is called connected.
            $discovered = $this->discoverStores($connection);
        } catch (Throwable $e) {
            // Redacted before it is trimmed, for the same reason as the advertising callback: a
            // provider's failure message can name the URL that failed, query string included.
            return $this->back($creds->platform, 'failed', ProviderErrorText::forDisplay($e->getMessage()));
        }

        $audit->log(
            action: 'integration.store.connected',
            entityType: ProviderConnection::class,
            entityId: (string) $connection->getKey(),
            after: ['provider' => $creds->platform, 'stores' => $discovered],
        );

        return $this->back($creds->platform, 'connected', null, $discovered);
    }

    /** GET /commerce/store-installs/{claim} — what a claim page shows: the store, never its tokens. */
    public function installPreview(string $claim): JsonResponse
    {
        $install = app(SallaInstalls::class)->preview($claim);
        abort_if($install === null, 404, 'This install link has expired or was already used.');

        return ApiResponse::success([
            'provider' => $install->provider,
            'store_name' => $install->store_name ?? $install->store_domain ?? 'Salla store '.$install->merchant_id,
            'store_domain' => $install->store_domain,
            'currency' => $install->store_currency,
            'expires_at' => $install->expires_at->toIso8601String(),
        ], 'Pending store install.');
    }

    /** POST /commerce/store-installs/{claim}/claim — a signed-in user attaches the store to their workspace. */
    public function installClaim(Request $request, string $claim, TenantContext $tenant, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        // SEC-3 — the same tenant-scoped rule as `start`, plus the caller's client ceiling: a bare uuid let a
        // claim stamp another company's (or another client's) workspace on the credential and the store.
        $validated = $request->validate([
            'client_workspace_id' => ['nullable', 'uuid', Rule::exists('client_workspaces', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
        ]);
        abort_unless(
            ($validated['client_workspace_id'] ?? null) === null
                || app(ClientScopeResolver::class)->canReach($request->user(), (string) $validated['client_workspace_id']),
            403,
            'You cannot connect a store for this client.',
        );

        try {
            $result = app(SallaInstalls::class)->claim(
                $claim,
                (string) $tenant->tenantId(),
                $request->user()->getKey() === null ? null : (int) $request->user()->getKey(),
                $validated['client_workspace_id'] ?? null,
            );
        } catch (Throwable $e) {
            return ApiResponse::error(ProviderErrorText::forDisplay($e->getMessage()), status: 410);
        }

        $audit->log(
            action: 'integration.store.claimed',
            entityType: ProviderConnection::class,
            entityId: $result['connection_id'],
            after: ['provider' => 'salla', 'stores' => $result['stores']],
        );

        return ApiResponse::success($result, 'Store claimed.');
    }

    private function discoverStores(ProviderConnection $connection): int
    {
        return app(StoreDiscovery::class)->discover($connection);
    }

    private function credentialsOr404(string $provider): PlatformCredentials
    {
        $key = strtolower(trim($provider));

        abort_unless(
            ProviderCatalogue::has($key) && ProviderCatalogue::get($key)->kind === ProviderKind::Commerce,
            404,
        );

        return PlatformCredentials::for($key);
    }

    /** Back to the SPA's integrations page, carrying an outcome it can render in the reader's language. */
    private function back(string $provider, string $outcome, ?string $reason, int $stores = 0): RedirectResponse
    {
        return redirect()->away(Frontend::origin().'/app/integrations?'.http_build_query(array_filter([
            'provider' => $provider,
            'outcome' => $outcome,
            'stores' => $outcome === 'connected' ? (string) $stores : null,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 180),
        ], static fn ($v) => $v !== null && $v !== '')));
    }
}
