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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LIVEREP-001 — a live shared link recomputes, and cannot be talked into widening.
 *
 * The tests that matter here are the negative ones. A live link is the only surface in the product
 * reachable with **no session at all**, so the tenant and project scopes that protect everything else
 * are not doing any work: the only thing between the URL and somebody else's figures is the ceiling
 * stored on the share, and the intersection that applies it. So most of this file consists of asking
 * for things the link was not given — a sibling campaign, another tenant's campaign, a date before the
 * window — and asserting the answer is the link's own data rather than an error or, far worse, the data.
 */
final class LiveReportShareTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private UnifiedCampaign $shared;

    private UnifiedCampaign $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $this->shared = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Shared campaign', 'status' => 'active',
        ]);
        $this->sibling = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Not shared', 'status' => 'active',
        ]);

        // 100 spend on the shared campaign, 999 on the sibling — a figure impossible to miss if it leaks.
        $this->metric($this->shared->id, 'spend', 100, '2026-07-10');
        $this->metric($this->shared->id, 'clicks', 50, '2026-07-10');
        $this->metric($this->sibling->id, 'spend', 999, '2026-07-10');

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['kpis' => ['spend' => 100]],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    private function metric(string $campaignId, string $key, float $value, string $date): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaignId,
            'provider' => 'meta',
            'metric_key' => $key,
            'metric_date' => $date,
            'value' => $value,
        ]);
    }

    /** @param array<string, mixed> $scope */
    private function liveLink(array $scope = []): string
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => $scope + [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->shared->id],
                'providers' => ['meta'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        return $raw;
    }

    public function test_a_live_link_reports_current_figures_without_a_session(): void
    {
        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live");

        $res->assertOk();
        $this->assertSame(100.0, (float) $res->json('data.totals.spend'));
        $this->assertSame(50.0, (float) $res->json('data.totals.clicks'));
    }

    /**
     * The one that matters most: a campaign the link was never given.
     *
     * The sibling has 999 spend. If the ceiling is not applied, the total is 1099 and a client is
     * reading a campaign nobody shared with them.
     */
    public function test_asking_for_a_campaign_outside_the_ceiling_does_not_widen_it(): void
    {
        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live?campaigns[]={$this->sibling->id}");

        $res->assertOk();
        $this->assertSame(
            100.0,
            (float) $res->json('data.totals.spend'),
            'a campaign outside the share ceiling reached the client payload',
        );
    }

    public function test_a_date_before_the_window_is_clamped_rather_than_honoured(): void
    {
        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live?from=2020-01-01&to=2030-01-01");

        $res->assertOk();
        $this->assertSame('2026-07-01', $res->json('data.applied.from'));
        $this->assertSame('2026-07-31', $res->json('data.applied.to'));
    }

    public function test_a_platform_outside_the_ceiling_is_dropped_not_honoured(): void
    {
        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live?providers[]=google");

        $res->assertOk();
        // Intersection left nothing, which the service reads as "the whole ceiling" — meta — not "google".
        $this->assertSame(100.0, (float) $res->json('data.totals.spend'));
    }

    /** A narrowing that IS within the ceiling must actually narrow, or the intersection is a no-op. */
    public function test_narrowing_inside_the_ceiling_works(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->shared->id, $this->sibling->id],
                'providers' => ['meta'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        $all = $this->getJson("/api/v1/reports/shared/{$raw}/live");
        $this->assertSame(1099.0, (float) $all->json('data.totals.spend'));

        $narrowed = $this->getJson("/api/v1/reports/shared/{$raw}/live?campaigns[]={$this->shared->id}");
        $this->assertSame(100.0, (float) $narrowed->json('data.totals.spend'));
    }

    public function test_a_revoked_link_stops_answering(): void
    {
        $token = $this->liveLink();
        app(ShareService::class)->resolveActive($token)->update(['revoked_at' => Carbon::now()]);

        $this->getJson("/api/v1/reports/shared/{$token}/live")->assertNotFound();
    }

    public function test_an_expired_link_stops_answering(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'expires_at' => Carbon::now()->addDay(),
            'scope' => ['project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id], 'providers' => ['meta']],
        ], null);

        $this->travel(2)->days();
        $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertNotFound();
    }

    public function test_a_password_protected_live_link_refuses_without_the_password(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'password' => 'letmein',
            'scope' => ['project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id], 'providers' => ['meta'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31'],
        ], null);

        $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertStatus(401);
        $this->getJson("/api/v1/reports/shared/{$raw}/live", ['X-Report-Password' => 'letmein'])->assertOk();
    }

    /** A snapshot link says what it is, rather than answering with zeroes it would then render. */
    public function test_a_snapshot_link_refuses_the_live_endpoint(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [], null);

        $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertStatus(409);
    }

    /**
     * Hiding spend must hide it on the live path too.
     *
     * The snapshot sanitizer and the live one are separate functions over differently shaped payloads;
     * this is the test that stops the second from being forgotten when the first is changed.
     */
    public function test_hidden_spend_is_absent_from_the_live_payload(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'hide_spend' => true,
            'scope' => ['project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id], 'providers' => ['meta'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31'],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live");

        $res->assertOk();
        $this->assertNull($res->json('data.totals.spend'), 'spend was hidden on the snapshot path but published on the live one');
        $this->assertSame(50.0, (float) $res->json('data.totals.clicks'), 'hiding spend should not blank unrelated metrics');
    }

    /** Hiding names must also rename the FILTER, or the reader just looks up instead of down. */
    public function test_hidden_campaign_names_are_absent_from_the_picker(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'hide_campaign_names' => true,
            'scope' => ['project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id], 'providers' => ['meta'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31'],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live");

        $res->assertOk();
        $names = array_column((array) $res->json('data.available.campaigns'), 'name');
        $this->assertNotContains('Shared campaign', $names);
    }

    /**
     * A platform that has never synced says so, instead of reporting a zero.
     *
     * «0 spend» and «we cannot see this account» look identical on a chart and mean opposite things.
     */
    public function test_a_never_synced_platform_is_reported_as_awaiting_credentials(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id],
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live");

        $states = collect($res->json('data.freshness'))->pluck('state', 'provider');
        $this->assertSame('awaiting_credentials', $states['tiktok']);
    }

    /** Every view is logged, live path included — the access history must not have a hole in it. */
    public function test_a_live_view_is_logged(): void
    {
        $token = $this->liveLink();
        $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk();

        $share = app(ShareService::class)->resolveActive($token);
        $this->assertSame(1, $share->logs()->where('action', 'view')->count());
    }
}
