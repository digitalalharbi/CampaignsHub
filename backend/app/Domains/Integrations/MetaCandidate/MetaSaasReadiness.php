<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Legal\Models\DataRequest;
use App\Support\Frontend;

/**
 * META-SAAS-APP-001 — how close the SaaS Meta app is to taking customer connections, one item at a time.
 *
 * Every status is read from evidence this platform holds, never assumed:
 * - `ready`: the evidence exists (a stored value, a passed round trip, a call Meta really made, a sync that really ran).
 * - `not_verified`: the platform side is in place but nothing real has exercised it yet.
 * - `needs_credentials`: it cannot even be tried until the SaaS app's credentials are entered.
 * - `owner_action`: it lives in Meta's dashboards or is the Owner's decision; nothing here can read it.
 *
 * A passed round trip is the only thing that proves the Meta-side login configuration (redirect URI, configuration ID, app roles),
 * so that item turns `ready` from the run, never from a form. Business Verification and App Review are never readable from Meta;
 * they turn `ready` only on the Owner's recorded confirmation, which says who confirmed it and when.
 */
final class MetaSaasReadiness
{
    public const STATUSES = ['ready', 'not_verified', 'needs_credentials', 'owner_action'];

    /** @return list<array{key: string, status: string, detail: array<string, mixed>}> */
    public function items(): array
    {
        // Resolved per call, as `MetaAppRouting` does: a held instance would keep answering with the credentials it first read.
        $credentials = app(MetaSaasCredentials::class);
        $configured = $credentials->isConfigured();
        $hasSecret = $credentials->value('client_secret') !== null;
        $run = MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas);
        $runIsCurrent = $run !== null && $run->succeeded()
            && hash_equals((string) $run->credential_fingerprint, (string) $credentials->fingerprint());
        $row = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->first();
        $callbackSeen = DataRequest::query()->where('source', 'provider_callback')->where('source_provider', 'meta-saas')->exists();
        $synced = ExternalAccount::withoutGlobalScopes()->where('provider', 'meta')->whereNotNull('last_synced_at')
            ->whereHas('connection', fn ($q) => $q->withoutGlobalScopes()->where('app_profile', MetaCredentialProfile::Saas->value))
            ->exists();
        $confirmed = $row?->review_confirmed_at !== null;
        $routedToSaas = MetaAppRouting::default() === MetaCredentialProfile::Saas;

        $gate = fn (bool $readyWhen, string $otherwise = 'not_verified'): string => $readyWhen ? 'ready' : ($configured ? $otherwise : 'needs_credentials');

        return [
            ['key' => 'legal_pages', 'status' => 'ready', 'detail' => [
                'privacy_url' => Frontend::url('/privacy'),
                'terms_url' => Frontend::url('/terms'),
                'deletion_page_url' => Frontend::url('/data-deletion'),
            ]],
            ['key' => 'redirect_uri', 'status' => 'ready', 'detail' => [
                'redirect_uri' => ProviderCatalogue::get('meta')->redirectUri(),
            ]],
            ['key' => 'deletion_callback', 'status' => $callbackSeen ? 'ready' : ($hasSecret ? 'not_verified' : 'needs_credentials'), 'detail' => [
                'callback_url' => route('api.v1.data-deletion.callback', ['provider' => 'meta-saas']),
                'verified_with' => 'saas_app_secret',
                'received_from_meta' => $callbackSeen,
            ]],
            ['key' => 'credentials', 'status' => $configured ? 'ready' : 'needs_credentials', 'detail' => [
                'missing' => $credentials->missing(),
            ]],
            ['key' => 'meta_dashboard', 'status' => $runIsCurrent ? 'ready' : 'owner_action', 'detail' => [
                'proven_by' => 'test_round_trip',
            ]],
            ['key' => 'oauth_round_trip', 'status' => $gate($runIsCurrent), 'detail' => [
                'latest_run_status' => $run?->status,
                'credentials_changed_since_test' => $run !== null && $run->succeeded() && ! $runIsCurrent,
            ]],
            ['key' => 'account_discovery', 'status' => $gate($runIsCurrent && ($run->discovered_accounts ?? []) !== []), 'detail' => [
                'discovered' => $runIsCurrent ? count($run->discovered_accounts ?? []) : null,
            ]],
            ['key' => 'sync_and_reports', 'status' => $gate($synced), 'detail' => [
                'synced_account_on_saas_connection' => $synced,
            ]],
            ['key' => 'business_verification', 'status' => $confirmed ? 'ready' : 'owner_action', 'detail' => $this->confirmation($row)],
            ['key' => 'app_review', 'status' => $confirmed ? 'ready' : 'owner_action', 'detail' => $this->confirmation($row)],
            ['key' => 'new_connections', 'status' => $routedToSaas ? 'ready' : 'owner_action', 'detail' => [
                'default_for_new_connections' => MetaAppRouting::default()->value,
            ]],
        ];
    }

    /** @return array{confirmed_at: string|null, confirmed_by: int|null} */
    private function confirmation(?ProviderConfiguration $row): array
    {
        return [
            'confirmed_at' => $row?->review_confirmed_at?->toIso8601String(),
            'confirmed_by' => $row?->review_confirmed_by,
        ];
    }
}
