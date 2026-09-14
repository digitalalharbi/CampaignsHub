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
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — asked of the ENDPOINTS, not of the sanitizer.
 *
 * ## Why the unit tests are not enough
 *
 * `ClientLinkMoneyKeysTest` and `ClientReportMoneyRedactionTest` prove that `sanitize()`,
 * `sanitizeLive()` and `redactRow()` remove what they should. Neither proves a CONTROLLER calls
 * them, and that is a different claim — the one a client's network tab actually tests.
 *
 * It is also the claim that was false. `PublicReportController::attribution()` returns
 * `AttributionTransparency::build(...)` with no redaction of any kind, and that payload carries
 * `platform_reported_revenue`, `store_confirmed_revenue` and `total_revenue`. The section is gated by
 * `sectionVisibility()->attribution`, which is a DIFFERENT flag from `hide_revenue`: an operator who
 * turned the attribution section on and hid revenue got revenue anyway, by a route nobody had
 * connected to the money rules.
 *
 * ## Why this walks the routes instead of listing the ones we thought of
 *
 * The defect above is an enumeration defect — a surface existed and a list did not mention it. So
 * this test refuses to enumerate: it takes every registered `reports/shared/{token}` GET route,
 * hits it on a link that hides both money figures, and walks the WHOLE response recursively for any
 * money key with a value. A new shared endpoint is covered the day it is added, and one that forgets
 * to redact fails here rather than in a client's browser.
 *
 * A route that 404s on this link (a section the link does not carry) is not a pass and not a
 * failure — it is «nothing was served», and the test says how many routes actually answered so the
 * guard cannot go quiet by serving nothing at all.
 */
final class SharedLinkMoneyLeakTest extends TestCase
{
    use RefreshDatabase;

    /** Every key that IS money, or that money can be divided out of, plus the withheld originals. */
    private function forbidden(): array
    {
        return array_values(array_unique(array_merge(
            CreativeVisibility::COST_METRICS,
            CreativeVisibility::REVENUE_METRICS,
            CreativeVisibility::MONEY_COMPANIONS['spend'],
            CreativeVisibility::MONEY_COMPANIONS['revenue'],
            CreativeVisibility::MONEY_CURRENCY_KEYS,
            // The attribution payload's own names for revenue — the defect this test was written for.
            ['platform_reported_revenue', 'store_confirmed_revenue', 'total_revenue', 'spend_at_risk'],
        )));
    }

    private Report $report;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Leak', 'slug' => 'leak-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $project->getKey());

        $campaign = $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);

        /*
         * Real revenue in `daily_metrics`, because `AttributionTransparency` reads that table.
         *
         * Without it the attribution endpoint answers 200 with every revenue field NULL, and a sweep
         * over it passes having inspected nothing — which is how a guard reports «no leak» about a
         * code path that has no redaction in it at all. The endpoint returns
         * `AttributionTransparency::build(...)` directly; the section is gated on
         * `sectionVisibility()->attribution`, a DIFFERENT flag from `hide_revenue`, so an operator
         * who turned the section on and hid revenue gets revenue.
         */
        /*
         * TWO windows, and the second one is not decoration.
         *
         * A platform row carries `movement` — its own period-over-period ratios — and
         * `LiveReportService` leaves it EMPTY when the previous window held nothing, which is the
         * honest answer for a platform that was not running then. With metrics in this window only,
         * the live sweep reached a platforms list whose movement was `[]` on every row, and removing
         * the sanitiser's movement strip changed nothing it could see. A fixture that cannot reach
         * a branch proves nothing about it.
         */
        foreach ([2 => 1.0, 40 => 0.5] as $daysAgo => $scale) {
            foreach (['orders' => 60.0, 'revenue' => 18000.0, 'spend' => 6000.0] as $key => $value) {
                DailyMetric::create([
                    'tenant_id' => $tenant->getKey(),
                    'project_id' => $project->getKey(),
                    'unified_campaign_id' => $campaign->getKey(),
                    'external_account_id' => (string) Str::uuid(),
                    'external_campaign_id' => (string) Str::uuid(),
                    'provider' => 'snapchat',
                    'metric_key' => $key,
                    'metric_date' => now()->subDays($daysAgo)->toDateString(),
                    'value' => $value * $scale,
                    'original_amount' => $value * $scale,
                    'original_currency' => 'SAR',
                    'exchange_rate' => 1,
                    'project_currency' => 'SAR',
                    'attribution_window' => '7d_click',
                    'source_type' => 'platform_reported',
                ]);
            }
        }

        /*
         * A payload carrying money in every shape the sanitizers know about, so a section that
         * forgets to redact has something to leak. The originals are the production shape: an
         * unconvertible figure with the real amount preserved beside a null conversion.
         */
        $row = [
            'spend' => 6000.0, 'revenue' => 18000.0, 'cpa' => 120.0, 'cpc' => 0.5, 'cpm' => 20.0,
            'cpl' => 40.0, 'cpi' => 3.0, 'cpe' => 1.5, 'cost_per_view' => 0.02, 'cost_per_lpv' => 0.3,
            'cost_per_result' => 86.84, 'roas' => 3.0, 'aov' => 300.0,
            'spend_original' => 412.5, 'revenue_original' => 1980.0,
            'spend_withheld_rows' => 3, 'revenue_withheld_rows' => 3,
            'money_original_currency' => 'USD', 'money_original_currencies' => 1,
            'impressions' => 90000, 'clicks' => 300, 'conversions' => 60,
            'name' => 'A creative', 'provider' => 'snapchat', 'campaign_name' => 'Sale',
        ];

        $this->report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(),
            'name' => 'Monthly', 'type' => 'performance', 'status' => 'completed',
            'audience' => 'client', 'currency' => 'SAR', 'form' => 'detailed',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'data' => [
                'period' => ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()],
                /*
                 * `kpis` is the snapshot's own name for the scalar totals and `totals` is the live
                 * payload's — checked in `ReportGenerator` and `LiveReportService` rather than
                 * assumed, because a first draft of this fixture put `totals` in a snapshot and the
                 * sweep reported a leak for a key the snapshot never carries.
                 */
                'kpis' => $row,
                /*
                 * A platform row WITH its movement, because movement is a rung down.
                 *
                 * The values there are ratios rather than amounts, and the sweep cannot tell a ratio
                 * under the key `spend` from a figure under it — which is the point: «hiding spend
                 * takes the ratio that would give it back» is already this product's rule, and a
                 * per-platform movement is that ratio one axis over.
                 */
                'platforms' => [$row + ['movement' => $row]],
                'campaigns' => [$row],
                'timeseries' => [$row],
                'budget' => [$row],
                'ads' => [$row],
                /*
                 * The roster's REAL shape, money one rung down under `metrics`.
                 *
                 * This was `[$row]` — flat, money at the top level — and that single convenience is
                 * why this sweep passed while a `hide_spend` link published sixty rows of
                 * `metrics.spend`, `metrics.cpc` and `metrics.cpm` on the live payload. The sanitiser
                 * strips a row's own keys, the fixture handed it a row whose keys were its own, and
                 * the shape the product builds was never tested. A fixture more generous than the
                 * server is a test that cannot fail for the reason the product breaks.
                 */
                'ads_roster' => [['name' => 'A creative', 'provider' => 'snapchat', 'metrics' => $row]],
                /*
                 * And a section that was on no list at all, in the WRAPPER shape it really has — the
                 * failure `sanitizeLive()`'s own enumeration warns about, which no fixture carried.
                 */
                'objective_performance' => [
                    'paths' => [['path' => 'conversion'] + $row],
                    'direct' => $row,
                    'blended' => $row,
                ],
                'worst_creatives' => [$row],
                'top_creatives' => [$row],
                'ads_groups' => [['ads' => [$row]]],
                /*
                 * And the per-platform gallery, which nests the SAME group one rung further down.
                 *
                 * `ads_platform_groups[].groups[].ads[]` is platform → objective → ad. The
                 * `ads_groups` line above is one rung and was itself added after a link published
                 * every ad in the grouped gallery; a section that goes two is exactly where the next
                 * one hides, so it is in the fixture in the shape the server really builds rather
                 * than flattened into something a sanitiser passes by accident.
                 */
                'ads_platform_groups' => [[
                    'provider' => 'snapchat',
                    'candidates' => 4,
                    'groups' => [['family' => 'sales', 'ads' => [$row]] + $row],
                ]],
                'slides' => [['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true]],
            ],
            'generated_at' => now(),
        ]);
    }

    /** Every money key found anywhere in the structure, with a value, as `path => value`. */
    private function leaks(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        $forbidden = $this->forbidden();

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            /*
             * A BOOLEAN is never a figure — it is a permission.
             *
             * `CreativeVisibility::toArray()` is sent to the page under `permissions`, keyed by the
             * same metric names, and `spend: false` is the link SAYING it withholds spend. A first
             * version of this sweep reported those four flags as leaks, which would have been a
             * detector bug published as a security finding.
             */
            /*
             * And a SENTENCE is never a figure either — it is an explanation.
             *
             * `objective_performance.direct.formula` is keyed by the metric it describes, so `cpa`
             * holds «sales-path spend ÷ sales-path orders». That names the arithmetic and discloses
             * no money, exactly as `spend: false` names a permission and discloses no money. The
             * boolean lesson above, met a second time on a different shape: the rule is that a leak
             * is a NUMBER, so anything non-numeric is read past rather than published as a finding.
             *
             * Numeric strings still count — a figure delivered as «6000.00» is a figure.
             */
            if (is_string($key) && in_array($key, $forbidden, true) && is_numeric($value)) {
                $found[$here] = $value;
            }

            $found = array_merge($found, $this->leaks($value, $here));
        }

        return $found;
    }

    /** @return list<string> every registered GET path under a shared report token */
    private function sharedPaths(): array
    {
        $paths = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, 'reports/shared/{token}') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // A route needing a second bound id cannot be called blind, and the creative detail is
            // covered by its own unit guard through `redactRow`.
            if (preg_match('/\{(?!token)[a-z_]+\}/', $uri)) {
                continue;
            }

            $paths[] = $uri;
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    #[Test]
    public function no_shared_endpoint_publishes_money_on_a_link_that_hides_it(): void
    {
        foreach (['snapshot', 'live'] as $mode) {
            $this->sweepOneMode($mode);
        }
    }

    /**
     * One link, every GET route it serves, in ONE mode.
     *
     * Split out because the sweep ran against a SNAPSHOT share only, and `live()` builds its payload
     * from services rather than from stored data — so the one path where a section could be added
     * without being added to the sanitiser's list was the path never swept.
     */
    private function sweepOneMode(string $mode): void
    {
        /*
         * The live-mode link needs a SCOPE or it is not live at all.
         *
         * `ReportShare::isLive()` is `mode === 'live' && scope !== []`, so a scope-less live link
         * answers 409 and the live payload — the one built by services rather than read from stored
         * data, and therefore the one where a section can be added without being added to the
         * sanitiser's list — was never swept. The snapshot link deliberately keeps NO scope, because
         * that is the shape the seeders create and the shape that turned an empty project id into a
         * 500.
         */
        $scope = $mode === 'live' ? [
            'project_id' => (string) $this->report->project_id,
            /*
             * The campaign, and NOT an empty list.
             *
             * An empty ceiling fails closed — `LiveReportService` intersects the share's campaigns
             * with the window and an empty set matches nothing — so the live sweep was walking a
             * payload whose `platforms`, `campaigns` and creative sections were all `[]`. It answered
             * 200, inspected six routes, found no money and proved nothing about any section that
             * carries money only when there is data. The same lesson as the roster's flattened row,
             * one level up: a fixture that cannot reach a branch says nothing about it.
             */
            'campaign_ids' => [(string) $this->campaign->getKey()],
            'providers' => [],
            'earliest' => now()->subDays(30)->toDateString(),
            'latest' => now()->toDateString(),
        ] : null;

        [$share, $token] = app(ShareService::class)->create($this->report, array_filter([
            'mode' => $mode,
            'scope' => $scope,
            'hide_spend' => true,
            'hide_revenue' => true,
            'settings' => [
                // Every section ON, so nothing is skipped for being switched off rather than redacted.
                'sections' => ['attribution' => true, 'creatives' => true],
                'creatives' => CreativeVisibility::fromArray([
                    'creatives' => true, 'spend' => false, 'revenue' => false, 'cpa' => false, 'roas' => false,
                    'insights' => true, 'comparison' => true,
                ])->toArray(),
            ],
        ]), null);

        $paths = $this->sharedPaths();
        $this->assertGreaterThan(3, count($paths), 'the route sweep found almost nothing, so it proved almost nothing');

        $answered = 0;
        $leaked = [];
        $declined = [];

        foreach ($paths as $uri) {
            $response = $this->getJson('/'.str_replace('{token}', $token, $uri));

            if ($response->status() !== 200) {
                $declined[] = $uri.' → '.$response->status();
                if ($response->status() === 500) {
                    fwrite(STDERR, "\n500 on {$uri}: ".substr((string) json_encode($response->json()), 0, 400)."\n");
                }

                continue;
            }

            $answered++;
            $found = $this->leaks($response->json());

            foreach ($found as $where => $value) {
                $leaked[] = "{$uri} → {$where} = ".json_encode($value);
            }
        }

        fwrite(STDERR, "\n[{$mode}] answered={$answered} declined=".implode(', ', $declined)."\n");
        $this->assertGreaterThan(1, $answered, "no shared endpoint answered in {$mode} mode, so nothing was inspected");
        $this->assertSame([], $leaked, "a {$mode} link hiding spend and revenue published money:\n".implode("\n", $leaked));
    }

    /**
     * Hiding ONE figure while publishing the other — the case the sweep above cannot express.
     *
     * That sweep hides spend and revenue together, which is the safe direction: with both gone no
     * ratio between them can survive either. The dangerous direction is asymmetric. A link that hides
     * spend and publishes revenue was also publishing `roas`, and ROAS is revenue ÷ spend — so the
     * hidden figure came back with one division, exactly, no estimation. Measured before the fix:
     * sixty roster rows, five ads and three grouped ads carrying it.
     *
     * `roas` was classified by its numerator and so belonged to revenue alone. It belongs to both,
     * because it is built from both, and a hidden figure must take its derivations with it whichever
     * side of the fraction it sits on.
     */
    #[Test]
    public function hiding_spend_takes_the_ratio_that_would_give_it_back(): void
    {
        [, $token] = app(ShareService::class)->create($this->report, [
            'mode' => 'snapshot',
            'hide_spend' => true,
            // Revenue deliberately VISIBLE. With both hidden this test would pass without the fix.
            'hide_revenue' => false,
            'settings' => ['sections' => ['creatives' => true]],
        ], null);

        $body = $this->getJson("/api/v1/reports/shared/{$token}")->assertOk()->json();

        $roster = $body['data']['data']['ads_roster'][0]['metrics'] ?? [];

        $this->assertNull($roster['spend'] ?? null, 'the hidden figure itself survived');
        $this->assertNull($roster['roas'] ?? null, 'roas survived a hidden spend, so revenue ÷ roas returns it');
        $this->assertNotNull(
            $roster['revenue'] ?? null,
            'revenue was stripped though the link never hid it — over-redaction is its own defect',
        );
    }
}
