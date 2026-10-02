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
 * INTEG-OPENAI-001 §27 — ChatGPT Ads in a client's report, through the engine every provider uses.
 *
 * The report engine reads the canonical order rather than a list of its own, so this provider
 * participates by construction. «By construction» is the claim worth testing: it is exactly the kind
 * that stops being true the first time somebody writes a provider set into a section.
 *
 * This is the surface where being absent costs the most. A client reads the report; a platform
 * missing from it is spend that was made and never shown, and nobody looking at the page can tell
 * the difference between «that platform did nothing» and «that platform is not in this report».
 */
final class OpenAiAdsInReportsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private UnifiedCampaign $campaign;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'R', 'slug' => 'rep-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->getKey());

        $ws = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $ws->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'ChatGPT campaign', 'status' => 'active', 'objective' => 'sales',
        ]);

        /*
         * Spend, clicks, impressions and conversions — and NO revenue.
         *
         * Deliberate: the figures a report leads with must come from what the provider reported, and
         * the absent one must stay absent rather than appearing as a zero beside the others.
         */
        foreach ([['spend', 400.0], ['clicks', 90.0], ['impressions', 9000.0], ['conversions', 12.0]] as [$key, $value]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'openai_ads',
                'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value,
            ]);
        }

        $this->report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'live', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** The platform section names it, and the figures are the ones that were reported. */
    public function test_a_shared_report_shows_chatgpt_ads_with_its_figures(): void
    {
        $payload = $this->livePayload();

        $platforms = $payload['platforms'] ?? [];

        $this->assertNotEmpty(
            $platforms,
            'the report has no platform section at all; keys were: '.implode(', ', array_keys($payload)),
        );

        $row = collect($platforms)->firstWhere('provider', 'openai_ads')
            ?? collect($platforms)->firstWhere('key', 'openai_ads');

        $this->assertNotNull($row, 'ChatGPT Ads spent in this period and the report does not mention it');
        $this->assertEqualsWithDelta(400.0, (float) ($row['spend'] ?? $row['value'] ?? 0), 0.01);
    }

    /**
     * **The payload carries the CANONICAL key, and no alias.**
     *
     * The name is deliberately not here. This payload serves a report the client renders, and the
     * interface turns the key into «إعلانات ChatGPT» or «ChatGPT Ads» from the canonical display
     * names — which is why a report printing `openai_ads` to a customer is a client-side defect, and
     * is held by `platformFilterCoverage.test.ts` there rather than asserted here.
     *
     * What this side owes is a key the interface can resolve. `openai`, `chatgpt` and
     * `openai_advertising` all arrive from somewhere in this codebase, and a payload carrying one of
     * them would render as itself — the raw spelling on a page somebody pays for.
     */
    public function test_the_payload_carries_the_canonical_provider_key(): void
    {
        $platforms = $this->livePayload()['platforms'] ?? [];

        $keys = array_values(array_filter(array_map(
            static fn (array $row): ?string => $row['provider'] ?? $row['key'] ?? null,
            $platforms,
        )));

        $this->assertContains('openai_ads', $keys);

        foreach (['openai', 'chatgpt', 'chatgpt_ads', 'openai_advertising'] as $alias) {
            $this->assertNotContains($alias, $keys, "the report carries «{$alias}», which no label table resolves");
        }
    }

    /** @return array<string, mixed> */
    private function livePayload(): array
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'form' => null,
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['openai_ads'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }
}
