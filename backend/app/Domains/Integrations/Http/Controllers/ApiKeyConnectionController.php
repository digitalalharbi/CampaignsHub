<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Catalogue\ProviderAuth;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * INTEG-APIKEY-001 — connecting a provider that issues the advertiser a key instead of a consent screen.
 *
 * ## Why this is not a branch inside the OAuth controller
 *
 * `AdPlatformOAuthController` is built around a round trip: it mints a single-use state because the
 * browser leaves, and its callback is public because nothing of the session survives the return. None
 * of that exists here. The customer pastes a key into a dialog inside their own authenticated
 * session; there is no redirect, no state to claim, no public endpoint, and no third party that could
 * replay anything.
 *
 * Putting this in that controller would mean a `start` that sometimes starts nothing and a callback
 * guarding against an attack this flow cannot suffer. What the two SHARE — the vault, discovery, the
 * plan limit, the audit record — is shared by calling the same objects, not by living in one class.
 *
 * ## What «connected» means here, and it is the same rule
 *
 * A key that is accepted by the provider and then lists no ad account is not a working connection.
 * It is a key for an account we cannot read, and calling it connected puts a green light on a
 * workspace that will never receive a figure. So the key is stored and the catalogue is read inside
 * ONE transaction, and a discovery that fails — or finds nothing — takes the stored key with it.
 * Nothing is persisted by a connect attempt that did not prove the whole chain.
 *
 * ## The key itself
 *
 * It arrives in the body, is handed straight to the vault, and is never written anywhere else: not
 * into the response, not into the audit record, not into a log line, and not into the connection row.
 * The only thing any surface gets back is its last four characters, which is enough for somebody to
 * recognise which key they pasted and useless to anybody who did not have it already.
 */
final class ApiKeyConnectionController extends Controller
{
    public function __construct(
        private readonly TokenVault $vault,
        private readonly AccountDiscovery $discovery,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /**
     * POST /integrations/{provider}/api-key/connect — store a key, prove it, open the connection.
     *
     * Also the ROTATION flow, by construction rather than by a second endpoint: `TokenVault::open()`
     * re-credentials the existing connection for this (tenant, workspace, provider) in place, so a
     * customer who rotates the key in the provider's console and pastes the new one here keeps the
     * same connection, the same discovered accounts and the same bindings. A second endpoint would
     * be a second place where «which connection does this belong to» is decided.
     */
    public function store(Request $request, string $provider, TenantContext $tenant, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        abort_unless(ProviderCatalogue::has($provider), 404, 'Unknown provider.');
        $definition = ProviderCatalogue::get($provider);

        /*
         * A provider with a consent screen may not be connected by pasting a key.
         *
         * Not a formality: the two flows store the same column with different meaning. An OAuth
         * provider's access token is short-lived and refreshable, and a hand-pasted one would work
         * until it expired and then fail with no refresh token to recover from — a connection that
         * breaks days later, for a reason nothing on the screen can explain.
         */
        if ($definition->auth !== ProviderAuth::ApiKey) {
            return ApiResponse::error(
                message: $definition->label.' is connected by authorising it, not by entering a key.',
                errors: ['provider' => [$definition->key]],
                meta: ['status' => 'wrong_auth_kind', 'provider' => $definition->key, 'auth' => $definition->auth->value],
                status: 422,
            );
        }

        // PROVCFG-001 — a provider the platform operator has taken out of service is refused before
        // a secret is accepted, for the same reason the OAuth flow refuses it before a state exists.
        if (! $this->settings->isEnabled($definition->key)) {
            return ApiResponse::error(
                message: 'This platform is currently unavailable.',
                errors: ['provider' => [$definition->key]],
                meta: ['status' => 'disabled', 'provider' => $definition->key],
                status: 422,
            );
        }

        $validated = $request->validate([
            /*
             * A shape check, and deliberately a loose one.
             *
             * The real test of a key is the provider's own answer, which this flow performs before
             * anything is called connected. A strict pattern here would be us guessing at a format
             * the provider has not promised — and the failure mode of guessing wrong is refusing a
             * valid key with «invalid», which is unanswerable for the person holding it.
             *
             * The bounds exist so an empty box and a pasted document are both refused without a
             * round trip.
             */
            'api_key' => ['required', 'string', 'min:16', 'max:500'],
            // OAUTH-WS-001 — the same ownership rule as the consent flow, because the consequence is
            // identical: this decides whose client a live credential is filed under.
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('projects', 'id')
                ->where('tenant_id', $tenant->tenantId())
                ->whereNull('deleted_at')],
        ]);

        $key = trim($validated['api_key']);

        /*
         * No expiry, and that is a fact about the credential rather than a default.
         *
         * `OAuthTokens::isExpired()` returns false for a null expiry, so `TokenVault::fresh()` hands
         * this key back untouched and never attempts a refresh. That is the correct behaviour and it
         * needs no branch: there is no authorisation server to refresh against, and a key is replaced
         * by rotating it, which comes back through this same endpoint.
         */
        $tokens = new OAuthTokens(accessToken: $key, refreshToken: null, expiresAt: null);

        try {
            [$connection, $discovered] = DB::transaction(function () use ($tenant, $definition, $tokens, $validated, $request): array {
                $connection = $this->vault->open(
                    tenantId: (string) $tenant->tenantId(),
                    provider: $definition->key,
                    tokens: $tokens,
                    connectionName: $definition->label,
                    clientWorkspaceId: $validated['client_workspace_id'] ?? null,
                    createdBy: $request->user()->getKey(),
                    credentialType: 'api_key',
                );

                $discovered = $this->discovery->refresh($connection)['discovered'];

                /*
                 * A key the provider accepts for an account we cannot list is not a connection.
                 *
                 * Thrown rather than returned so the transaction unwinds: the credential row, the
                 * connection row and the health stamps all go, and the customer is exactly where
                 * they were before they pressed connect. A stored key that reads nothing is worse
                 * than no key — it is a connection somebody will point at when the numbers are
                 * missing.
                 */
                if ($discovered === 0) {
                    throw new RuntimeException('The key was accepted, but it lists no ad account.');
                }

                return [$connection, $discovered];
            });
        } catch (Throwable $e) {
            /*
             * Redacted before it is shown. A provider's own failure text quotes the request that
             * failed, and the request that failed was made with this key in a header
             * (SecretNeverInLoggedUrlTest holds the same rule for URLs).
             */
            return ApiResponse::error(
                message: ProviderErrorText::forDisplay($e->getMessage()),
                errors: ['api_key' => [ProviderErrorText::forDisplay($e->getMessage())]],
                meta: ['status' => 'rejected', 'provider' => $definition->key],
                status: 422,
            );
        }

        $audit->log(
            action: 'integration.connection.opened',
            entityType: ProviderConnection::class,
            entityId: (string) $connection->getKey(),
            // The provider, the count and the KIND of credential. Never the credential.
            after: ['provider' => $definition->key, 'ad_accounts' => $discovered, 'credential_type' => 'api_key'],
        );

        return ApiResponse::success([
            'provider' => $definition->key,
            'connection' => (string) $connection->getKey(),
            'accounts' => $discovered,
            // Enough to recognise, useless to reuse — and the only form of the key that ever leaves
            // this process.
            'key_hint' => mb_substr($key, -4),
            'project_id' => $validated['project_id'] ?? null,
        ], 'Connected.', status: 201);
    }
}
