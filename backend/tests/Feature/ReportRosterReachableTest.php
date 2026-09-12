<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportAds;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-CREATIVE-TRUTH-001 §D — every promoted creative is REACHABLE, not merely counted.
 *
 * ## Why the previous unit was not the end of it
 *
 * §B made the bound honest: a report that showed sixty creatives out of sixty-five said so. That
 * stopped the document lying, and it did not make the other five reachable — «65 ran, 60 listed» is
 * a disclosed gap, and a disclosed gap is still a gap. The owner's ruling is explicit: a full report
 * must be able to reach every promoted creative, and the cap alone is not completion.
 *
 * ## What was actually costing the bound
 *
 * Not the query — `present()` is fully batched, so five hundred rows cost the same round trips as
 * sixty. What grows per row is the PRESENTED CARD: a preview envelope, the platform's ad objects,
 * the headline-metric layout. A snapshot is stored as JSON and read on every open, so five thousand
 * of those is a document nobody can load.
 *
 * The roster does not need any of it. It is a table of names and figures — the ranked lists above it
 * are where the picture matters — so the roster is built LEAN, and once a row is nine fields the
 * bound that was protecting the payload is protecting nothing.
 */
final class ReportRosterReachableTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    private function creatives(int $count): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_id' => 'c-1',
            'name' => 'Campaign',
            'status' => 'active',
            'objective' => 'sales',
        ]);

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $campaign->id,
                'provider' => 'meta',
                'external_creative_id' => "ec-{$i}",
                'name' => sprintf('Creative %04d', $i),
                'format' => 'image',
                'asset_url' => 'https://cdn.test/a.jpg',
            ]);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => '2026-07-15',
                'spend' => $i,
                'impressions' => 100,
                'clicks' => 5,
                'conversions' => 1,
                'revenue' => $i * 2,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('creative_daily_metrics')->insert($rows);
    }

    /** @return array<string, mixed> */
    private function section(string $form = 'detailed'): array
    {
        return app(ReportAds::class)->for(
            'sales',
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            ['project_ids' => [(string) $this->project->id]],
            $form,
        );
    }

    /**
     * Six hundred creatives, and a full report reaches every one of them.
     *
     * Past the old five-hundred bound on purpose: under it this case passes without proving anything,
     * which is how a cap that has stopped mattering survives its own test.
     */
    public function test_a_full_report_reaches_every_creative_past_the_old_bound(): void
    {
        $this->creatives(600);

        $section = $this->section();

        $this->assertSame(600, $section['creatives_in_scope']);
        $this->assertCount(600, $section['roster'], 'the report still stops short of the estate it reports on');
        $this->assertSame(0, $section['creatives_withheld']);
    }

    /**
     * And the roster row is LEAN — which is what made the bound unnecessary.
     *
     * A presented card carries a preview envelope, the platform's ad objects and a headline layout.
     * None of it is drawn by a table of names and figures, and all of it is stored in the snapshot
     * and parsed on every open. This pins the shape so the weight cannot creep back.
     */
    public function test_a_roster_row_carries_only_what_the_table_draws(): void
    {
        $this->creatives(3);

        $row = $this->section()['roster'][0];

        foreach (['id', 'name', 'provider', 'format', 'objective', 'metrics'] as $key) {
            $this->assertArrayHasKey($key, $row, "the roster stopped carrying «{$key}»");
        }

        foreach (['preview', 'ads', 'headline_metrics', 'campaign_name', 'ad_delivered'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "the roster is carrying «{$key}», which no column draws");
        }
    }

    /** The figures on a roster row are the ones the table shows, and nothing is invented. */
    public function test_the_roster_carries_the_figures_the_table_reads(): void
    {
        $this->creatives(2);

        $metrics = $this->section()['roster'][0]['metrics'];

        foreach (['spend', 'impressions', 'clicks', 'conversions', 'ctr'] as $key) {
            $this->assertArrayHasKey($key, $metrics);
        }
    }

    /**
     * The ranked lists keep their previews — the roster being lean must not strip the cards.
     *
     * «Which of these worked» is answered with the picture that ran. Reusing the lean rows there to
     * save a second pass would take the media out of the one section a client recognises.
     */
    public function test_the_ranked_ads_still_carry_their_media(): void
    {
        $this->creatives(3);

        $section = $this->section();

        $this->assertNotEmpty($section['ads']);
        $this->assertArrayHasKey('preview', $section['ads'][0], 'the ranked list lost the picture that ran');
    }

    /** A five-page summary still curates, and still says how many it left out. */
    public function test_a_summary_still_presents_fewer_and_says_so(): void
    {
        $this->creatives(65);

        $summary = $this->section('executive_summary');

        $this->assertSame(65, $summary['creatives_in_scope']);
        $this->assertCount(60, $summary['roster']);
        $this->assertSame(5, $summary['creatives_withheld']);
    }
}
