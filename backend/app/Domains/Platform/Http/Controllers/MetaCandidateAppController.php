<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaCandidateRoundTrip;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * META-CANDIDATE-001 — the platform owner's Candidate Meta app console.
 *
 * Behind the `platform` gate like every provider setting. Same three rules as the provider console: a
 * secret goes in and never comes out, writes are partial, and every write is audited by FIELD NAME.
 * Nothing here reads or writes the Live Meta row, a tenant's connection, or an external account.
 */
final class MetaCandidateAppController extends Controller
{
    public function __construct(
        private readonly MetaCandidateCredentials $credentials,
        private readonly MetaCandidateRoundTrip $roundTrip,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/settings/integrations/meta-candidate */
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->payload(), 'Candidate Meta app.');
    }

    /** PUT /admin/settings/integrations/meta-candidate */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config_id' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['string', 'max:200'],
        ]);

        $changed = $this->credentials->save(
            values: array_intersect_key($validated, array_flip(MetaCandidateCredentials::KEYS)),
            scopes: array_key_exists('scopes', $validated) ? ($validated['scopes'] ?? []) : null,
            actorId: $request->user()?->getKey(),
        );

        if ($changed !== []) {
            $this->audit->log(
                action: 'platform.integration.meta_candidate.updated',
                entityType: ProviderConfiguration::class,
                entityId: 'meta.candidate',
                after: ['fields_changed' => $changed],
            );
        }

        return ApiResponse::success([...$this->payload(), 'fields_changed' => $changed], 'Candidate credentials saved.');
    }

    /** DELETE /admin/settings/integrations/meta-candidate/credentials/{key} */
    public function forget(string $key): JsonResponse
    {
        abort_unless(in_array($key, MetaCandidateCredentials::KEYS, true), 404);

        if (! $this->credentials->forget($key)) {
            return ApiResponse::error(message: 'That credential is not stored.', errors: ['key' => [$key]], status: 422);
        }

        $this->audit->log(
            action: 'platform.integration.meta_candidate.credential_cleared',
            entityType: ProviderConfiguration::class,
            entityId: 'meta.candidate',
            after: ['field' => $key],
        );

        return ApiResponse::success($this->payload(), 'Credential cleared.');
    }

    /** POST /admin/settings/integrations/meta-candidate/test — the OAuth start of a new round trip. */
    public function start(Request $request): JsonResponse
    {
        try {
            $started = $this->roundTrip->start((int) $request->user()->getKey());
        } catch (RuntimeException $e) {
            return ApiResponse::error(
                message: 'The Candidate Meta app is not fully configured.',
                errors: ['missing' => $this->credentials->missing()],
                status: 422,
            );
        }

        $this->audit->log(
            action: 'platform.integration.meta_candidate.test_started',
            entityType: MetaCandidateConnection::class,
            entityId: (string) $started['run']->getKey(),
        );

        return ApiResponse::success([
            'run' => $started['run']->toReport(),
            'authorization_url' => $started['authorization_url'],
            'expires_in_minutes' => (int) config('ad_platforms.state_ttl_minutes', 15),
        ], 'Authorization URL issued.');
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'credentials' => $this->credentials->summary(),
            'latest_run' => MetaCandidateConnection::latestRun()?->toReport(),
        ];
    }
}
