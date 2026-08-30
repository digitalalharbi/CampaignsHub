<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * PUBLIC-REPORT-NOAUTH — a client's link must never be able to ask them to sign in.
     *
     * «رسالة انتهت جلستك مسموحة فقط داخل /app و/agency و/admin و/portal.» The link's reader has no
     * account, so a 401 on this path is not a security outcome — it is the product failing, and the
     * client cannot do the thing it asks. The guarantee is checked STRUCTURALLY, against the routes
     * themselves rather than one happy request, because a middleware added to the group later would
     * pass every functional test in this file while breaking every real link in the field.
     */
    /**
     * REPORT-AD-PREVIEW-001 — the client's link carries the ads, built by the deck's own service.
     *
     * The live link computes its figures from the metrics engine so an operator's dashboard and a
     * client's link cannot disagree about a number. It had no ads at all: the part of the report a
     * client recognises — the picture that ran — existed in the generated snapshot and nowhere else.
     *
     * Giving this path its own ad query would have been the fast way, and it is how «the best ad»
     * comes to mean two different things in two documents about one campaign. It calls `ReportAds`,
     * with this link's own scope.
     */
    public function test_a_live_link_carries_the_ads_that_ran(): void
    {
        $creative = ExternalCreative::create([
            'tenant_id' => $this->report->tenant_id,
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->shared->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'Eid film',
            'format' => 'image',
            'status' => 'active',
            'asset_url' => 'https://cdn.example.test/eid.jpg',
            'last_active_at' => Carbon::parse('2026-07-10'),
            'last_synced_at' => Carbon::parse('2026-07-10'),
        ]);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->report->tenant_id,
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $creative->campaign_id,
            'metric_date' => '2026-07-10',
            'spend' => 300, 'impressions' => 10000, 'clicks' => 400, 'conversions' => 20, 'revenue' => 1500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk();

        $this->assertSame('ad', $res->json('data.ads_level'));
        $this->assertNull($res->json('data.ads_absent_reason'));
        $this->assertContains('Eid film', array_column((array) $res->json('data.ads'), 'name'));
    }

    /**
     * A link whose window holds no ad-level rows says so, and does not pretend the section is missing.
     *
     * An empty grid under «الإعلانات» reads as «your ads were so bad there is nothing to show» — a
     * claim about the client's advertising made by a gap in ours.
     */
    public function test_a_live_link_with_no_ads_says_why(): void
    {
        $token = $this->liveLink();

        $res = $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk();

        $this->assertSame([], $res->json('data.ads'));
        $this->assertSame('no_creatives_in_window', $res->json('data.ads_absent_reason'));
    }

    public function test_no_public_report_route_sits_behind_an_authentication_middleware(): void
    {
        $guarded = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'reports/shared/') && ! str_contains($route->uri(), 'reports/print/')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && (str_starts_with($middleware, 'auth') || str_contains($middleware, 'Authenticate'))) {
                    $guarded[] = $route->uri().' → '.$middleware;
                }
            }
        }

        $this->assertSame([], $guarded, 'a public client link cannot require a session');
    }

    /**
     * And the same URL, asked twice with no session and no cookie jar between them, answers twice.
     *
     * A link that only worked on the first request — because something established a session on the
     * way — would pass every other test here and fail the day a client sent it to a colleague.
     */
    public function test_the_link_answers_repeatedly_with_no_session_and_never_401s(): void
    {
        $token = $this->liveLink();

        foreach ([1, 2, 3] as $attempt) {
            $res = $this->getJson("/api/v1/reports/shared/{$token}/live");

            $this->assertSame(200, $res->getStatusCode(), "attempt {$attempt} did not answer");
            $this->assertNull(auth()->user(), 'the public link path must not authenticate anybody');
            $this->assertSame(100.0, (float) $res->json('data.totals.spend'));
        }
    }

    /**
     * «تحديث البيانات تلقائيًا بعد كل Sync» — the same link, after new metrics land.
     *
     * This is what «live» has to mean to be worth the word: not a snapshot taken when the link was
     * created, but the figures as they stand when the client opens it. Proved by writing metrics the
     * way a sync writes them and re-reading the SAME token — no new link, no regeneration, no session.
     */
    public function test_the_same_link_shows_what_a_later_sync_added(): void
    {
        $token = $this->liveLink();

        $this->assertSame(100.0, (float) $this->getJson("/api/v1/reports/shared/{$token}/live")->json('data.totals.spend'));

        // A sync lands more spend on the shared campaign, inside the link's own window.
        app(TenantContext::class)->setTenantId($this->project->tenant_id);
        $this->metric($this->shared->id, 'spend', 250, '2026-07-11');
        app(TenantContext::class)->forget();

        $after = $this->getJson("/api/v1/reports/shared/{$token}/live");

        $after->assertOk();
        $this->assertSame(
            350.0,
            (float) $after->json('data.totals.spend'),
            'the link served a frozen figure — a live link that does not move is a snapshot with a longer name',
        );
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

    /**
     * A ceiling naming NO platform lists no platform — «nothing», never «everything».
     *
     * The rule `ceiling()` states, applied to the freshness footer. It is not a figure, so it is easy
     * to treat as harmless: it is still a disclosure, because which platforms an agency buys on is
     * not something a client is automatically entitled to know — and this is the one surface in the
     * product with no session behind it.
     *
     * Written after the freshness rewrite (UNIFIED-001) briefly made an empty ceiling mean «no
     * provider filter», which is exactly the fail-open reading the class was built to refuse.
     */
    public function test_a_link_whose_ceiling_names_no_platform_lists_none(): void
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id, 'campaign_ids' => [$this->shared->id],
                'providers' => [], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk();

        $this->assertSame([], $res->json('data.freshness'));
    }

    /** Every view is logged, live path included — the access history must not have a hole in it. */
    public function test_a_live_view_is_logged(): void
    {
        $token = $this->liveLink();
        $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk();

        $share = app(ShareService::class)->resolveActive($token);
        $this->assertSame(1, $share->logs()->where('action', 'view')->count());
    }
    // ── SHARE-SHORT-001 / FUNNEL-001 additions ───────────────────────────────────────────────

    /**
     * The link a client is actually sent is short, and still not guessable.
     *
     * 22 base62 characters is ~131 bits — more than an AES-128 key. The old 48-character form produced
     * a URL that WhatsApp and Outlook both wrap across two lines, which is how a client ends up
     * pasting half a link and reporting that the report is broken.
     */
    public function test_a_client_link_is_short_enough_to_send_and_long_enough_to_be_unguessable(): void
    {
        $token = $this->liveLink();

        $this->assertSame(22, strlen($token));
        $this->assertSame("/r/{$token}", ShareService::pathFor($token));
        // And it opens without a session, which is the whole point of the link.
        $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk();
    }

    /** A link issued at the old length keeps working: the length was never part of the contract. */
    public function test_a_link_issued_at_the_old_length_still_opens(): void
    {
        $legacy = Str::random(48);

        $share = app(ShareService::class)->resolveActive($this->liveLink());
        $share->update(['token_hash' => app(ShareService::class)->hashToken($legacy)]);

        $this->getJson("/api/v1/reports/shared/{$legacy}/live")->assertOk();
    }

    /**
     * A project with no store gets NULL rather than a funnel of nulls.
     *
     * A section of empty rows reads as one that failed to load, and a client would ask why — about a
     * store they never had.
     */
    public function test_a_live_link_omits_the_store_funnel_when_the_project_has_no_store(): void
    {
        $res = $this->getJson('/api/v1/reports/shared/'.$this->liveLink().'/live')->assertOk();

        $this->assertNull($res->json('data.store_funnel'));
    }

    /**
     * With a store, the client link carries the SAME funnel the operator reads.
     *
     * Computing it a second way for the client would be a second answer to «كم طلبًا جاء من الإعلان؟»,
     * and the first time the two disagreed nobody would know which to believe.
     */
    public function test_a_live_link_carries_the_store_funnel_and_hides_revenue_there_too(): void
    {
        $this->seedStore();

        $open = $this->getJson('/api/v1/reports/shared/'.$this->liveLink().'/live')->assertOk();

        $this->assertNotNull($open->json('data.store_funnel'));
        $this->assertSame(1, $open->json('data.store_funnel.coverage.stores'));
        $this->assertSame(250.0, (float) $open->json('data.store_funnel.totals.revenue'));

        // A share that hides revenue must hide it in the funnel too — otherwise the flag covers the
        // KPI cards and leaks the exact figure one section further down.
        [, $hidden] = app(ShareService::class)->create($this->report, [
            'hide_revenue' => true,
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->shared->id],
                'providers' => ['meta'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        $res = $this->getJson("/api/v1/reports/shared/{$hidden}/live")->assertOk();

        $this->assertNull($res->json('data.store_funnel.totals.revenue'));
        $this->assertNull($res->json('data.store_funnel.derived.roas'));
        $this->assertNull($res->json('data.store_funnel.derived.aov'));
        $this->assertSame([], $res->json('data.store_funnel.comparisons.products'));
        // The order COUNT is not money and stays: hiding it would misrepresent the funnel's shape.
        $this->assertSame(1, $res->json('data.store_funnel.totals.orders'));
    }

    /** A store with one order in the link's window, so the funnel has something to state. */
    private function seedStore(): void
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->report->tenant_id,
            provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'Salla',
        );

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->report->tenant_id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 'store-1',
            'name' => 'متجر', 'currency' => 'SAR', 'status' => 'active',
        ]);

        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->report->tenant_id,
            'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(),
            'provider' => 'salla', 'external_id' => 'o-1', 'status' => 'completed',
            'placed_at' => Carbon::parse('2026-07-15'), 'currency' => 'SAR', 'total' => 250,
            'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);
    }
}
