<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * META-CANDIDATE-001 — Candidate → Live, and back, as two deliberate and reversible acts.
 *
 * ## What promotion changes, and exactly nothing else
 *
 * Only the Live profile's app identity: App ID, App Secret, Configuration ID and scopes, in the Live
 * `provider_configurations` row. The previous values are kept in `meta.previous_live` for ONE-step
 * rollback. The webhook fields, `enabled` and `environment` are left as they are.
 *
 * It does not open, close, re-credential or flag a single `provider_connections` row, token or
 * external account, and forces no re-authorisation. Meta tokens are app-scoped: a token issued by the
 * old app keeps working only while that app stays valid. A connection whose long-lived token later
 * nears its own expiry is refreshed with the NEW app's credentials, which Meta refuses for another
 * app's token, so that connection is flagged for reconnect at that point, exactly as an expired token
 * would be. Rolling back restores the old app's identity for those refreshes.
 *
 * ## Refused unless the round trip that licenses it is the one now configured
 *
 * The latest candidate run must have succeeded end to end AND carry the fingerprint of the candidate
 * credentials configured right now. Change the secret or the configuration after a passing test and
 * the promotion is refused until the test is run again.
 */
final class MetaProfilePromotion
{
    public const PREVIOUS_LIVE = 'meta.previous_live';

    public const PROFILE_KEYS = ['client_id', 'client_secret', 'config_id'];

    public function __construct(
        private readonly MetaCandidateCredentials $candidate,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /** @return array{eligible: bool, reason: string|null} */
    public function eligibility(): array
    {
        if (! $this->candidate->isConfigured()) {
            return ['eligible' => false, 'reason' => 'candidate_not_configured'];
        }

        $run = MetaCandidateConnection::latestRun();

        if ($run === null || ! $run->succeeded()) {
            return ['eligible' => false, 'reason' => 'latest_round_trip_not_succeeded'];
        }

        if (! hash_equals((string) $run->credential_fingerprint, (string) $this->candidate->fingerprint())) {
            return ['eligible' => false, 'reason' => 'credentials_changed_since_round_trip'];
        }

        if ($this->candidate->value('client_id') === $this->settings->value('meta', 'client_id')) {
            return ['eligible' => false, 'reason' => 'candidate_already_live'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * The scopes the current Live profile requests that the candidate would NOT request.
     *
     * Promotion makes the candidate's scopes Live's. Dropping one silently would take a permission away
     * from every future Live authorisation, so a non-empty answer refuses promotion unless the operator
     * confirms the narrowing separately and by name.
     *
     * @return list<string>
     */
    public function droppedScopes(): array
    {
        return array_values(array_diff($this->settings->scopes('meta'), $this->candidate->scopes()));
    }

    public function rollbackAvailable(): bool
    {
        return ProviderConfiguration::query()->where('provider', self::PREVIOUS_LIVE)->exists();
    }

    /** @return array{previous_app_id_hint: string|null, new_app_id_hint: string|null} */
    public function promote(?int $actorId): array
    {
        return DB::transaction(function () use ($actorId) {
            $live = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Live->storageKey())->lockForUpdate()->first();
            $existed = $live !== null;
            $previousHint = $this->hint($this->settings->value('meta', 'client_id'));

            $live ??= new ProviderConfiguration([
                'provider' => MetaCredentialProfile::Live->storageKey(),
                'environment' => 'production',
                'enabled' => true,
            ]);

            $stored = $live->credentials ?? [];

            // The slot holds exactly what was STORED (not the env fallback), so a rollback re-creates
            // the same effective configuration, including «read from the environment».
            $slot = ProviderConfiguration::query()->where('provider', self::PREVIOUS_LIVE)->lockForUpdate()->first()
                ?? new ProviderConfiguration(['provider' => self::PREVIOUS_LIVE, 'environment' => 'production', 'enabled' => false]);
            $slot->credentials = [
                'values' => array_intersect_key($stored, array_flip(self::PROFILE_KEYS)),
                'row_existed' => $existed,
                'last_test_status' => $live->last_test_status,
                'last_test_message' => $live->last_test_message,
                'last_tested_at' => $live->last_tested_at?->toIso8601String(),
                'promoted_at' => Carbon::now()->toIso8601String(),
                'promoted_by' => $actorId,
            ];
            $slot->scopes = $live->scopes;
            $slot->save();

            $values = $this->candidate->values();
            $live->credentials = [
                ...array_diff_key($stored, array_flip(self::PROFILE_KEYS)),
                ...array_filter($values, static fn ($v) => $v !== null),
            ];
            $live->scopes = $this->candidate->scopes();
            // A different app has not been tested through the provider console; say so.
            $live->last_test_status = null;
            $live->last_test_message = null;
            $live->last_tested_at = null;
            $live->last_rotated_at = Carbon::now();
            $live->configured_at = Carbon::now();
            $live->configured_by = $actorId;
            $live->save();

            $this->settings->forgetCache('meta');

            return ['previous_app_id_hint' => $previousHint, 'new_app_id_hint' => $this->hint($values['client_id'])];
        });
    }

    /** @return array{restored_app_id_hint: string|null}|null null when there is nothing to roll back to */
    public function rollback(?int $actorId): ?array
    {
        return DB::transaction(function () use ($actorId) {
            $slot = ProviderConfiguration::query()->where('provider', self::PREVIOUS_LIVE)->lockForUpdate()->first();

            if ($slot === null) {
                return null;
            }

            $snapshot = $slot->credentials ?? [];
            $live = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Live->storageKey())->lockForUpdate()->firstOrFail();

            $live->credentials = [
                ...array_diff_key($live->credentials ?? [], array_flip(self::PROFILE_KEYS)),
                ...(array) ($snapshot['values'] ?? []),
            ];
            $live->scopes = $slot->scopes;
            $live->last_test_status = $snapshot['last_test_status'] ?? null;
            $live->last_test_message = $snapshot['last_test_message'] ?? null;
            $live->last_tested_at = $snapshot['last_tested_at'] ?? null;
            $live->last_rotated_at = Carbon::now();
            $live->configured_by = $actorId;

            // Promotion created this row where none existed: removing it restores «configured from the
            // environment» exactly, rather than leaving an empty row behind that says otherwise.
            if (($snapshot['row_existed'] ?? true) === false && $live->credentials === [] && $live->scopes === null) {
                $live->delete();
            } else {
                $live->save();
            }

            // One step: the rollback point is spent.
            $slot->delete();

            $this->settings->forgetCache('meta');

            return ['restored_app_id_hint' => $this->hint($this->settings->value('meta', 'client_id'))];
        });
    }

    private function hint(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, -4);
    }
}
