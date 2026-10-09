<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * GA4-INTEGRATION-001 — a customer authorising their own Google Analytics 4 property.
 *
 * The same two halves as the advertising and commerce flows, for the same reasons: `start` runs inside
 * the session, where WHO is connecting and for WHICH client workspace are known, and puts both into a
 * single-use state; `callback` is public, because Google redirects a BROWSER to it and no session
 * cookie, bearer token or tenant header survives that hop — so it trusts nothing in its own request
 * except the state it claims.
 *
 * ## Why this is a third controller rather than a branch in the advertising one
 *
 * Connecting an ad platform means discovering AD ACCOUNTS; connecting a store means discovering
 * STORES; connecting GA4 means discovering PROPERTIES. They share the OAuth machinery and nothing
 * after it. A single callback branching on the provider is how the three sets of rules drift into each
 * other, and the specific drift to avoid here is the one the owner named: GA4 counted as an
 * advertising platform, and its revenue blended into a return beside figures it is not comparable
 * with.
 *
 * ## «Connected» is earned by the property listing, not by the token
 *
 * A token that exchanges cleanly and then cannot name a single property is not a working connection —
 * it is a Cloud project without the Admin API enabled, or an identity with no Analytics access.
 * Calling that connected would put a green light on a workspace that will never receive a figure.
 *
 * ## And discovery selects nothing
 *
 * An agency's Google account commonly reaches dozens of clients' properties. Everything found is
 * recorded as DISCOVERED and bound to no project; selection is a separate, explicit action. See
 * `Ga4PropertyDiscovery`.
 */
final class MeasurementOAuthController extends Controller
{
    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly TokenVault $vault,
        private readonly Ga4PropertyDiscovery $discovery,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /** POST /integrations/measurement/{provider}/oauth/start */
    public function start(Request $request, string $provider, TenantContext $tenant): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $creds = $this->credentialsOr404($provider);

        // Taken out of service by the platform operator. Refused before a state is minted, because an
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
         * OAUTH-WS-001 — the workspace is proven to be this tenant's, not merely well-formed.
         *
         * The same gate as the other two flows. A measurement connection carries the client's own
         * site traffic and on-site revenue; accepting a bare uuid would let an operator of tenant A
         * file a live Analytics credential under a client workspace belonging to another company.
         */
        $validated = $request->validate([
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
        ]);

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
     * GET /oauth/measurement/{provider}/callback — Google sends the browser back here.
     *
     * Always ends in a redirect to the SPA, never a JSON body: a human is looking at this page.
     */
    public function callback(Request $request, string $provider, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $creds = $this->credentialsOr404($provider);

        if ($request->has('error')) {
            return $this->back($creds->platform, 'denied', (string) $request->query('error_description', (string) $request->query('error')));
        }

        $record = AuthorizationState::claim((string) $request->query('state', ''), $creds->platform);

        if ($record === null) {
            // Deliberately vague, because this is the branch an attacker sees.
            return $this->back($creds->platform, 'invalid_state', 'This authorisation link has expired or was already used.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->back($creds->platform, 'failed', 'Google returned no authorisation code.');
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

            // The first real round trip. Until this names a property, nothing is called connected.
            $found = $this->discovery->discover($connection);
        } catch (Throwable $e) {
            // Redacted before it is trimmed: a Google failure message can name the URL that failed,
            // query string and all, and an access token has been seen in one.
            return $this->back($creds->platform, 'failed', ProviderErrorText::forDisplay($e->getMessage()));
        }

        $audit->log(
            action: 'integration.measurement.connected',
            entityType: ProviderConnection::class,
            entityId: (string) $connection->getKey(),
            after: ['provider' => $creds->platform, 'properties' => $found['discovered']],
        );

        return $this->back($creds->platform, 'connected', null, $found['discovered']);
    }

    private function credentialsOr404(string $provider): PlatformCredentials
    {
        $key = strtolower(trim($provider));

        abort_unless(
            ProviderCatalogue::has($key) && ProviderCatalogue::get($key)->kind === ProviderKind::Measurement,
            404,
        );

        return PlatformCredentials::for($key);
    }

    /** Back to the SPA's integrations page, carrying an outcome it can render in the reader's language. */
    private function back(string $provider, string $outcome, ?string $reason, int $properties = 0): RedirectResponse
    {
        return redirect()->away(Frontend::origin().'/app/integrations?'.http_build_query(array_filter([
            'provider' => $provider,
            'outcome' => $outcome,
            'properties' => $outcome === 'connected' ? (string) $properties : null,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 180),
        ], static fn ($v) => $v !== null && $v !== '')));
    }
}
