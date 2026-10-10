<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ATTRIB-VIS-001 — attribution is a permission on the link, and off means absent.
 *
 * The panel says «the platforms claim 1,169 orders and your shop recorded 640». That is the most
 * useful page in the product for an advertiser, and it is also a sentence about the agency's own
 * reporting — so publishing it is a per-link decision, not a default.
 *
 * The tests that matter here are the refusals. A section removed from the interface while its data
 * still travels in the JSON is not a permission, it is a CSS rule.
 */
final class SharedAttributionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Report $report;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-attrib', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-attrib']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@attrib.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-attrib', 'mode' => 'managed']);
        $project = $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'monthly', 'status' => 'completed',
            'currency' => 'SAR',
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31'], 'kpis' => ['spend' => 100]],
        ]);
        app(TenantContext::class)->forget();
    }

    /**
     * Off by default — including for every link created before this existed.
     *
     * The alternative would have published the platform-versus-store comparison on every live
     * client link the day it shipped, and nobody would have been asked.
     */
    public function test_a_link_that_says_nothing_may_not_open_the_attribution_section(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [], $this->owner->id);

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertNotFound();

        $sections = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.sections');
        $this->assertFalse($sections['attribution']);
    }

    /** An operator who asks for it gets it, through the same address. */
    public function test_a_link_that_carries_the_section_opens_it(): void
    {
        [, $raw] = app(ShareService::class)->create(
            $this->report,
            ['settings' => ['sections' => ['attribution' => true]]],
            $this->owner->id,
        );

        $this->assertTrue($this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.sections.attribution'));
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")
            ->assertOk()
            // §14.9's own vocabulary: what each platform claims, what the shop confirmed, and the
            // deduplication status between them.
            ->assertJsonStructure(['data' => ['period', 'platform_reported', 'store_confirmed', 'dedup', 'models']]);
    }

    /**
     * Off means the DOCUMENT never holds it, which is what makes the export safe.
     *
     * A block stripped from the interface but still present in the snapshot payload would ride into
     * the PDF, the XLSX and the CSV, all of which render the same document.
     */
    public function test_the_document_payload_never_carries_an_attribution_block(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [], $this->owner->id);

        $body = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');

        $this->assertArrayNotHasKey('attribution', $body);
        $this->assertStringNotContainsString('store_confirmed', json_encode($body));
    }

    /** A revoked link loses the section with everything else. */
    public function test_a_revoked_link_cannot_open_the_section(): void
    {
        [$share, $raw] = app(ShareService::class)->create(
            $this->report,
            ['settings' => ['sections' => ['attribution' => true]]],
            $this->owner->id,
        );
        $share->forceFill(['revoked_at' => now()])->saveQuietly();

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertNotFound();
    }

    /** A malformed settings blob is «closed», never «whatever that value coerces to». */
    public function test_a_malformed_settings_blob_closes_the_section(): void
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, [], $this->owner->id);
        $share->forceFill(['settings' => ['sections' => 'yes please']])->saveQuietly();

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertNotFound();
    }

    /** The operator's own endpoint reports the flag back, so the builder can show what it set. */
    public function test_the_builder_reads_back_what_it_stored(): void
    {
        $project = $this->report->project_id;

        $created = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$project}/reports/{$this->report->id}/shares", [
                'sections' => ['attribution' => true],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($created['sections']['attribution']);
        $this->assertTrue(ReportShare::withoutGlobalScopes()->first()->sectionVisibility()->attribution);
    }

    /** Opening the section is logged, like every other read of a client link. */
    public function test_opening_the_section_is_logged(): void
    {
        [$share, $raw] = app(ShareService::class)->create(
            $this->report,
            ['settings' => ['sections' => ['attribution' => true]]],
            $this->owner->id,
        );

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertOk();

        $this->assertSame('attribution', $share->logs()->latest('id')->first()->detail);
    }

    /**
     * ATTRIBUTION-RECONCILIATION-001 · CLIENT-REPORT-ENTITY-BOUNDARY-001 — the client's ledger names
     * platforms and evidence, never a campaign. The reconciliation block joins the shared payload, and
     * the one thing in it a client link may not carry is campaign identity: the ledger row's
     * `campaign` and the models block's `campaign_names`. Spend and revenue follow the link's money
     * flags like every other figure here.
     */
    public function test_the_clients_ledger_names_platforms_and_evidence_never_a_campaign(): void
    {
        $this->seedStoreOrderOnACampaign();
        [, $raw] = app(ShareService::class)->create(
            $this->report,
            ['settings' => ['sections' => ['attribution' => true]]],
            $this->owner->id,
        );

        $data = $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertOk()->json('data');

        $this->assertTrue($data['reconciliation']['available']);
        $this->assertNotEmpty($data['reconciliation']['ledger']['rows']);
        foreach ($data['reconciliation']['ledger']['rows'] as $row) {
            $this->assertArrayNotHasKey('campaign', $row);
            $this->assertSame('meta', $row['platform']);
            $this->assertSame('utm_campaign_id', $row['method']);
        }
        foreach ($data['models'] as $model) {
            $this->assertArrayNotHasKey('campaign_names', $model);
        }
    }

    public function test_a_revenue_hiding_link_hides_the_ledgers_money_too(): void
    {
        $this->seedStoreOrderOnACampaign();
        [, $raw] = app(ShareService::class)->create(
            $this->report,
            ['settings' => ['sections' => ['attribution' => true]], 'hide_revenue' => true, 'hide_spend' => true],
            $this->owner->id,
        );

        $data = $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertOk()->json('data');
        $row = $data['reconciliation']['ledger']['rows'][0];
        $meta = collect($data['reconciliation']['platforms'])->firstWhere('provider', 'meta');

        $this->assertNull($row['revenue']);
        $this->assertNull($row['refunded']);
        $this->assertNull($meta['reconciled_revenue']);
        $this->assertNull($meta['spend']);
        $this->assertSame(1, $meta['reconciled_orders'], 'orders are the section\'s subject and are not money');
    }

    private function seedStoreOrderOnACampaign(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $open = fn (string $provider) => app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.'-'.uniqid(),
        )->getKey();
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $open('salla'), 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => 'shop-'.uniqid(), 'name' => 'Store', 'currency' => 'SAR', 'status' => 'active',
        ]);
        $ads = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $open('meta'), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'acct-'.uniqid(), 'name' => 'Ads', 'currency' => 'SAR', 'status' => 'active',
        ]);
        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Ramadan — internal name',
            'status' => 'active', 'attribution_model' => 'last_click',
        ]);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $ads->getKey(),
            'provider' => 'meta', 'external_id' => 'c-'.uniqid(), 'name' => 'Ramadan — internal name', 'status' => 'active',
            'unified_campaign_id' => $unified->getKey(),
        ]);
        foreach ([['conversions', 1], ['revenue', 300], ['spend', 100]] as [$key, $value]) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $ads->getKey(),
                'external_campaign_id' => $campaign->getKey(), 'unified_campaign_id' => $unified->getKey(), 'provider' => 'meta',
                'metric_key' => $key, 'metric_date' => '2026-07-10', 'value' => $value, 'project_currency' => 'SAR',
                'attribution_window' => 'default', 'source_type' => 'api',
            ]);
        }
        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $store->getKey(),
            'provider' => 'salla', 'external_id' => 'o-1', 'reference' => 'ORD-1', 'status' => 'completed',
            'placed_at' => Carbon::parse('2026-07-10 12:00:00'), 'currency' => 'SAR', 'total' => 300, 'refunded_total' => 20,
            'click_id' => 'fb-1', 'click_id_provider' => 'meta', 'utm_source' => 'meta',
            'external_campaign_id' => $campaign->getKey(), 'unified_campaign_id' => $unified->getKey(),
            'attribution_method' => 'utm_campaign_id',
        ]);
        app(TenantContext::class)->forget();
    }
}
