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

        $campaign = UnifiedCampaign::create([
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
        foreach (['orders' => 60.0, 'revenue' => 18000.0] as $key => $value) {
            DailyMetric::create([
                'tenant_id' => $tenant->getKey(),
                'project_id' => $project->getKey(),
                'unified_campaign_id' => $campaign->getKey(),
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'provider' => 'snapchat',
                'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value,
                'project_currency' => 'SAR',
                'attribution_window' => '7d_click',
                'source_type' => 'platform_reported',
            ]);
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
                'platforms' => [$row],
                'campaigns' => [$row],
                'timeseries' => [$row],
                'budget' => [$row],
                'ads' => [$row],
                'ads_roster' => [$row],
                'worst_creatives' => [$row],
                'top_creatives' => [$row],
                'ads_groups' => [['ads' => [$row]]],
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
            if (is_string($key) && in_array($key, $forbidden, true) && ! is_bool($value)
                && $value !== null && $value !== [] && $value !== '') {
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
        [$share, $token] = app(ShareService::class)->create($this->report, [
            'mode' => 'snapshot',
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
        ], null);

        $paths = $this->sharedPaths();
        $this->assertGreaterThan(3, count($paths), 'the route sweep found almost nothing, so it proved almost nothing');

        $answered = 0;
        $leaked = [];

        foreach ($paths as $uri) {
            $response = $this->getJson('/'.str_replace('{token}', $token, $uri));

            if ($response->status() !== 200) {
                continue;
            }

            $answered++;
            $found = $this->leaks($response->json());

            foreach ($found as $where => $value) {
                $leaked[] = "{$uri} → {$where} = ".json_encode($value);
            }
        }

        $this->assertGreaterThan(1, $answered, 'no shared endpoint answered, so nothing was inspected');
        $this->assertSame([], $leaked, "a link hiding spend and revenue published money:\n".implode("\n", $leaked));
    }
}
