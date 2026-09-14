<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 — one link, one ceiling, on every section of it.
 *
 * ## The defect
 *
 * A share carries an ACCOUNT axis: the operator picks which ad accounts the link may speak about, and
 * `LiveReportService` applies it — `ReportScope::fromArray([... 'account_ids' => $share->scope[...]])
 * ->applyTo($engine)` — so `platforms`, `kpis` and everything else built from that engine are bounded
 * by it. Two sections are NOT built from that engine. `objective_performance` and `objective_leaders`
 * construct `ObjectivePerformance` inline from the raw scope, passing `projectIds`, `campaignIds` and
 * `providers` and NOT `accountIds` — and `ObjectivePerformance` filters on
 * `daily_metrics.external_account_id` perfectly well when it is given one.
 *
 * So a link scoped to one ad account rendered its platform table for that account and its objective
 * split for EVERY account in the project. That is not only two sections of one document disagreeing
 * about what the link covers — it is the wider of the two facing the client, on the one surface in
 * the product with no session behind it.
 *
 * The comment above the live construction says «the same service the deck calls, on the same bounds:
 * a link that computed its own split would eventually disagree with the document it accompanies». It
 * was the same SERVICE and not the same BOUNDS: `ReportScope::objectivePerformance()`, which the deck
 * uses, passes `accountIds` and this did not.
 *
 * ## Why the figures are what they are
 *
 * 100 inside the ceiling and 999 outside it, so a leak is impossible to read as anything else.
 */
final class LiveReportAccountCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private UnifiedCampaign $inside;

    private UnifiedCampaign $outside;

    private string $accountInside;

    private string $accountOutside;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'ceiling-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $this->accountInside = (string) Str::uuid();
        $this->accountOutside = (string) Str::uuid();

        $this->inside = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Inside the ceiling',
            'status' => 'active', 'objective' => 'sales',
        ]);
        $this->outside = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Another account entirely',
            'status' => 'active', 'objective' => 'sales',
        ]);

        $this->metric($this->inside->id, $this->accountInside, 'spend', 100);
        $this->metric($this->inside->id, $this->accountInside, 'clicks', 50);
        $this->metric($this->outside->id, $this->accountOutside, 'spend', 999, 'tiktok');
        $this->metric($this->outside->id, $this->accountOutside, 'clicks', 40, 'tiktok');
        /*
         * Orders on both, because the conversion path ranks on `orders` and refuses to rank at all
         * without them — and on a DIFFERENT platform for the out-of-ceiling account, because a
         * client's leaders are bucketed by platform and not by campaign (CLIENT-REPORT-ENTITY-
         * BOUNDARY-001, `leadersByPath(by: 'provider')`). Two campaigns on one platform collapse
         * into one bucket, which the section then declines to rank — so a fixture built that way
         * would assert nothing about the ceiling at all.
         */
        $this->metric($this->inside->id, $this->accountInside, 'conversions', 5);
        $this->metric($this->outside->id, $this->accountOutside, 'conversions', 9, 'tiktok');

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['kpis' => ['spend' => 100]],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    private function metric(string $campaignId, string $accountId, string $key, float $value, string $provider = 'meta'): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => $accountId,
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaignId,
            'provider' => $provider,
            'metric_key' => $key,
            'metric_date' => '2026-07-10',
            'value' => $value,
        ]);
    }

    /**
     * The link names BOTH campaigns deliberately.
     *
     * The account is then the only axis that can exclude the 999, so a section that honours the
     * campaign ceiling while ignoring the account one cannot pass by accident.
     */
    private function link(): string
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->inside->id, $this->outside->id],
                'account_ids' => [$this->accountInside],
                'providers' => ['meta', 'tiktok'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        return $raw;
    }

    /** @param array<string,mixed> $objective */
    private function objectiveSpend(array $objective): float
    {
        $spend = 0.0;

        foreach ($objective['paths'] ?? [] as $path) {
            $spend += (float) ($path['spend'] ?? 0);
        }

        return $spend;
    }

    public function test_the_platform_section_honours_the_account_ceiling(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link()}/live")->assertOk();

        $spend = collect($res->json('data.platforms'))->sum('spend');

        $this->assertSame(100.0, (float) $spend, 'The platform table already had the ceiling; if this fails the ceiling itself broke.');
    }

    public function test_the_objective_split_honours_the_same_ceiling(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link()}/live")->assertOk();

        $this->assertSame(
            100.0,
            $this->objectiveSpend($res->json('data.objective_performance') ?? []),
            'The objective split reported spend from an ad account this link was never scoped to.',
        );
    }

    /** Two sections of one document must not disagree about what the link covers. */
    public function test_the_two_sections_agree_with_each_other(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link()}/live")->assertOk();

        $this->assertSame(
            (float) collect($res->json('data.platforms'))->sum('spend'),
            $this->objectiveSpend($res->json('data.objective_performance') ?? []),
        );
    }

    /**
     * The leaders are ranked out of the same service and were built with the same blind spot.
     *
     * A «strongest campaign» drawn from outside the ceiling names an account the reader was never
     * granted — a disclosure as well as a wrong figure.
     */
    public function test_the_objective_leaders_rank_nothing_bought_outside_the_ceiling(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link()}/live")->assertOk();

        $conversion = collect($res->json('data.objective_leaders.paths') ?? [])
            ->firstWhere('path', 'conversion');

        $this->assertNotNull($conversion, 'The conversion path is missing, so this asserts nothing.');

        $named = array_filter([
            $conversion['strongest']['name'] ?? null,
            $conversion['weakest']['name'] ?? null,
        ]);

        // The platform outside the ceiling must not be a candidate — and with it excluded there is
        // one bucket left, which the section declines to rank rather than crowning a field of one.
        $this->assertSame(1, $conversion['campaigns'], 'A platform the link was never scoped to was ranked against the one it was.');
        $this->assertNotContains('tiktok', array_map('strtolower', $named));
    }

    /**
     * A ceiling naming NO campaign means «none», on every section of the document.
     *
     * `MetricsAggregator::forCampaigns([])` stores an empty list rather than null and resolves it to
     * the impossible-id sentinel, so the engine-built sections render nothing — deliberately, and by
     * the same rule `ceiling()` applies to the other axes. The objective sections coalesced that
     * empty list to null, and null means «every campaign», so the one section that was not built
     * from the engine read a fail-CLOSED choice as a fail-OPEN one.
     *
     * Measured on the demo world before the fix: `platforms` 0 beside an objective split of 129,967.
     * An empty report body next to a section reporting the whole project is not a rounding
     * disagreement — it is the document contradicting itself in front of the client.
     */
    public function test_a_ceiling_naming_no_campaign_empties_the_objective_split_too(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [],
                'account_ids' => [$this->accountInside],
                'providers' => ['meta', 'tiktok'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk();

        $platforms = (float) collect($res->json('data.platforms'))->sum('spend');

        // The engine's own reading, stated here so the assertion below is anchored to it rather than
        // to a constant that would drift if the ceiling rule ever changed.
        $this->assertSame(0.0, $platforms, 'The engine no longer fails closed on an empty campaign ceiling.');
        $this->assertSame(
            $platforms,
            $this->objectiveSpend($res->json('data.objective_performance') ?? []),
            'The objective split reported campaigns the link named none of.',
        );
    }
}
