<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-MEASURED-FIRST-001 §B — a report lists the content the PERIOD measured, and counts the rest.
 *
 * ## What the owner found
 *
 * Content with no spend and no figures of any kind was appearing in reports. The creative scope was
 * «every creative matching the report's filters» with no reference to the window at all, so a
 * creative that last ran a year before the period still arrived in the roster — at the end, because
 * the spend sort puts nulls last, but present, named, and on a page a client reads.
 *
 * A report is a statement about a period. A creative the period never measured has nothing to state,
 * and listing it with dashes down every column is worse than omitting it: it reads as a creative that
 * ran and produced nothing, which is a claim the data does not make, and it makes a reader doubt the
 * figures beside it.
 *
 * ## And it is NOT dropped silently
 *
 * `creatives_in_scope` keeps meaning what it has always meant — how many the scope holds — so the
 * section can still say «65 ran». `creatives_unmeasured` is new and says how many of those the period
 * holds no figure for. A report that quietly shrank its own estate would be the same defect wearing
 * the opposite sign.
 */
final class ReportUnmeasuredCreativeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-unmeasured-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-unmeasured-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'client_workspace_id' => $client->id,
            'provider' => 'meta',
            'external_id' => 'c-1',
            'name' => 'Campaign',
            'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    public function test_a_creative_the_period_never_measured_is_not_listed(): void
    {
        $this->creative('measured', spendOn: '2026-07-15');
        $this->creative('never measured', spendOn: null);

        $ads = $this->generate();
        $names = array_column($ads['ads_roster'], 'name');

        $this->assertContains('measured', $names);
        $this->assertNotContains('never measured', $names);
    }

    public function test_a_creative_measured_outside_the_period_is_not_listed_either(): void
    {
        $this->creative('in period', spendOn: '2026-07-15');
        $this->creative('last year', spendOn: '2025-07-15');

        $names = array_column($this->generate()['ads_roster'], 'name');

        $this->assertSame(['in period'], $names);
    }

    /** Nothing is dropped silently: the section says how many it could not speak for. */
    public function test_the_unmeasured_are_counted_rather_than_vanished(): void
    {
        $this->creative('measured', spendOn: '2026-07-15');
        $this->creative('silent one', spendOn: null);
        $this->creative('silent two', spendOn: null);

        $ads = $this->generate();

        $this->assertSame(2, $ads['creatives_unmeasured']);
    }

    /** `creatives_in_scope` keeps its meaning — the estate, not the listing. */
    public function test_the_scope_count_still_describes_the_whole_estate(): void
    {
        $this->creative('measured', spendOn: '2026-07-15');
        $this->creative('silent', spendOn: null);

        $this->assertSame(2, $this->generate()['creatives_in_scope']);
    }

    /**
     * A reported ZERO is measurement and stays in the report.
     *
     * «The platform told us this spent nothing in this period» is a fact about the period, and a
     * client is entitled to see it. It is silence that has nothing to say.
     */
    public function test_a_creative_the_platform_reported_a_zero_for_is_still_listed(): void
    {
        $this->creative('reported zero', spendOn: '2026-07-15', spend: 0.0);

        $this->assertContains('reported zero', array_column($this->generate()['ads_roster'], 'name'));
    }

    /**
     * A platform that reports only at the campaign grain still says WHEN a creative delivered.
     *
     * No creative row and no ad row, but `last_active_at` inside the period: that is the period
     * measuring something about this creative, and dropping it would be «unavailable treated as
     * absent» — the error this change exists to remove, not relocate.
     */
    public function test_a_creative_whose_only_evidence_is_its_own_active_date_is_listed(): void
    {
        $creative = $this->creative('campaign grain only', spendOn: null);
        $creative->forceFill(['last_active_at' => '2026-07-10'])->save();

        $this->assertContains('campaign grain only', array_column($this->generate()['ads_roster'], 'name'));
    }

    /** And a date OUTSIDE the period is not evidence about the period. */
    public function test_an_active_date_before_the_period_is_not_evidence_for_it(): void
    {
        $measured = $this->creative('measured', spendOn: '2026-07-15');
        $old = $this->creative('active last year', spendOn: null);
        $old->forceFill(['last_active_at' => '2025-07-10'])->save();

        $this->assertSame(['measured'], array_column($this->generate()['ads_roster'], 'name'));
        $this->assertNotNull($measured);
    }

    private function creative(string $name, ?string $spendOn, float $spend = 10.0): ExternalCreative
    {
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-'.Str::random(8),
            'name' => $name,
            'format' => 'image',
            'asset_url' => 'https://cdn.test/a.jpg',
        ]);

        if ($spendOn !== null) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => $spendOn,
                'spend' => $spend,
                'impressions' => 100,
                'clicks' => 5,
                'conversions' => 1,
                'revenue' => 30,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $creative;
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'performance', 'status' => 'draft', 'form' => 'detailed',
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'currency' => 'SAR', 'scope' => [],
        ]);

        return app(ReportGenerator::class)->generate($report);
    }
}
