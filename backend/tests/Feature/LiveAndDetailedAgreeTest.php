<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LIVE-CROSS-PLATFORM-001 — the live link and the saved document are one report, read twice.
 *
 * ## Why this is asserted between the two builders
 *
 * A client can be sent either. `LiveReportService` answers from the database at the moment the link
 * is opened; `ReportGenerator` freezes the same period into a document. They share
 * `MetricsAggregator`, which is the reason they SHOULD agree — and sharing an engine is not the same
 * as agreeing, because each builder decides its own scope, its own window and its own demo policy
 * before the engine is ever called. Those decisions are where two surfaces drift apart while every
 * test of each one passes.
 *
 * The owner's instruction is explicit that this pair must reconcile on real data. A client who
 * opens the link on Monday and receives the PDF on Tuesday is holding two statements about the same
 * month, and a disagreement between them is the one an operator cannot explain away.
 *
 * ## The fixture is built so a disagreement is visible
 *
 * Two providers across three days, split unevenly, with a seeded row inside the same window. One
 * provider and one day cannot expose a builder that drops a provider, misreads a boundary or
 * forgets the demo policy: the totals would agree by coincidence.
 */
final class LiveAndDetailedAgreeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private const SPEND = 4_250.0;

    private const IMPRESSIONS = 310_000.0;

    private const CLICKS = 7_400.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'lv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Live', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);

        $this->row('meta', 'spend', 1_000.0, 1);
        $this->row('meta', 'spend', 1_500.0, 2);
        $this->row('snapchat', 'spend', 1_750.0, 3);
        $this->row('meta', 'impressions', 120_000.0, 1);
        $this->row('meta', 'impressions', 90_000.0, 2);
        $this->row('snapchat', 'impressions', 100_000.0, 3);
        $this->row('meta', 'clicks', 3_000.0, 1);
        $this->row('meta', 'clicks', 2_400.0, 2);
        $this->row('snapchat', 'clicks', 2_000.0, 3);

        // A seeded row inside the same window: a builder that forgets the demo policy reports a
        // number the other one does not.
        $this->row('meta', 'spend', 99_000.0, 2, demo: true);
    }

    public function test_the_live_link_and_the_saved_document_report_the_same_figures(): void
    {
        $detailed = $this->generated();
        $live = $this->live();

        /*
         * The two builders envelope the same figures under different names — the document calls them
         * `kpis` and the link calls them `totals` — and each has its own reader. That is a difference
         * of packaging, not of fact, and this test is about the fact. Normalising here rather than
         * renaming either: both names are load-bearing for their own consumers, and no defect sits
         * behind the difference.
         */
        foreach (['spend' => self::SPEND, 'impressions' => self::IMPRESSIONS, 'clicks' => self::CLICKS] as $key => $expected) {
            $inDocument = (float) ($detailed['kpis'][$key] ?? -1);
            $onTheLink = (float) ($live['totals'][$key] ?? $live['kpis'][$key] ?? -1);

            $this->assertSame($expected, $inDocument, "the saved document's {$key}");
            $this->assertSame(
                $inDocument,
                $onTheLink,
                "the live link says {$key} = {$onTheLink} where the document a client keeps says {$inDocument}",
            );
        }
    }

    /** And the platform breakdown beneath the headline, which each builder queries for itself. */
    public function test_both_name_the_same_platforms_with_the_same_spend(): void
    {
        $spendByProvider = static function (array $payload): array {
            $out = [];
            foreach (($payload['platforms'] ?? []) as $row) {
                $out[(string) ($row['provider'] ?? '')] = round((float) ($row['spend'] ?? 0), 2);
            }
            ksort($out);

            return $out;
        };

        $document = $spendByProvider($this->generated());
        $link = $spendByProvider($this->live());

        $this->assertNotSame([], $document, 'the document named no platform at all');
        $this->assertSame($document, $link, 'the live link and the document disagree about the platforms or their spend');
    }

    // ── fixture ───────────────────────────────────────────────────────────────────────────────

    private function row(string $provider, string $key, float $value, int $daysAgo, bool $demo = false): void
    {
        $row = DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $this->campaign->getKey(),
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => $provider,
            'metric_key' => $key,
            'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'value' => $value,
            'original_amount' => $key === 'spend' ? $value : null,
            'original_currency' => 'SAR',
            'project_currency' => 'SAR',
            'exchange_rate' => 1,
        ]);

        // `is_demo` is not fillable and must be forced, or the «seeded» row is an ordinary one and
        // the fixture cannot produce the state it names.
        $row->forceFill(['is_demo' => $demo])->saveQuietly();
    }

    private function report(): Report
    {
        return Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Monthly',
            'type' => 'performance',
            'status' => 'draft',
            'period_start' => Carbon::today()->subDays(7)->toDateString(),
            'period_end' => Carbon::today()->toDateString(),
            'currency' => 'SAR',
        ]);
    }

    /** @return array<string, mixed> */
    private function generated(): array
    {
        return app(ReportGenerator::class)->generate($this->report());
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        /*
         * A share carries its own CEILING, and a bare one is not a smaller share — it is a different
         * thing entirely. `ceiling()` reads `scope.project_id`, and with it empty the builder enters
         * the impossible-project sentinel, where the report itself cannot be resolved. The first
         * version of this fixture created a share with no scope and died on «Attempt to read property
         * form on null»: a fixture describing a share the product never issues.
         */
        $share = ReportShare::create([
            'tenant_id' => $this->tenant->getKey(),
            'report_id' => $this->report()->getKey(),
            'token_hash' => hash('sha256', 'live-'.uniqid()),
            'allow_download' => false,
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                /*
                 * The campaign ceiling is named, because an unnamed one means NONE.
                 *
                 * `MetricsAggregator::forCampaigns([])` turns an empty list into the impossible-id
                 * sentinel: a ceiling granting no campaign matches nothing, deliberately and
                 * fail-closed. The second version of this fixture left it out and the live link
                 * correctly reported 0 against the document's 4,250 — the product obeying its own
                 * documented rule, and a fixture describing a share that grants a client nothing.
                 */
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'earliest' => Carbon::today()->subDays(30)->toDateString(),
                'latest' => Carbon::today()->toDateString(),
            ],
        ]);

        return app(LiveReportService::class)->build($share, [
            'from' => Carbon::today()->subDays(7)->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ], 'SAR');
    }
}
