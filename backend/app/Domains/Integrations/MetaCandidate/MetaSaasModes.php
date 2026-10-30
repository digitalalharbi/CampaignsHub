<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\PlatformCredentials;

/**
 * META-SAAS-APP-001 — the three modes of the Meta integration, and what «SaaS Primary» would and would not change.
 *
 * - `live`: every new connection uses the current Live app. The SaaS app is not in use anywhere.
 * - `saas_test`: still Live for every customer. The SaaS app connects only inside the isolated test workspace.
 * - `saas_primary`: NEW connections in every tenant use the SaaS app. No existing connection moves: each keeps the app that issued
 *   its token (`app_profile`, `issuing_app_id`), so Live connections keep syncing with Live and their reports keep their figures.
 *
 * Primary is never assumed ready. Every precondition is read from evidence: credentials stored, a real round trip passed with
 * exactly those credentials, a real journey in the test workspace (connection, discovery, sync, metrics, report, all rows), Meta's
 * approval recorded by the Owner, and Live still configured so nothing it issued stops refreshing. Turning it on also needs the
 * Owner's named approval at the moment of the switch. Going back to Live affects new connections only.
 */
final class MetaSaasModes
{
    /** The journey steps that must have really happened in the test workspace before Primary. */
    public const REQUIRED_JOURNEY = ['oauth', 'discovery', 'sync', 'metrics', 'reports'];

    public function __construct(private readonly MetaSaasTestWorkspace $workspace) {}

    /** @return array{current: string, primary: array{enabled: bool, eligible: bool, preconditions: list<array{key: string, met: bool}>, preview: array<string, mixed>}} */
    public function state(): array
    {
        $primary = MetaAppRouting::default() === MetaCredentialProfile::Saas;
        $journey = $this->workspace->journey();
        $preconditions = $this->preconditions($journey);

        return [
            'current' => $primary ? 'saas_primary' : ($journey['active'] ? 'saas_test' : 'live'),
            'primary' => [
                'enabled' => $primary,
                'eligible' => ! in_array(false, array_column($preconditions, 'met'), true),
                'preconditions' => $preconditions,
                'preview' => $this->preview(),
            ],
        ];
    }

    /** @return list<string> the preconditions not met, in order — empty when Primary may be turned on */
    public function unmet(): array
    {
        $unmet = array_filter($this->preconditions($this->workspace->journey()), static fn (array $p) => ! $p['met']);

        return array_values(array_column($unmet, 'key'));
    }

    /**
     * What the switch would change, counted — shown before it is confirmed and recorded with it.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $active = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->where('status', '!=', 'revoked');
        $live = (clone $active)->where(fn ($q) => $q->whereNull('app_profile')->orWhere('app_profile', MetaCredentialProfile::Live->value));
        $saas = (clone $active)->where('app_profile', MetaCredentialProfile::Saas->value);

        return [
            'existing_live_connections' => (clone $live)->count(),
            'existing_live_tenants' => (clone $live)->distinct()->count('tenant_id'),
            'existing_saas_connections' => (clone $saas)->count(),
            // Fixed by design and stated so the preview cannot be read otherwise.
            'existing_connections_moved' => 0,
            'new_connections_app_when_primary' => MetaCredentialProfile::Saas->value,
            'new_connections_app_after_revert' => MetaCredentialProfile::Live->value,
        ];
    }

    /**
     * @param  array{exists: bool, active: bool, steps: array<string, bool>}  $journey
     * @return list<array{key: string, met: bool}>
     */
    private function preconditions(array $journey): array
    {
        $credentials = app(MetaSaasCredentials::class);
        $run = MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas);
        $approved = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->value('review_confirmed_at') !== null;
        $journeyDone = $journey['exists'] && ! in_array(false, array_map(static fn (string $s) => ($journey['steps'][$s] ?? false) === true, self::REQUIRED_JOURNEY), true);

        return [
            ['key' => 'credentials_configured', 'met' => $credentials->isConfigured()],
            ['key' => 'round_trip_passed', 'met' => $run !== null && $run->succeeded() && hash_equals((string) $run->credential_fingerprint, (string) $credentials->fingerprint())],
            ['key' => 'test_journey_complete', 'met' => $journeyDone],
            ['key' => 'meta_approval_recorded', 'met' => $approved],
            // Live keeps refreshing everything it issued; a Primary that left Live unconfigured would strand them.
            ['key' => 'live_still_configured', 'met' => PlatformCredentials::forMeta(MetaCredentialProfile::Live)->isConfigured()],
        ];
    }
}
