<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ReportBreakdowns;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDrilldownFixture;
use Tests\TestCase;
use Throwable;

/**
 * SECURITY CLUSTER 5 — a public report link shows exactly what its ceiling allows, on EVERY surface.
 *
 * The drill-down fixture holds a project with a meta account and a tiktok account. A link granted
 * ONLY the meta account must never publish a figure that belongs to the tiktok one: not in the PDF's
 * platform drill-down, not in the format comparison, not in the store funnel's ad side.
 */
final class ReportLinkCeilingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDrilldownFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDrilldownFixture();
    }

    private function accountId(string $externalId): string
    {
        return (string) ExternalAccount::withoutGlobalScopes()->where('external_id', $externalId)->value('id');
    }

    /** A link granted the meta account (and its one campaign) of a project that also holds tiktok. */
    private function metaOnlyShare(array $over = []): array
    {
        return app(ShareService::class)->create($this->report, $over + [
            'allow_download' => true,
            'scope' => [
                'project_id' => (string) $this->project->id,
                'account_ids' => [$this->accountId('act-meta')],
                'campaign_ids' => [$this->campaigns[0]],
                'providers' => [],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);
    }

    /** @return list<array<string, mixed>> */
    private function printedDrilldowns(ReportShare $share): array
    {
        $token = 'ceil-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id, 'type' => 'document', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->id,
        ], 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data.platform_drilldowns') ?? [];
    }

    public function test_a_narrowed_links_pdf_drilldown_prints_only_the_links_accounts(): void
    {
        // A live-builder report holds no scope of its own: the ceiling lives on the share.
        $this->report->forceFill([
            'scope' => null,
            'config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]],
            'audience' => 'client',
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']],
        ])->saveQuietly();

        [$share] = $this->metaOnlyShare();
        $blocks = collect($this->printedDrilldowns($share));

        $this->assertSame(['meta'], $blocks->pluck('provider')->sort()->values()->all(), 'the PDF drill-down printed a platform the link was never granted');
        $this->assertEqualsWithDelta(300.0, $blocks->firstWhere('provider', 'meta')['totals']['spend'], 0.0001);
    }

    public function test_a_saved_reports_narrower_link_is_not_widened_back_to_the_reports_scope(): void
    {
        $this->report->forceFill([
            'scope' => ['project_ids' => [(string) $this->project->id], 'campaign_ids' => $this->campaigns],
            'config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]],
            'audience' => 'client',
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']],
        ])->saveQuietly();

        [$share] = $this->metaOnlyShare();

        $this->assertSame(['meta'], collect($this->printedDrilldowns($share))->pluck('provider')->sort()->values()->all());
    }

    public function test_the_content_format_comparison_counts_only_the_links_accounts(): void
    {
        // Distinguish the two accounts' creatives by format so the comparison shows who was counted.
        DB::table('external_creatives')->where('provider', 'tiktok')->update(['format' => 'video']);
        [, $raw] = $this->metaOnlyShare();

        $formats = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.content_formats');

        $this->assertIsArray($formats);
        $counted = json_encode($formats['coverage'] ?? []).json_encode($formats['objectives'] ?? []).json_encode($formats['spend_mix'] ?? []);
        $this->assertStringNotContainsString('video', $counted, "the tiktok account's creatives were counted in a meta-only link's comparison");
    }

    public function test_the_store_funnels_ad_side_is_the_links_accounts_only(): void
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla',
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => 'store-1',
            'name' => 'Shop', 'currency' => 'SAR', 'status' => 'active',
        ]);
        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(), 'provider' => 'salla', 'external_id' => 'o-1', 'status' => 'completed',
            'placed_at' => Carbon::parse('2026-07-15'), 'currency' => 'SAR', 'total' => 250,
            'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);

        [, $raw] = $this->metaOnlyShare();
        $funnel = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.store_funnel');

        $this->assertNotNull($funnel);
        $this->assertEqualsWithDelta(300.0, (float) $funnel['totals']['spend'], 0.0001, "the tiktok account's spend was published in a meta-only link's store funnel");
        $this->assertSame(['meta'], collect($funnel['comparisons']['platforms'])->pluck('platform')->all());
    }

    public function test_a_password_protected_links_attribution_needs_the_password(): void
    {
        $this->report->forceFill(['data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31']]])->saveQuietly();
        [, $raw] = app(ShareService::class)->create($this->report, [
            'password' => 'open-sesame',
            'settings' => ['sections' => ['attribution' => true]],
        ], null);

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertStatus(401);
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution", ['X-Report-Password' => 'wrong'])->assertStatus(401);
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution", ['X-Report-Password' => 'open-sesame'])->assertOk();
    }

    public function test_a_failed_generation_leaves_no_project_behind_in_the_worker(): void
    {
        // A window that cannot be read: the generation dies right after the project was set.
        $this->report->setRawAttributes(['period_end' => 'not-a-date'] + $this->report->getAttributes());

        try {
            app(ReportGenerator::class)->generate($this->report);
            $this->fail('the generation was expected to fail');
        } catch (Throwable) {
            // expected
        }

        $this->assertFalse(app(ProjectContext::class)->hasProject(), 'the failed job handed its project to the next job in the worker');
    }

    public function test_a_queue_worker_starts_each_job_without_a_project(): void
    {
        app(ProjectContext::class)->setProjectId((string) $this->project->id);

        event(new JobProcessing('redis', \Mockery::mock(Job::class, ['payload' => []])));

        $this->assertFalse(app(ProjectContext::class)->hasProject());
    }
}
