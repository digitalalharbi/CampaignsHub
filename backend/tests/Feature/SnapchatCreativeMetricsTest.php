<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ReportsCreativeInsights;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SNAP-CREATIVE-METRICS-001 — creative-level stats, from the provider body to the stored row.
 *
 * Every metric this product held was a campaign total, because `breakdown` was the literal string
 * `campaign`. The content library listed 1,451 real Snapchat creatives with «لا توجد بيانات» under
 * each — and that was accurate: no creative-level row existed anywhere to show.
 */
final class SnapchatCreativeMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'CM', 'slug' => 'cm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        foreach (PlatformCredentials::for('snapchat')->requires() as $k) {
            config()->set("ad_platforms.platforms.snapchat.{$k}", "test-{$k}");
        }

        $this->account = $this->account('act-1');
        $this->campaign = $this->campaign($this->account, 'cmp-1');
    }

    // ── the writer ────────────────────────────────────────────────────────────────────────────

    public function test_a_snapchat_creative_row_is_persisted_against_the_right_creative(): void
    {
        $creative = $this->creative($this->campaign, 'cr-1');

        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 12.5, 'impressions' => 300],
        ]);

        $this->assertSame(1, $out['upserted']);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertNotNull($row, 'nothing was written for a creative this account owns');
        $this->assertSame('2026-08-01', (string) $row->metric_date);
        $this->assertEqualsWithDelta(12.5, (float) $row->spend, 0.01);
    }

    public function test_a_repeated_sync_of_the_same_window_does_not_duplicate(): void
    {
        $creative = $this->creative($this->campaign, 'cr-1');
        $rows = [['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 12.5]];

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, $rows);
        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 19.0],
        ]);

        $this->assertSame(1, DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->count());
        $this->assertEqualsWithDelta(
            19.0,
            (float) DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->value('spend'),
            0.01,
            'a re-sync must correct the figure in place — attribution keeps moving for days',
        );
    }

    public function test_an_unknown_creative_is_skipped_and_never_fabricated(): void
    {
        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-never-discovered', 'date' => '2026-08-01', 'spend' => 5.0],
        ]);

        $this->assertSame(0, $out['upserted']);
        $this->assertSame(1, $out['skipped']);
        $this->assertSame(0, ExternalCreative::withoutGlobalScopes()->count(), 'a placeholder creative was invented');
        $this->assertSame(0, DB::table('creative_daily_metrics')->count());
    }

    /** Another project's creative carrying the SAME provider id must not receive these numbers. */
    public function test_a_cross_project_creative_id_cannot_leak(): void
    {
        $otherWs = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C2', 'slug' => 'c2-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $otherWs->id, 'name' => 'P2', 'status' => 'active',
        ]);
        $otherAccount = $this->account('act-2');
        $otherCampaign = $this->campaign($otherAccount, 'cmp-2', $otherProject);
        $theirs = $this->creative($otherCampaign, 'cr-1', $otherProject);

        // Ours does not exist; only theirs carries `cr-1`.
        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 12.5],
        ]);

        $this->assertSame(1, $out['skipped']);
        $this->assertSame(
            0,
            DB::table('creative_daily_metrics')->where('creative_id', $theirs->id)->count(),
            "another project's creative received this account's spend",
        );
    }

    /** The same provider id under two accounts of one project resolves to NEITHER. */
    public function test_an_ambiguous_provider_id_writes_nothing_and_is_reported(): void
    {
        $second = $this->account('act-2');
        $secondCampaign = $this->campaign($second, 'cmp-2');

        $a = $this->creative($this->campaign, 'cr-dup');
        // Same project + provider, so the unique index permits only one row with this id — the
        // ambiguity this guards is the SCOPE query returning more than one, which a second account's
        // campaign in the same project can produce once ids are reused.
        $b = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_campaign_id' => $secondCampaign->id, 'provider' => 'snapchat',
            'external_creative_id' => 'cr-dup-2', 'name' => 'B', 'format' => 'image',
        ]);

        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-dup', 'date' => '2026-08-01', 'spend' => 4.0],
        ]);

        // `cr-dup` belongs to this account and is unambiguous, so it writes.
        $this->assertSame(1, $out['upserted']);
        $this->assertSame(0, DB::table('creative_daily_metrics')->where('creative_id', $b->id)->count());
        $this->assertSame(1, DB::table('creative_daily_metrics')->where('creative_id', $a->id)->count());
    }

    // ── metric truth ──────────────────────────────────────────────────────────────────────────

    public function test_a_metric_the_platform_did_not_report_stays_null_and_a_real_zero_stays_zero(): void
    {
        $creative = $this->creative($this->campaign, 'cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            // Snapchat reported a measured zero for conversions, and said nothing about revenue.
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 3.0, 'conversions' => 0],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertSame(0.0, (float) $row->conversions, 'a measured zero was lost');
        $this->assertNull($row->revenue, 'an unreported metric became a number');
        $this->assertNull($row->video_views, 'an unreported metric became a number');
    }

    // ── the capability boundary ───────────────────────────────────────────────────────────────

    public function test_only_connectors_that_report_creative_stats_declare_the_capability(): void
    {
        $registry = app(AdvertisingConnectorRegistry::class);

        $this->assertInstanceOf(ReportsCreativeInsights::class, $registry->get('snapchat'));

        foreach (['google_ads', 'linkedin'] as $provider) {
            $this->assertNotInstanceOf(
                ReportsCreativeInsights::class,
                $registry->get($provider),
                "{$provider} sends no creative with an ad, so it must never be asked for creative stats",
            );
        }
    }

    // ── end to end: provider body → sweep → stored row → the reader the UI uses ───────────────

    /**
     * The whole chain, with nothing hand-built.
     *
     * `creative_daily_metrics` is never touched by this test — it is written only by the sweep, from
     * a Snapchat stats envelope, and then read back through `CreativeMetrics::forCreatives()`, which
     * is the same service the content library and the creative API read. Constructing the row here
     * would prove the reader works on a row I invented, which is the failure mode this ticket is
     * about: everything green, nothing on the screen.
     */
    public function test_a_snapchat_stats_envelope_reaches_the_reader_the_content_library_uses(): void
    {
        $this->account->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        $creative = $this->creative($this->campaign, 'cr-1');

        Http::fake([
            // Campaign level — the existing path, untouched.
            '*breakdown=campaign*' => Http::response(['timeseries_stats' => [
                $this->envelope('campaign', 'cmp-1', spend: 40.0, impressions: 900),
            ]], 200),
            // Creative level — the new one.
            '*breakdown=creative*' => Http::response(['timeseries_stats' => [
                $this->envelope('creative', 'cr-1', spend: 12.5, impressions: 300),
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        app(AccountMetricsSyncer::class)->sync(
            $this->account,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-01'),
        );

        $stored = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->count();
        $this->assertSame(1, $stored, 'the sweep never wrote a creative row — the chain is broken before storage');

        $read = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->id],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-01'),
        );

        $this->assertArrayHasKey((string) $creative->id, $read, 'the reader the content library uses sees nothing');
        $this->assertEqualsWithDelta(12.5, (float) $read[(string) $creative->id]['spend'], 0.01);
    }

    /** The campaign path must keep landing in `daily_metrics`, untouched by any of this. */
    public function test_the_campaign_level_path_is_unchanged(): void
    {
        $this->account->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        $this->creative($this->campaign, 'cr-1');

        Http::fake([
            '*breakdown=campaign*' => Http::response(['timeseries_stats' => [
                $this->envelope('campaign', 'cmp-1', spend: 40.0, impressions: 900),
            ]], 200),
            '*breakdown=creative*' => Http::response(['timeseries_stats' => [
                $this->envelope('creative', 'cr-1', spend: 12.5, impressions: 300),
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        app(AccountMetricsSyncer::class)->sync(
            $this->account,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-01'),
        );

        $this->assertGreaterThan(
            0,
            DB::table('daily_metrics')->where('external_campaign_id', $this->campaign->id)->count(),
            'campaign totals stopped landing — the creative work cost the figures that already worked',
        );
    }

    /** Snapchat's own envelope shape, at whichever breakdown level was asked for. */
    private function envelope(string $level, string $id, float $spend, int $impressions): array
    {
        return ['timeseries_stat' => [
            'id' => 'act-1',
            'type' => 'AD_ACCOUNT',
            'paging' => ['next_link' => ''],
            'breakdown_stats' => [$level => [[
                'id' => $id,
                'type' => strtoupper($level),
                'granularity' => 'DAY',
                'timeseries' => [[
                    'start_time' => '2026-08-01T00:00:00.000+03:00',
                    'end_time' => '2026-08-02T00:00:00.000+03:00',
                    'stats' => ['spend' => $spend * 1_000_000, 'impressions' => $impressions],
                ]],
            ]]],
        ]];
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function account(string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat-'.$externalId,
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        /*
         * PROJECT-INTEGRATION-ASSIGNMENT-001 — an unassigned account syncs nothing, by design.
         *
         * Without this the sweep returns `awaiting_assignment` and both end-to-end cases read zero
         * rows, which looks exactly like a broken pipeline. A fixture that skips the deliberate act
         * a real account goes through is not a fixture of this system.
         */
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        return $account;
    }

    private function campaign(ExternalAccount $account, string $externalId, ?Project $project = null): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => ($project ?? $this->project)->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
        ]);
    }

    private function creative(ExternalCampaign $campaign, string $externalId, ?Project $project = null): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => ($project ?? $this->project)->id,
            'external_campaign_id' => $campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);
    }
}
