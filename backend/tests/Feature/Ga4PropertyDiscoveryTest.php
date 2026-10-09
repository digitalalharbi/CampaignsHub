<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Measurement\Ga4DiscoveryFailed;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GA4-INTEGRATION-001 — discovery finds what the identity can see, and selects nothing.
 *
 * «DISCOVERED ≠ SELECTED. Do not sync every property the Google identity can see.»
 *
 * The rule is not pedantry. An agency's Google account commonly reaches dozens of clients'
 * properties; syncing what it can see would pull one client's web analytics into another client's
 * project. That is the same failure `ACCOUNT-SCOPE-ISOLATION-001` closes for ad accounts, which is
 * why properties are recorded as `ExternalAccount` rows and selection goes through the existing
 * binding — so the tests that matter here are about what discovery does NOT do.
 */
final class Ga4PropertyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'M', 'slug' => 'm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => 'ga4', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        /*
         * The payload is the token SET, json-encoded — that is what `TokenVault::stored()` decodes,
         * and a bare string gets it «holds no access token». A live `expires_at` is given because a
         * Google access token does expire in an hour: without one the vault reads «no stated expiry»
         * and this test would never exercise the path a real GA4 connection takes.
         */
        $credential->setPayload((string) json_encode([
            'access_token' => 'AT',
            'refresh_token' => 'RT',
            'expires_at' => now()->addDay()->toIso8601String(),
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        ]));
        $credential->save();

        $this->connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'ga4',
            'connection_name' => 'ga4-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    /** Two accounts, three properties — the shape an agency identity actually returns. */
    private function fakeSummaries(): void
    {
        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response([
                'accountSummaries' => [
                    [
                        'account' => 'accounts/100',
                        'displayName' => 'Acme Group',
                        'propertySummaries' => [
                            ['property' => 'properties/111', 'displayName' => 'Acme Store', 'propertyType' => 'PROPERTY_TYPE_ORDINARY'],
                            ['property' => 'properties/222', 'displayName' => 'Acme Blog'],
                        ],
                    ],
                    [
                        'account' => 'accounts/200',
                        'displayName' => 'Other Client',
                        'propertySummaries' => [
                            ['property' => 'properties/333', 'displayName' => 'Other Store', 'propertyType' => 'PROPERTY_TYPE_ORDINARY'],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_it_records_every_property_the_identity_can_reach(): void
    {
        $this->fakeSummaries();

        $out = app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $this->assertSame(3, $out['discovered']);
        $this->assertSame(
            ['111', '222', '333'],
            ExternalAccount::withoutGlobalScopes()
                ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
                ->orderBy('external_id')
                ->pluck('external_id')
                ->all(),
        );
    }

    /** The GA4 hierarchy is kept: a property remembers the Analytics account above it. */
    public function test_a_property_carries_the_account_it_belongs_to(): void
    {
        $this->fakeSummaries();

        app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $property = ExternalAccount::withoutGlobalScopes()->where('external_id', '111')->firstOrFail();

        $this->assertSame('Acme Store', $property->name);
        $this->assertSame('100', $property->parent_external_id);
        $this->assertSame('Acme Group', $property->parent_name);
    }

    /**
     * The claim this whole unit rests on.
     *
     * Three properties were found and NONE of them is bound to a project. A discovery that also
     * selected would be the agency-identity leak: one client's analytics in another client's project.
     */
    public function test_discovery_binds_nothing_to_any_project(): void
    {
        $this->fakeSummaries();

        app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $this->assertSame(
            0,
            ProjectIntegrationBinding::withoutGlobalScopes()->count(),
            'discovery selected a property — DISCOVERED must never mean SELECTED',
        );
    }

    /** A property is not an ad account, and nothing asking for one may be handed it. */
    public function test_a_property_is_not_filed_as_an_ad_account(): void
    {
        $this->fakeSummaries();

        app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $this->assertSame(
            0,
            ExternalAccount::withoutGlobalScopes()->where('account_type', 'ad_account')->count(),
            'a GA4 property was filed as an ad account',
        );
    }

    /** Re-running discovery updates rather than duplicates — a second look is not a second estate. */
    public function test_a_second_discovery_does_not_duplicate(): void
    {
        $this->fakeSummaries();

        app(Ga4PropertyDiscovery::class)->discover($this->connection);
        app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $this->assertSame(3, ExternalAccount::withoutGlobalScopes()->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->count());
    }

    /**
     * A refusal is a refusal, never an empty estate.
     *
     * A customer shown «no properties found» after a 403 goes looking for a problem in their
     * Analytics account that does not exist, while the real cause — a Cloud project without the
     * Admin API enabled — goes unmentioned.
     */
    public function test_a_refusal_is_raised_rather_than_returned_as_no_properties(): void
    {
        Http::fake(['*analyticsadmin.googleapis.com*' => Http::response(['error' => ['message' => 'Admin API has not been used']], 403)]);

        $this->expectException(Ga4DiscoveryFailed::class);

        app(Ga4PropertyDiscovery::class)->discover($this->connection);
    }

    /** A property type Google did not state is recorded as absent, not guessed. */
    public function test_an_unstated_property_type_is_not_invented(): void
    {
        $this->fakeSummaries();

        $out = app(Ga4PropertyDiscovery::class)->discover($this->connection);

        $blog = collect($out['properties'])->firstWhere('property_id', '222');

        $this->assertNull($blog['property_type']);
    }
}
