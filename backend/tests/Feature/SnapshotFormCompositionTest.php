<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Support\ReportComposition;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-PRODUCT-MODEL-001 — the SNAPSHOT half of «mode × form», which the live half already keeps.
 *
 * ## The asymmetry this exists against
 *
 * `ReportComposition` states what each form contains and is applied where a payload is assembled —
 * its own docblock says why that is the only place it can live: «an executive summary whose payload
 * still carries the full creative roster is a detailed report wearing a shorter label, and every
 * consumer downstream would have to remember the trimming separately».
 *
 * It was applied in exactly one assembler. {@see LiveReportService}
 * calls it twice; {@see ReportGenerator} — which assembles the SNAPSHOT, the product the Owner
 * actually generates — never did. It stamped `$data['form']` and stored the full detailed document
 * under it. So of the four named products:
 *
 *   LIVE × Executive Summary      — composed as a summary
 *   LIVE × Detailed               — composed as a detailed report
 *   SNAPSHOT × Executive Summary  — composed as a DETAILED report, labelled a summary
 *   SNAPSHOT × Detailed           — composed as a detailed report
 *
 * which is Owner defect row 96 read literally: «Executive Summary not structurally different from
 * Detailed». The two forms of the snapshot differed by whatever the renderer chose to hide, and one
 * of the two detailed-only blocks was not hidden anywhere at all — `ReportSectionResolver` maps the
 * funnel to a section key and has no mapping for the per-platform creative rankings, so a generated
 * summary served them to a client under a heading its own label says it does not contain.
 *
 * ## Why the trimming and not the renderer
 *
 * `ShareSections` states the principle and it is the same one here: «a section removed from the UI
 * while its data still travels in the JSON is not a permission, it is a CSS rule — and the network
 * tab is one keystroke away». A snapshot is also the thing the PDF, the export and the download are
 * rendered from, so a trimming that lives in one React component is a trimming three other surfaces
 * do not have.
 *
 * Every case below asserts on the GENERATED payload, because that is the document — not on a
 * component's output, which is what made this invisible for a month.
 */
final class SnapshotFormCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
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

        /*
         * Two platforms, because the block this proves is the PER-PLATFORM one: a fixture running a
         * single provider would produce one group and a one-row «comparison», and the case would
         * pass on a payload that had nothing to divide.
         */
        $this->creatives($this->campaign('Meta', 'meta'), 4, 'Meta', 'meta');
        $this->creatives($this->campaign('Snap', 'snapchat'), 4, 'Snap', 'snapchat');
    }

    private function campaign(string $name, string $provider): UnifiedCampaign
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

    private function creatives(UnifiedCampaign $campaign, int $count, string $prefix, string $provider): void
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
                'spend' => 10 * $i,
                'impressions' => 1000 * $i,
                'clicks' => 50 * $i,
                'conversions' => $i,
                'revenue' => 30 * $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(string $form): array
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
            'scope' => [],
        ]);

        return app(ReportGenerator::class)->generate($report);
    }

    /** The label and the document must be the same statement — the whole of this row. */
    public function test_the_generated_snapshot_states_the_form_it_was_composed_as(): void
    {
        $this->assertSame('executive_summary', $this->snapshot('executive_summary')['form'] ?? null);
        $this->assertSame('detailed', $this->snapshot('detailed')['form'] ?? null);
    }

    /** Handoff §10: the funnel is the DETAILED product's. */
    public function test_a_generated_summary_does_not_carry_the_funnel(): void
    {
        $summary = $this->snapshot('executive_summary');

        $this->assertSame(
            [],
            $summary['funnel'],
            'a generated executive summary carried the funnel — the one block the renderer hides, '.
            'still travelling in the JSON a client can read and the PDF is drawn from',
        );
    }

    /** «Platform-specific top creatives» is depth, and nothing hid it on this product at all. */
    public function test_a_generated_summary_does_not_carry_the_per_platform_creative_rankings(): void
    {
        $this->assertSame(
            [],
            $this->snapshot('executive_summary')['ads_platform_groups'],
            'a generated executive summary carried the per-platform creative rankings',
        );
    }

    /** The trimming must reach the summary and stop there. */
    public function test_the_generated_detailed_report_keeps_every_one_of_them(): void
    {
        $detailed = $this->snapshot('detailed');

        $this->assertNotEmpty($detailed['funnel'], 'the detailed snapshot lost its funnel');
        $this->assertNotEmpty(
            $detailed['ads_platform_groups'],
            'the detailed snapshot lost its per-platform creative rankings',
        );
    }

    /**
     * The honesty clause, which is the one that makes the trimming safe to ship.
     *
     * `ReportComposition` records why `ads_roster` is deliberately not trimmed: emptying it would
     * make the roster component return null and the COUNT would vanish with the table, restoring
     * REPORT-CREATIVE-TRUTH-001's own defect — «a report showed a curated handful and said nothing
     * about the rest» — through a change meant to shorten the document. A summary withholds the
     * list and STATES the count.
     */
    public function test_a_generated_summary_still_states_how_many_creatives_ran(): void
    {
        $summary = $this->snapshot('executive_summary');

        $this->assertSame(8, $summary['creatives_in_scope'], 'the summary stopped counting the estate');
        $this->assertNotEmpty($summary['ads'], 'the summary dropped the ads section itself');
    }

    /**
     * The Owner's complaint, turned into a measurement rather than an impression.
     *
     * Row 96 is «Executive Summary and Detailed render the same document with more text», and the
     * live product was closed against numbers: 43 blocks against 45 before, 30 against 45 after. The
     * snapshot had no such number, which is how it stayed PARTIAL while reading as fixed. So this
     * counts what the two generated documents actually contain — the visible slides of the deck and
     * the payload sections that carry anything — and fails if a future change quietly collapses the
     * difference back, whatever the labels say.
     *
     * Deliberately NOT a fixed expected count: the deck grows, and a test pinned to «30» would be
     * edited away on the first legitimate addition. The invariant is the GAP.
     */
    public function test_the_two_generated_products_are_measurably_different_documents(): void
    {
        $summary = $this->snapshot('executive_summary');
        $detailed = $this->snapshot('detailed');

        $slides = static fn (array $data): int => count(array_filter(
            is_array($data['slides'] ?? null) ? $data['slides'] : [],
            static fn ($slide): bool => is_array($slide) && ($slide['visible'] ?? true) === true,
        ));

        $this->assertGreaterThan(
            $slides($summary),
            $slides($detailed),
            'the generated summary has as many slides as the detailed report — the deck did not differentiate',
        );

        /* The blocks that carry something, which is what a reader experiences as length. */
        $filled = static fn (array $data): int => count(array_filter(
            $data,
            static fn ($value): bool => is_array($value) && $value !== [],
        ));

        $this->assertGreaterThan(
            $filled($summary),
            $filled($detailed),
            'both forms carry the same filled blocks — the summary is the detailed document relabelled',
        );
    }

    /**
     * One vocabulary across the products — the row asks for exactly this.
     *
     * The forms are not allowed to mean different things on a snapshot and on a live link: an
     * operator who learns what «executive summary» withholds from one must not have to learn it
     * again from the other. Asserted against the single statement both read, so a key added to the
     * composition that only one assembler applies fails here rather than on a client's report.
     */
    public function test_the_snapshot_withholds_what_the_composition_says_a_summary_withholds(): void
    {
        $summary = $this->snapshot('executive_summary');
        $detailed = $this->snapshot('detailed');

        $withheld = ReportComposition::for('executive_summary')->apply($detailed);

        foreach ($detailed as $key => $value) {
            if ($withheld[$key] === $value) {
                continue;
            }

            $this->assertSame(
                $withheld[$key],
                $summary[$key] ?? null,
                "«{$key}» is withheld from a live summary and present in a generated one — ".
                'the two products disagree about what an executive summary is',
            );
        }
    }
}
