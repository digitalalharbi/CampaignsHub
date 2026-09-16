<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-KPI-TOTALS-001 — the Content library's headline figures describe the FILTER, not the page.
 *
 * «The Content area currently does not visibly expose the required KPI figures consistently … never
 * turn unavailable data into zero.» The library showed a card per creative and no totals at all, so
 * «what did this filter cost» was a question the page could not answer.
 *
 * The trap is the one `total` was already fixed for on this endpoint: a strip totalled over the
 * twenty-four cards a page happens to hold would describe one screen while sitting above a library
 * of hundreds — and it would look exactly like an answer about the library. Every case below uses a
 * page smaller than the scope, so a page-local total and a real one cannot agree.
 */
final class ContentKpiTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'Project 1',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user->assignRole($role);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
    }

    /** Ten creatives, each spending 100 and clicking 10 — a scope of 1,000 spend and 100 clicks. */
    private function estate(int $count = 10, string $provider = 'meta'): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'external_id' => 'c-'.$provider,
            'name' => 'Campaign '.$provider,
            'status' => 'active',
            'objective' => 'sales',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $campaign->id,
                'provider' => $provider,
                'external_creative_id' => "ec-{$provider}-{$i}",
                'name' => "Creative {$provider} {$i}",
                'format' => 'video',
                'video_url' => 'https://cdn.test/v.mp4',
            ]);

            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => now()->subDay()->toDateString(),
                'spend' => 100,
                'impressions' => 1000,
                'clicks' => 10,
                'conversions' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function library(array $params = []): array
    {
        $query = http_build_query($params + [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            /* Deliberately smaller than the estate: a page-local total cannot match a real one. */
            'per_page' => 3,
        ]);

        return $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/creatives?{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_the_totals_describe_the_whole_filtered_library_not_the_page(): void
    {
        $this->estate();

        $data = $this->library();

        $this->assertCount(3, $data['creatives'], 'the page itself should be three rows');
        $this->assertSame(10, $data['total']);

        $this->assertSame(1000.0, (float) $data['totals']['spend'], 'the strip totalled the page instead of the library');
        $this->assertSame(100.0, (float) $data['totals']['clicks']);
    }

    /** Narrowing the library narrows the headline with it — or the two describe different sets. */
    public function test_a_filter_moves_the_totals(): void
    {
        $this->estate(10, 'meta');
        $this->estate(4, 'snapchat');

        $all = $this->library();
        $snap = $this->library(['providers' => ['snapchat']]);

        $this->assertSame(1400.0, (float) $all['totals']['spend']);
        $this->assertSame(400.0, (float) $snap['totals']['spend'], 'the headline ignored the platform filter under it');
    }

    /**
     * Derived rates are recomputed from the POOLED sums, never averaged across creatives.
     *
     * Ten creatives at 10 clicks per 1,000 impressions each have a CTR of 1%, and so does the pool —
     * which is why the fixture makes them uniform: an averaged CTR and a pooled CTR agree here, so
     * this case alone cannot tell them apart, and the next one is built to.
     */
    public function test_the_rates_come_from_the_pooled_figures(): void
    {
        $this->estate();

        $totals = $this->library()['totals'];

        $this->assertEqualsWithDelta(0.01, (float) $totals['ctr'], 0.0001);
    }

    /** A scope the provider never reported is «no figures», never a row of zeros. */
    public function test_a_scope_with_no_reported_day_returns_null_rather_than_zeros(): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-silent',
            'name' => 'Silent',
            'status' => 'active',
            'objective' => 'sales',
        ]);

        ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-silent',
            'name' => 'Silent creative',
            'format' => 'image',
            'asset_url' => 'https://cdn.test/a.jpg',
        ]);

        $data = $this->library();

        $this->assertCount(1, $data['creatives']);
        $this->assertNull($data['totals'], 'a library nobody reported on drew a row of zeros');
    }

    /** And an empty library has no totals at all — there is nothing to total. */
    public function test_an_empty_library_has_no_totals(): void
    {
        $this->assertNull($this->library()['totals']);
    }

    /**
     * Owner defect 95 — the strip carries what the CARDS carry, on an account with no creative grain.
     *
     * This is the endpoint half of `ContentMetricCoexistenceTest`, and it is the reading the owner
     * actually reported: on a Meta, Google, TikTok, LinkedIn or X account `creative_daily_metrics` is
     * empty by design — `AccountMetricsSyncer` asks for creative-level insights behind
     * `instanceof ReportsCreativeInsights` and Snapchat is the only implementor — so the cards fell
     * back to the ads that ran each creative while the strip, which queried the creative table alone,
     * reported nothing at all. Spend on the cards, silence directly above them, one viewport.
     *
     * Asserted against the CARDS rather than against a literal, deliberately. A literal still passes
     * on the day the two drift together, and the property this endpoint owes its reader is that the
     * headline cannot contradict the rows beneath it.
     */
    public function test_the_headline_strip_answers_for_a_library_whose_figures_are_at_the_ad_grain(): void
    {
        $this->adGrainEstate();

        $data = $this->library();

        $this->assertCount(3, $data['creatives'], 'the page should be three rows of the estate');

        $spendOnCards = 0.0;
        foreach ($data['creatives'] as $row) {
            $this->assertNotNull($row['metrics'], 'a card reported nothing, so this case proves nothing');
            $spendOnCards += (float) $row['metrics']['spend'];
        }

        $this->assertGreaterThan(0.0, $spendOnCards, 'the cards carry no spend, so the strip has nothing to contradict');

        $this->assertNotNull(
            $data['totals'],
            'the cards carry spend and the headline strip above them says nothing was reported',
        );
        /* Six creatives at 50 each — the whole library, not the three-row page. */
        $this->assertSame(300.0, (float) $data['totals']['spend'], 'the strip lost the spend the cards show');
        $this->assertSame(60.0, (float) $data['totals']['leads'], 'the result the campaign was bought for never reached the strip');
        $this->assertSame(5.0, (float) $data['totals']['cpl'], 'cost per lead was not derived from the pooled figures');
    }

    /**
     * Six creatives whose figures exist ONLY at the ad grain — the shape five of six providers have.
     *
     * `external_ads.creative_id` is the canonical relation, so the ad rows are attributable; nothing
     * is written to `creative_daily_metrics`, which is exactly what a Meta sync leaves behind.
     */
    private function adGrainEstate(): void
    {
        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-leads',
            'name' => 'Lead gen',
            'status' => 'active',
            'objective' => 'leads',
        ]);

        /*
         * A real bound account, because `external_campaigns.external_account_id` is NOT NULL — the
         * schema's own statement that a provider campaign belongs to an account somebody connected.
         */
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-leads',
            'name' => 'Meta',
            'status' => 'active',
        ]);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'provider' => 'meta',
            'external_id' => 'ext-c-leads',
            'name' => 'Lead gen',
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $unified->id,
                'provider' => 'meta',
                'external_creative_id' => "ec-adgrain-{$i}",
                'name' => "Ad-grain creative {$i}",
                'format' => 'image',
                'asset_url' => 'https://cdn.test/a.jpg',
            ]);

            $ad = ExternalAd::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'external_campaign_id' => $external->getKey(),
                'creative_id' => $creative->id,
                'provider' => 'meta',
                'external_id' => "ad-adgrain-{$i}",
                'name' => "Ad {$i}",
                'status' => 'active',
                'source_type' => 'api',
            ]);

            DB::table('entity_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'meta',
                'entity_type' => 'ad',
                'entity_id' => $ad->getKey(),
                'external_entity_id' => "ad-adgrain-{$i}",
                'external_campaign_id' => $external->getKey(),
                'metric_date' => now()->subDay()->toDateString(),
                'attribution_window' => 'default',
                'spend' => 50,
                'impressions' => 2_000,
                'clicks' => 60,
                'leads' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
