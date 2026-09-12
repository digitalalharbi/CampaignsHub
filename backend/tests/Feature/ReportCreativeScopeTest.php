<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-CREATIVE-TRUTH-001 — a report's creative section is about the report's own scope, and says
 * how much of it the reader is seeing.
 *
 * «Creative-first reporting … Full reports must be able to show ALL promoted creatives truthfully.»
 *
 * ## §A — the scope was applied to six sections and forgotten by the seventh
 *
 * `ReportGenerator::generate()` builds `$scope` from the report's own `scope` column and applies it
 * to the metrics engine every figure comes from, with a comment saying exactly why that happens once
 * rather than per section: «a scope honoured by four of those seven and forgotten by three would be
 * worse than none, because the totals would no longer equal their own parts.»
 *
 * The ad section was the section that forgot. `reportAds->for($objective, $from, $to)` — no filters
 * at all — so a report scoped to one campaign printed the client's ads beside another campaign's
 * ads, under a heading naming the first. That is the failure mode the comment describes, and it is
 * worse than a wrong total: it shows a client creative work that is not theirs to see.
 *
 * ## §B — the sixty-row bound was silent
 *
 * `MAX_ROWS` exists for a real reason. What was missing is that a report showing a curated handful
 * out of two hundred said nothing about the two hundred, which reads as «these are the ads that
 * ran». Every case below keeps the estate larger than what any list shows, so a count taken off the
 * page and a count taken off the scope cannot agree.
 */
final class ReportCreativeScopeTest extends TestCase
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

    private function campaign(string $name, string $provider = 'meta'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'external_id' => 'c-'.Str::random(8),
            'name' => $name,
            'status' => 'active',
            'objective' => 'sales',
        ]);
    }

    /** `$count` creatives on one campaign, each with a reported day inside the window. */
    private function creatives(UnifiedCampaign $campaign, int $count, string $prefix, string $provider = 'meta'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'campaign_id' => $campaign->id,
                'provider' => $provider,
                'external_creative_id' => "ec-{$prefix}-{$i}",
                'name' => sprintf('%s %03d', $prefix, $i),
                'format' => 'image',
                'asset_url' => 'https://cdn.test/a.jpg',
            ]);

            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => '2026-07-15',
                'spend' => $i,
                'impressions' => 100 * $i,
                'clicks' => 5 * $i,
                'conversions' => $i,
                'revenue' => 3 * $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function generate(array $scope = [], string $form = 'detailed'): array
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R',
            'type' => 'performance',
            'status' => 'draft',
            'form' => $form,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'currency' => 'SAR',
            'scope' => $scope,
        ]);

        return app(ReportGenerator::class)->generate($report);
    }

    /** §A — a report scoped to one campaign shows that campaign's creatives and no others. */
    public function test_the_creative_section_honours_the_reports_own_scope(): void
    {
        $mine = $this->campaign('Mine');
        $theirs = $this->campaign('Theirs');
        $this->creatives($mine, 3, 'Mine');
        $this->creatives($theirs, 3, 'Theirs');

        $data = $this->generate(['campaign_ids' => [(string) $mine->id]]);

        $names = array_column($data['ads'] ?? [], 'name');
        $this->assertNotEmpty($names, 'the scoped campaign has creatives; the section should show them');

        foreach ($names as $name) {
            $this->assertStringStartsNotWith(
                'Theirs',
                (string) $name,
                'the ad section ignored the scope and printed another campaign’s creative',
            );
        }
    }

    /** The same rule on the platform axis — one filter forgotten is the whole defect. */
    public function test_the_creative_section_honours_the_platform_scope(): void
    {
        $meta = $this->campaign('Meta', 'meta');
        $snap = $this->campaign('Snap', 'snapchat');
        $this->creatives($meta, 3, 'Meta');
        $this->creatives($snap, 3, 'Snap', 'snapchat');

        $data = $this->generate(['providers' => ['snapchat']]);

        $this->assertSame(3, $data['creatives_in_scope']);
        foreach ($data['ads'] ?? [] as $ad) {
            $this->assertSame('snapchat', $ad['provider']);
        }
    }

    /** §B — the section states how many creatives ran, not how many it printed. */
    public function test_the_section_states_the_scope_it_was_taken_from(): void
    {
        $this->creatives($this->campaign('One'), 40, 'Creative');

        $data = $this->generate();

        $this->assertSame(40, $data['creatives_in_scope']);
        $this->assertGreaterThan(
            count($data['ads']),
            $data['creatives_in_scope'],
            'the fixture must hold more creatives than any single list shows, or this proves nothing',
        );
    }

    /**
     * §B — a FULL report lists every creative that ran, beyond the curated top and bottom lists.
     *
     * The ranked lists answer «which worked»; the roster answers «what did we run», which is the
     * question a client asks when they are paying for forty creatives and can see six.
     */
    public function test_a_full_report_carries_every_creative_that_ran(): void
    {
        $this->creatives($this->campaign('One'), 40, 'Creative');

        $data = $this->generate(form: 'detailed');

        $this->assertCount(40, $data['ads_roster']);
        $this->assertSame(0, $data['creatives_withheld'], 'a full report withheld creatives it could hold');
    }

    /**
     * And a five-page summary presents fewer, which is a choice it has to state rather than hide.
     *
     * Sixty-five rather than a round two hundred: the point is the FIRST creative past the summary's
     * bound, and a fixture that inserts hundreds to prove the same arithmetic slows every run for
     * nothing.
     */
    public function test_a_summary_presents_fewer_and_says_how_many_it_left_out(): void
    {
        $this->creatives($this->campaign('One'), 65, 'Creative');

        $summary = $this->generate(form: 'executive_summary');
        $full = $this->generate(form: 'detailed');

        $this->assertSame(65, $summary['creatives_in_scope']);
        $this->assertCount(60, $summary['ads_roster']);
        $this->assertSame(5, $summary['creatives_withheld']);

        // The same account, reported in full, reaches all sixty-five and withholds none.
        $this->assertCount(65, $full['ads_roster']);
        $this->assertSame(0, $full['creatives_withheld']);
    }

    /**
     * §C — the roster crosses the client boundary carrying nothing internal.
     *
     * A presented creative row is not the ranked row the boundary was written for. It carries the
     * campaign NAME on every entry — «never restore campaign names into client reports» — plus
     * `ad_set_id` and its own `ads` key holding the platform's ad objects, each with three more
     * internal ids.
     *
     * That last one is why this case exists at all rather than being assumed: `ads()` has a branch
     * for an objective GROUP, whose `ads` key holds more ad rows. Handed a roster row it takes that
     * branch, walks into the PLATFORM's ads, strips the two keys it knows and passes the other three
     * to the link — one key name meaning two things, and no failure anywhere to notice it.
     */
    public function test_the_client_roster_carries_no_internal_identifier(): void
    {
        $this->creatives($this->campaign('Internal name — v2 (burner)'), 3, 'Creative');

        $client = app(ClientReportView::class)->filter($this->generate());

        $this->assertNotEmpty($client['ads_roster']);

        foreach ($client['ads_roster'] as $row) {
            foreach (['id', 'campaign_id', 'campaign_name', 'ad_set_id', 'external_account_id', 'client_display_name', 'ads'] as $key) {
                $this->assertArrayNotHasKey($key, $row, "the client roster carried «{$key}»");
            }

            // And what a client IS there for survives the stripping.
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('metrics', $row);
        }

        $this->assertStringNotContainsString(
            'burner',
            json_encode($client['ads_roster'], JSON_THROW_ON_ERROR),
            'an internal campaign marker reached the client roster',
        );
    }

    /** An account that ran nothing answers both counts rather than omitting the fields. */
    public function test_an_empty_window_still_answers_the_counts(): void
    {
        $data = $this->generate();

        $this->assertSame(0, $data['creatives_in_scope']);
        $this->assertSame(0, $data['creatives_withheld']);
        $this->assertSame([], $data['ads_roster']);
    }
}
