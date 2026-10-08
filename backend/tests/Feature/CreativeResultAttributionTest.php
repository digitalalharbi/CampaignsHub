<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeResultAttribution;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-GRAIN-TRUTH-001 — a campaign's results are not the creatives' results.
 *
 * The owner, with a screenshot: a campaign reporting 109 purchases above creatives every one of
 * which read «الطلبات 0» and «0.00x» — «كيف حققت الحملة أداء عائد إلى 5x بالمقابل المحتويات العائد
 * لها ضعيف جداً، غير منطقي».
 *
 * Neither figure was wrong alone, which is what made the page unreadable. Several platforms report
 * a conversion against the CAMPAIGN and return a flat zero for it on each creative: the breakdown
 * was never made, so the zero means «not at this grain», not «nobody bought».
 *
 * The discrimination this has to get right is the one that costs a real figure if it goes the wrong
 * way: ONE creative at zero inside a selling campaign is an ordinary result and must stay a zero.
 * ALL of them at zero under a campaign that sold is a missing breakdown. The tests below are mostly
 * that boundary.
 */
final class CreativeResultAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    private ProviderConnection $connection;

    private ExternalAccount $selected;

    private ExternalAccount $deselected;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'P',
            'status' => 'active',
        ]);

        $this->workspace = $client;

        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $this->connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->selected = $this->account('act-selected');
        $this->deselected = $this->account('act-deselected');

        $this->bind($this->selected, true);
        $this->bind($this->deselected, false);
    }

    private function account(string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId.'-'.uniqid(), 'name' => $externalId, 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    /**
     * A campaign-grain result, in the table that actually holds one.
     *
     * `daily_metrics`, keyed by `unified_campaign_id` with one row per `metric_key` — NOT
     * `entity_daily_metrics`, which carries `ad_set` and `ad` grains only. The first version of
     * this service read the latter and could never have matched a row; checked against the
     * database rather than assumed.
     */
    /** @var array<string, string> unified campaign id → the external campaign row backing it */
    private array $externalCampaigns = [];

    private function externalCampaign(string $unifiedId, ExternalAccount $account): string
    {
        if (isset($this->externalCampaigns[$unifiedId])) {
            return $this->externalCampaigns[$unifiedId];
        }

        /* The unified campaign the id names — `external_campaigns.unified_campaign_id` is a key. */
        DB::table('unified_campaigns')->insert([
            'id' => $unifiedId,
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'C-'.substr($unifiedId, 0, 8),
            'objective' => 'sales',
            'status' => 'active',
            'budget_currency' => 'SAR',
            'priority' => 'normal',
            'objective_source' => 'provider',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();

        DB::table('external_campaigns')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'unified_campaign_id' => $unifiedId,
            'provider' => 'snapchat',
            'external_id' => 'ec-'.substr($unifiedId, 0, 8),
            'name' => 'C',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->externalCampaigns[$unifiedId] = $id;
    }

    private function campaignDay(string $campaignId, float $conversions, float $revenue, ?ExternalAccount $account = null): void
    {
        $account ??= $this->selected;
        $externalCampaign = $this->externalCampaign($campaignId, $account);

        foreach (['conversions' => $conversions, 'revenue' => $revenue] as $key => $value) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'snapchat',
                'external_account_id' => $account->id,
                'external_campaign_id' => $externalCampaign,
                'unified_campaign_id' => $campaignId,
                'metric_key' => $key,
                'metric_date' => Carbon::now()->subDay()->toDateString(),
                'value' => $value,
                'project_currency' => 'SAR',
                'original_currency' => 'SAR',
                'data_freshness_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** A real creative row, because `creative_daily_metrics.creative_id` is a foreign key. */
    private function creative(): string
    {
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'external_creative_id' => 'ext-'.uniqid(),
            'name' => 'C-'.uniqid(),
            'format' => 'image',
        ]);

        return (string) $creative->getKey();
    }

    private function creativeDay(string $campaignId, string $creativeId, ?float $conversions, ?float $revenue): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaignId,
            'creative_id' => $creativeId,
            'is_demo' => false,
            'impressions' => 1000,
            'clicks' => 10,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'conversions' => $conversions,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ask(array $campaignIds): array
    {
        return app(CreativeResultAttribution::class)->unattributedCampaigns(
            $campaignIds,
            Carbon::now()->subDays(7),
            Carbon::now(),
        );
    }

    /** The owner's case: the campaign sold, every creative under it reads zero. */
    public function test_a_campaign_that_sold_with_every_creative_at_zero_is_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609);
        $this->creativeDay($campaign, $this->creative(), 0, 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /**
     * The boundary that matters most.
     *
     * One creative sold and the others did not. The breakdown plainly exists, so the zeros are real
     * results and marking them unattributable would erase four true figures to avoid one awkward
     * comparison.
     */
    public function test_one_selling_creative_proves_the_breakdown_exists(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609);
        $this->creativeDay($campaign, $this->creative(), 24, 7_000);
        $this->creativeDay($campaign, $this->creative(), 0, 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /** Revenue alone is enough to prove it: a platform may break down value without a count. */
    public function test_revenue_without_a_count_still_proves_the_breakdown(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 40, revenue: 9_000);
        $this->creativeDay($campaign, $this->creative(), 0, 1_200);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /**
     * A campaign that sold nothing is not an attribution failure.
     *
     * An awareness buy was never asked to sell. Its creatives' zeros are contradicted by nothing,
     * and calling them unattributable would hide a true figure behind a caveat.
     */
    public function test_a_campaign_with_no_results_is_left_alone(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 0, revenue: 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /** Absent is not zero here either: nulls at creative grain are no breakdown at all. */
    public function test_null_creative_results_under_a_selling_campaign_are_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 55, revenue: 12_000);
        $this->creativeDay($campaign, $this->creative(), null, null);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /** A campaign with no creative rows at all in the window is equally unattributed. */
    public function test_a_selling_campaign_with_no_creative_rows_is_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 12, revenue: 3_000);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /** Each campaign is judged on its own evidence, never on its neighbour's. */
    public function test_campaigns_are_judged_separately(): void
    {
        $broken = (string) Str::uuid();
        $whole = (string) Str::uuid();

        $this->campaignDay($broken, conversions: 100, revenue: 20_000);
        $this->creativeDay($broken, $this->creative(), 0, 0);

        $this->campaignDay($whole, conversions: 30, revenue: 6_000);
        $this->creativeDay($whole, $this->creative(), 30, 6_000);

        $this->assertSame([$broken => true], $this->ask([$broken, $whole]));
    }

    /** Evidence outside the window proves nothing about it. */
    public function test_a_breakdown_outside_the_window_does_not_count(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 60, revenue: 15_000);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign,
            'creative_id' => $this->creative(),
            'is_demo' => false,
            'impressions' => 1000,
            'clicks' => 10,
            /* Sold, but two months ago — outside the window being asked about. */
            'metric_date' => Carbon::now()->subDays(60)->toDateString(),
            'conversions' => 60,
            'revenue' => 15_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-001 — a deselected account's campaign sales are not this project's.
     *
     * This is the failure the source guard existed to catch, and it is not cosmetic. Both sides of
     * the comparison must see the same estate: the creative side goes through `CreativeRows` and is
     * narrowed, so if the campaign side were not, a deselected account's sales would look like a
     * campaign that sold with no creative behind it — and every creative in the project would be
     * reported unattributable on the strength of rows the project may not show.
     */
    public function test_a_deselected_accounts_campaign_sales_do_not_reach_the_verdict(): void
    {
        $campaign = (string) Str::uuid();

        /* The sale belongs to an account this project has deselected. */
        $this->campaignDay($campaign, conversions: 109, revenue: 31_609, account: $this->deselected);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame(
            [],
            $this->ask([$campaign]),
            'a deselected account\'s sales were counted as this project\'s campaign result',
        );
    }

    /** …and the selected account's sales do reach it, so the narrowing is not simply hiding everything. */
    public function test_the_selected_accounts_campaign_sales_do_reach_the_verdict(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609, account: $this->selected);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /** Another tenant's rows are invisible here, whatever their bindings say. */
    public function test_another_tenants_campaign_sales_do_not_reach_the_verdict(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609, account: $this->selected);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);

        $this->assertSame([], $this->ask([$campaign]), 'another tenant could read this project\'s campaign results');
    }

    public function test_no_campaigns_asked_is_no_query_and_no_answer(): void
    {
        $this->assertSame([], $this->ask([]));
    }
}
