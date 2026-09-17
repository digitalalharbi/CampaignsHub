<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaCandidateRoundTrip;
use App\Domains\Integrations\MetaCandidate\MetaProfilePromotion;
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
        private readonly MetaProfilePromotion $promotion,
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

    /**
     * POST /admin/settings/integrations/meta-candidate/promote
     *
     * Candidate → Live. Refused unless the latest round trip succeeded with the credentials configured
     * NOW. Switches only the Live app identity; no customer connection, token or account is touched.
     */
    public function promote(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        $eligibility = $this->promotion->eligibility();

        if (! $eligibility['eligible']) {
            return ApiResponse::error(
                message: 'The Candidate app cannot be promoted.',
                errors: ['promotion' => [$eligibility['reason']]],
                status: 409,
            );
        }

        $result = $this->promotion->promote($request->user()?->getKey());

        $this->audit->log(
            action: 'platform.integration.meta.promoted',
            entityType: ProviderConfiguration::class,
            entityId: 'meta',
            // Four-character hints only: which app replaced which, never a value.
            before: ['app_id_hint' => $result['previous_app_id_hint']],
            after: ['app_id_hint' => $result['new_app_id_hint'], 'run_id' => MetaCandidateConnection::latestRun()?->id],
        );

        return ApiResponse::success($this->payload(), 'Candidate promoted to Live.');
    }

    /** POST /admin/settings/integrations/meta-candidate/rollback — one step back to the previous Live app. */
    public function rollback(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        $result = $this->promotion->rollback($request->user()?->getKey());

        if ($result === null) {
            return ApiResponse::error(
                message: 'There is no previous Live app to roll back to.',
                errors: ['rollback' => ['no_previous_live']],
                status: 409,
            );
        }

        $this->audit->log(
            action: 'platform.integration.meta.rolled_back',
            entityType: ProviderConfiguration::class,
            entityId: 'meta',
            after: ['app_id_hint' => $result['restored_app_id_hint']],
        );

        return ApiResponse::success($this->payload(), 'Live Meta app rolled back.');
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'credentials' => $this->credentials->summary(),
            'latest_run' => MetaCandidateConnection::latestRun()?->toReport(),
            'promotion' => [
                ...$this->promotion->eligibility(),
                'rollback_available' => $this->promotion->rollbackAvailable(),
            ],
        ];
    }
}
