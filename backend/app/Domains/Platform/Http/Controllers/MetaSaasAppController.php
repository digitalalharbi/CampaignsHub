<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\MetaCandidate\MetaAppRouting;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateRoundTrip;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasReadiness;
use App\Domains\Integrations\MetaCandidate\MetaSaasTestWorkspace;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * META-SAAS-APP-001 — the THIRD Meta app, administered on its own: credentials (write-only), its own test round trip, and whether NEW
 * customer connections use it. There is no promote and no rollback here — this app never replaces Live, and choosing it for new
 * connections changes nothing about any existing connection.
 */
final class MetaSaasAppController extends Controller
{
    public function __construct(
        private readonly MetaSaasCredentials $credentials,
        private readonly MetaCandidateRoundTrip $roundTrip,
        private readonly AuditLogger $audit,
        private readonly MetaSaasReadiness $readiness,
        private readonly MetaSaasTestWorkspace $testWorkspace,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->payload(), 'SaaS Meta app.');
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config_id' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['string', 'max:200'],
        ]);

        $before = $this->credentials->fingerprint();
        $changed = $this->credentials->save(
            values: array_intersect_key($validated, array_flip(MetaSaasCredentials::KEYS)),
            scopes: array_key_exists('scopes', $validated) ? ($validated['scopes'] ?? []) : null,
            actorId: $request->user()?->getKey(),
        );

        // New credentials have not been tested: the app stops being the default for NEW connections until a test passes again.
        if ($changed !== [] && $before !== $this->credentials->fingerprint()) {
            ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->update(['enabled' => false]);
        }

        if ($changed !== []) {
            $this->audit->log(
                action: 'platform.integration.meta_saas.updated',
                entityType: ProviderConfiguration::class,
                entityId: 'meta.saas',
                after: ['fields_changed' => $changed],
            );
        }

        return ApiResponse::success([...$this->payload(), 'fields_changed' => $changed], 'SaaS app credentials saved.');
    }

    public function forget(string $key): JsonResponse
    {
        abort_unless(in_array($key, MetaSaasCredentials::KEYS, true), 404);

        if (! $this->credentials->forget($key)) {
            return ApiResponse::error(message: 'That credential is not stored.', errors: ['key' => [$key]], status: 422);
        }
        ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->update(['enabled' => false]);

        $this->audit->log(action: 'platform.integration.meta_saas.credential_cleared', entityType: ProviderConfiguration::class, entityId: 'meta.saas', after: ['field' => $key]);

        return ApiResponse::success($this->payload(), 'Credential cleared.');
    }

    public function start(Request $request): JsonResponse
    {
        try {
            $started = $this->roundTrip->start((int) $request->user()->getKey(), MetaCredentialProfile::Saas);
        } catch (RuntimeException) {
            return ApiResponse::error(message: 'The SaaS Meta app is not fully configured.', errors: ['missing' => $this->credentials->missing()], status: 422);
        }

        $this->audit->log(action: 'platform.integration.meta_saas.test_started', entityType: MetaCandidateConnection::class, entityId: (string) $started['run']->getKey());

        return ApiResponse::success([
            'run' => $started['run']->toReport(),
            'authorization_url' => $started['authorization_url'],
            'expires_in_minutes' => (int) config('ad_platforms.state_ttl_minutes', 15),
        ], 'Authorization URL issued.');
    }

    /** Use the SaaS app (or Live) for NEW customer connections. Existing connections never change app. */
    public function routing(Request $request): JsonResponse
    {
        $data = $request->validate(['default' => ['required', 'in:live,saas'], 'confirm' => ['required', 'accepted']]);

        if ($data['default'] === 'saas') {
            $eligibility = $this->eligibility();
            if (! $eligibility['eligible']) {
                return ApiResponse::error(message: 'The SaaS app cannot take new connections yet.', errors: ['routing' => [$eligibility['reason']]], status: 409);
            }
            // A passed test only proves the app works for its own app roles. Customers can authorise it only once Meta approved it.
            if ($this->saasRow()?->review_confirmed_at === null) {
                return ApiResponse::error(message: 'Meta has not been confirmed to have approved the SaaS app.', errors: ['routing' => ['app_review_not_confirmed']], status: 409);
            }
        }

        $row = ProviderConfiguration::query()->firstOrNew(['provider' => MetaCredentialProfile::Saas->storageKey()], ['environment' => 'production']);
        $row->enabled = $data['default'] === 'saas';
        $row->save();

        $this->audit->log(action: 'platform.integration.meta_saas.routing_changed', entityType: ProviderConfiguration::class, entityId: 'meta.saas', after: ['default_for_new_connections' => $data['default']]);

        return ApiResponse::success($this->payload(), 'New Meta connections now use the '.($data['default'] === 'saas' ? 'SaaS' : 'Live').' app.');
    }

    /**
     * The Owner's statement that Meta verified the business and granted `ads_read` Advanced Access after App Review. Meta exposes
     * neither to the app, so it is recorded with who said it and when, and withdrawing it sends new connections back to Live.
     */
    public function confirmReview(Request $request): JsonResponse
    {
        $request->validate([
            'business_verified' => ['required', 'accepted'],
            'advanced_access_granted' => ['required', 'accepted'],
        ]);

        $row = ProviderConfiguration::query()->firstOrNew(['provider' => MetaCredentialProfile::Saas->storageKey()], ['environment' => 'production', 'enabled' => false]);
        $row->review_confirmed_at = now();
        $row->review_confirmed_by = $request->user()?->getKey();
        $row->save();

        $this->audit->log(action: 'platform.integration.meta_saas.review_confirmed', entityType: ProviderConfiguration::class, entityId: 'meta.saas', after: ['business_verified' => true, 'advanced_access_granted' => true]);

        return ApiResponse::success($this->payload(), 'Meta approval recorded.');
    }

    public function withdrawReview(): JsonResponse
    {
        $row = $this->saasRow();
        if ($row !== null) {
            $row->review_confirmed_at = null;
            $row->review_confirmed_by = null;
            $row->enabled = false;
            $row->save();
        }

        $this->audit->log(action: 'platform.integration.meta_saas.review_withdrawn', entityType: ProviderConfiguration::class, entityId: 'meta.saas');

        return ApiResponse::success($this->payload(), 'Meta approval withdrawn; new connections use the Live app.');
    }

    /**
     * Create the isolated test workspace for the new app. The address is the Owner's own new login; its password is set from the
     * reset email, never here. Refused when one already exists or the address already has an account.
     */
    public function createTestWorkspace(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        try {
            $created = $this->testWorkspace->provision($data['email'], $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(message: 'The test workspace could not be created.', errors: ['email' => [$e->getMessage()]], status: 409);
        }

        $this->audit->log(action: 'platform.integration.meta_saas.test_workspace_created', entityType: ProviderConfiguration::class, entityId: 'meta.saas', after: ['tenant_id' => (string) $created['tenant']->id]);

        return ApiResponse::success($this->payload(), 'Test workspace created; a password link was emailed.', status: 201);
    }

    /** Close the test workspace to the SaaS app. Its data stays; nothing is deleted. */
    public function closeTestWorkspace(): JsonResponse
    {
        $this->testWorkspace->deactivate();
        $this->audit->log(action: 'platform.integration.meta_saas.test_workspace_closed', entityType: ProviderConfiguration::class, entityId: 'meta.saas');

        return ApiResponse::success($this->payload(), 'Test workspace closed.');
    }

    private function saasRow(): ?ProviderConfiguration
    {
        return ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->first();
    }

    /** @return array{eligible: bool, reason: string|null} */
    private function eligibility(): array
    {
        return MetaAppRouting::saasEligibility();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')
            ->selectRaw("COALESCE(app_profile, 'live') AS app, COUNT(*) AS n")->groupBy('app')->pluck('n', 'app');

        return [
            'credentials' => $this->credentials->summary(),
            'latest_run' => MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas)?->toReport(),
            'routing' => [
                'default_for_new_connections' => MetaAppRouting::default()->value,
                ...$this->eligibility(),
            ],
            // How many existing connections each app holds — read-only, and nothing here moves them.
            'connections_by_app' => ['live' => (int) ($connections['live'] ?? 0), 'saas' => (int) ($connections['saas'] ?? 0)],
            'readiness' => $this->readiness->items(),
            // The end-to-end journey in the isolated test workspace, each step read from real rows.
            'test_workspace' => $this->testWorkspace->journey(),
        ];
    }
}
