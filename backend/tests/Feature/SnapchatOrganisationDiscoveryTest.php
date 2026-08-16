<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Enums\ProviderSetupState;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Providers\SnapchatConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SNAP-ORG-001 — one Snapchat organisation must not be everybody's Snapchat organisation.
 *
 * ## The defect, and why it is a tenancy defect rather than a configuration one
 *
 * `SnapchatConnector::fetchAdAccounts()` read `organization_id` out of the SYSTEM credentials — the
 * single row a platform operator fills in at `/admin` — and asked for
 * `organizations/{that one id}/adaccounts`.
 *
 * CampaignsHub is multi-tenant. Every customer who authorises Snapchat gets their own access token,
 * belonging to their own Business Manager member, with access to their own organisation. Reading one
 * organisation id from the platform's own settings means every one of those tokens is pointed at the
 * SAME organisation — the operator's. A tenant either sees ad accounts that are not theirs, or, far
 * more likely, sees none at all, because their token has no access to that organisation and the call
 * is refused. The system-level field cannot be right for more than one customer at a time.
 *
 * ## What the provider actually offers, read from the current documentation
 *
 * `GET /v1/me/organizations?with_ad_accounts=true` — the organisations the AUTHENTICATED member can
 * reach, with their ad accounts nested and the member's role on each. Discovery is exactly what the
 * endpoint is for, so the field was never necessary; it was a workaround standing in for a call that
 * already existed.
 *
 * These tests hold the tenancy property, not the shape of one request: two tenants, two tokens, two
 * organisations, and neither may see the other's ad accounts.
 */
final class SnapchatOrganisationDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The platform's own app. Nothing here belongs to a customer.
        ProviderConfiguration::create([
            'provider' => 'snapchat',
            'environment' => 'production',
            'credentials' => ['client_id' => 'app-id', 'client_secret' => 'app-secret'],
            'enabled' => true,
        ]);
    }

    /**
     * The organisation is discovered from the TOKEN, so two tenants see two different sets.
     *
     * **The defect, pinned.** Under the old code both calls went to the operator's organisation and
     * this test could not distinguish the tenants at all.
     */
    public function test_two_tenants_discover_their_own_organisations_and_never_each_others(): void
    {
        Http::fake([
            // Tenant A's member reaches org-a; tenant B's reaches org-b. The provider tells them
            // apart by the bearer token, which is the whole point of asking it rather than us.
            'adsapi.snapchat.com/v1/me/organizations*' => Http::sequence()
                ->push($this->organisations('org-a', 'Acme Group', 'act-a', 'Acme Ads'))
                ->push($this->organisations('org-b', 'Nova Group', 'act-b', 'Nova Ads')),
        ]);

        $connector = app(SnapchatConnector::class);

        $tenantA = $connector->discoverAdAccounts(new OAuthTokens('token-a', null, null));
        $tenantB = $connector->discoverAdAccounts(new OAuthTokens('token-b', null, null));

        $this->assertSame(['act-a'], array_column($tenantA, 'external_id'));
        $this->assertSame(['act-b'], array_column($tenantB, 'external_id'));

        // And each account carries the organisation it actually came from, not a configured constant.
        $this->assertSame('org-a', $tenantA[0]['parent_external_id']);
        $this->assertSame('org-b', $tenantB[0]['parent_external_id']);
    }

    /** The account keeps what the provider stated, and invents nothing it did not. */
    public function test_discovery_preserves_what_the_provider_stated_and_assumes_nothing(): void
    {
        Http::fake([
            'adsapi.snapchat.com/v1/me/organizations*' => Http::response([
                'organizations' => [[
                    'organization' => [
                        'id' => 'org-a',
                        'name' => 'Acme Group',
                        'ad_accounts' => [[
                            'id' => 'act-a',
                            'name' => 'Acme Ads',
                            'status' => 'ACTIVE',
                            // No currency and no timezone in this payload, deliberately.
                        ]],
                    ],
                ]],
            ]),
        ]);

        $accounts = app(SnapchatConnector::class)->discoverAdAccounts(new OAuthTokens('token-a', null, null));

        $this->assertSame('Acme Ads', $accounts[0]['name']);
        // Absent is NULL, never a default. A currency nobody stated is not USD, and a report built on
        // a guessed currency is worse than one that says it does not know.
        $this->assertNull($accounts[0]['currency']);
        $this->assertNull($accounts[0]['timezone']);
    }

    /**
     * SNAP-ORG-001b — the system form no longer asks for an organisation id.
     *
     * A field that cannot be correct for more than one customer must not be presented as a required
     * platform credential; asking for it is how the defect was introduced.
     */
    public function test_the_platform_form_does_not_ask_for_an_organisation_id(): void
    {
        $keys = array_map(
            static fn ($f) => $f->key,
            ProviderCatalogue::get('snapchat')->fields,
        );

        $this->assertNotContains('organization_id', $keys);
        $this->assertContains('client_id', $keys);
        $this->assertContains('client_secret', $keys);
    }

    /**
     * SNAP-TOKEN-001 — the stated token lifetime is the documented one.
     *
     * The interface said «نحو 30 دقيقة». The current documentation says the access token expires in
     * 3600 seconds. An operator reading 30 minutes plans refreshes, alerting and support answers
     * around a number that is half the real one — and it was written from memory, not from the docs.
     */
    public function test_the_stated_token_lifetime_matches_the_documentation(): void
    {
        $definition = ProviderCatalogue::get('snapchat');

        $this->assertStringNotContainsString('30 minute', strtolower((string) $definition->tokenNote));
        $this->assertStringNotContainsString('30 دقيقة', (string) $definition->tokenNoteAr);
        $this->assertStringContainsString('60 minute', strtolower((string) $definition->tokenNote));
    }

    /**
     * PROBE-CLAIM-001 — a credentials probe cannot make a provider «ready for production».
     *
     * The probe sends a deliberately invalid authorisation code and reads the refusal. A refusal that
     * names the GRANT proves the provider recognised our client id and secret — and that is all it
     * proves. It does not prove the scopes were approved, that any human has consented, that the app
     * passed review, or that a single ad account is reachable.
     *
     * The state machine promoted exactly that evidence to `production_ready`, which the interface
     * rendered as «جاهز للإنتاج» — the same overclaim `CLAUDE.md` forbids for `LIVE_VERIFIED`, in a
     * different enum. The honest ceiling for this evidence is «ready for a customer to start OAuth».
     */
    public function test_a_passing_credentials_probe_does_not_claim_production_readiness(): void
    {
        ProviderConfiguration::where('provider', 'snapchat')->update([
            'last_test_status' => 'passed',
            'environment' => 'production',
        ]);

        $state = app(ProviderConfigurationService::class)->state('snapchat');

        // The ceiling for probe evidence: the provider recognised our app, and nothing beyond that.
        $this->assertSame(ProviderSetupState::CredentialsVerified, $state);
        // It IS enough to let a customer begin — that is what the probe genuinely established.
        $this->assertTrue($state->allowsConnecting());
        // …and it is never an accomplishment. No state this enum can reach is live-verified, because
        // none of them observes a consent, a discovery or a sync.
        $this->assertFalse($state->isLiveVerified());

        // The overclaiming name is gone from the type altogether, so it cannot be reintroduced by
        // somebody reading the enum and assuming the case exists.
        $this->assertFalse(defined(ProviderSetupState::class.'::ProductionReady'));
        $this->assertSame(
            ['not_configured', 'awaiting_credentials', 'ready_to_connect', 'configuration_error', 'production_ready'],
            ProviderSetupState::values(),
            'the stored values stay stable — production rows and saved filters already contain them',
        );
    }

    /**
     * ENV-FAKE-001 — a switch that decides nothing must not decide anything.
     *
     * `environment` never changed an authorize URL, a token URL or an API base for any provider here;
     * none of the eight has a separate sandbox host wired. Its single effect was gating the
     * «جاهز للإنتاج» badge, so once that overclaim went it was a control with no behaviour at all,
     * presented to an operator as though they were staging a rollout.
     *
     * The verdict is now identical on either side, and the column is still stored so no production
     * row loses its value.
     */
    public function test_the_verdict_is_the_same_whichever_environment_is_stored(): void
    {
        $service = app(ProviderConfigurationService::class);

        ProviderConfiguration::where('provider', 'snapchat')->update([
            'last_test_status' => 'passed',
            'environment' => 'sandbox',
        ]);
        $service->forgetCache('snapchat');
        $inSandbox = $service->state('snapchat');

        ProviderConfiguration::where('provider', 'snapchat')->update(['environment' => 'production']);
        $service->forgetCache('snapchat');
        $inProduction = $service->state('snapchat');

        $this->assertSame($inSandbox, $inProduction);
        $this->assertSame(ProviderSetupState::CredentialsVerified, $inProduction);
        // The stored value is preserved either way — this removes a control, not a column.
        $this->assertSame('production', ProviderConfiguration::where('provider', 'snapchat')->value('environment'));
    }

    /**
     * SNAP-ORG-001c — retiring the field does not destroy what production already stored.
     *
     * There are real Snapchat credentials in production, and they may include the organisation id
     * this change stops asking for. Dropping a field from the catalogue must not drop the VALUE:
     * `save()` starts from the stored credentials and only ever adds or updates keys it was given,
     * so an unknown key survives every subsequent save untouched. That is asserted here rather than
     * reasoned about, because «I believe nothing deletes it» is not a safe thing to believe about
     * somebody's live configuration.
     */
    public function test_an_organisation_id_already_stored_in_production_is_never_destroyed(): void
    {
        $row = ProviderConfiguration::where('provider', 'snapchat')->firstOrFail();
        $row->credentials = [...$row->credentials, 'organization_id' => 'legacy-org-id'];
        $row->save();

        // A later save of the app credentials — the ordinary thing an operator does.
        app(ProviderConfigurationService::class)->save('snapchat', [
            'client_id' => 'rotated-app-id',
            'client_secret' => 'rotated-secret',
        ]);

        $after = ProviderConfiguration::where('provider', 'snapchat')->firstOrFail();

        $this->assertSame('legacy-org-id', $after->credentials['organization_id'] ?? null);
        $this->assertSame('rotated-app-id', $after->credentials['client_id']);
    }

    /** @return array<string, mixed> */
    private function organisations(string $orgId, string $orgName, string $accountId, string $accountName): array
    {
        return [
            'organizations' => [[
                'organization' => [
                    'id' => $orgId,
                    'name' => $orgName,
                    'ad_accounts' => [[
                        'id' => $accountId,
                        'name' => $accountName,
                        'currency' => 'SAR',
                        'timezone' => 'Asia/Riyadh',
                        'status' => 'ACTIVE',
                    ]],
                ],
            ]],
        ];
    }
}
