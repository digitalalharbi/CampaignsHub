<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\MetaCandidate\MetaAppRouting;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateRoundTrip;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
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
        }

        $row = ProviderConfiguration::query()->firstOrNew(['provider' => MetaCredentialProfile::Saas->storageKey()], ['environment' => 'production']);
        $row->enabled = $data['default'] === 'saas';
        $row->save();

        $this->audit->log(action: 'platform.integration.meta_saas.routing_changed', entityType: ProviderConfiguration::class, entityId: 'meta.saas', after: ['default_for_new_connections' => $data['default']]);

        return ApiResponse::success($this->payload(), 'New Meta connections now use the '.($data['default'] === 'saas' ? 'SaaS' : 'Live').' app.');
    }

    /** @return array{eligible: bool, reason: string|null} */
    private function eligibility(): array
    {
        if (! $this->credentials->isConfigured()) {
            return ['eligible' => false, 'reason' => 'not_configured'];
        }
        $run = MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas);
        if ($run === null || $run->status !== 'succeeded') {
            return ['eligible' => false, 'reason' => 'no_successful_test'];
        }
        if (! hash_equals((string) $run->credential_fingerprint, (string) $this->credentials->fingerprint())) {
            return ['eligible' => false, 'reason' => 'credentials_changed_since_test'];
        }

        return ['eligible' => true, 'reason' => null];
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
        ];
    }
}
