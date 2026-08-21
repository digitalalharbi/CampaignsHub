<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SNAP-CREATIVE-METRICS-001 — provider creative rows reach `creative_daily_metrics`.
 *
 * The content library showed 1,451 real creatives with «لا توجد بيانات» under each. That was
 * accurate: the table has existed since `2026_07_27_120000` and nothing had ever written to it,
 * because the connector only ever asked for campaign totals.
 */
final class CreativeDailyMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    public function test_a_provider_row_becomes_a_creative_daily_metric(): void
    {
        $creative = $this->creative('cr-1');

        $result = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 12.5, 'impressions' => 300],
        ]);

        $this->assertSame(['upserted' => 1, 'skipped' => 0, 'ambiguous' => 0], $result);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertNotNull($row, 'The content library had nothing to show because nothing was ever written here.');
        $this->assertEqualsWithDelta(12.5, (float) $row->spend, 0.01);
        $this->assertEqualsWithDelta(300, (float) $row->impressions, 0.01);
        $this->assertSame($this->project->id, $row->project_id, 'Project isolation must be carried from the creative.');
    }

    /**
     * A re-sync corrects in place. Attribution keeps moving for days, so the same window is fetched
     * again and again — doubling it would make spend climb every half hour on its own.
     */
    public function test_the_same_day_is_corrected_not_doubled(): void
    {
        $creative = $this->creative('cr-1');
        $rows = fn (float $spend) => [['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => $spend]];

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, $rows(10.0));
        app(UpsertCreativeDailyMetrics::class)->execute($this->account, $rows(14.0));

        $this->assertSame(1, DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->count());
        $this->assertEqualsWithDelta(
            14.0,
            (float) DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->value('spend'),
            0.01,
            'The later figure must replace the earlier one, not add to it.',
        );
    }

    /**
     * A creative the structure sweep has not discovered is SKIPPED, never invented.
     *
     * Creating one here would produce a row with a provider id and nothing else — no name, no
     * format, no ad — and the content library would render that placeholder as a real creative.
     */
    public function test_an_undiscovered_creative_is_skipped_and_counted(): void
    {
        $this->creative('cr-1');

        $result = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 5.0],
            ['campaign_id' => 'cr-unknown', 'date' => '2026-08-01', 'spend' => 99.0],
        ]);

        $this->assertSame(['upserted' => 1, 'skipped' => 1, 'ambiguous' => 0], $result);
        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->count(), 'No creative may be invented from a stats row.');
        $this->assertSame(1, DB::table('creative_daily_metrics')->count());
    }

    /**
     * A metric the platform did not report stays absent — it is not written as zero.
     */
    public function test_an_unreported_measure_is_not_written_as_zero(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 8.0],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertEqualsWithDelta(8.0, (float) $row->spend, 0.01);
        // The column default applies; nothing claimed the platform measured no video views.
        $this->assertEqualsWithDelta(0.0, (float) $row->video_views, 0.01);
    }

    /** Another project's creative sharing the provider id must not receive this project's numbers. */
    public function test_another_projects_creative_is_not_written_to(): void
    {
        $mine = $this->creative('cr-1');

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W2', 'slug' => 'w2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $other = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P2', 'status' => 'active',
        ]);
        $theirs = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $other->id,
            'provider' => 'snapchat', 'external_creative_id' => 'cr-1',
            'name' => 'Theirs', 'format' => 'image',
        ]);

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 7.0],
        ]);

        $this->assertSame(0, DB::table('creative_daily_metrics')->where('creative_id', $theirs->id)->count());
        $this->assertSame(1, DB::table('creative_daily_metrics')->where('creative_id', $mine->id)->count());
    }

    private function creative(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $this->campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);
    }
}
