<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AGGREGATION-TRUTH-001 — a total may not present a subset of its contributors as the whole.
 *
 * ## The defect class, stated plainly
 *
 * `MetricsAggregator::PIVOT` is nineteen `COALESCE(SUM(value) FILTER (…), 0)` expressions, and
 * `reportedKeys()` decides «was this reported» from the PRESENCE OF A ROW. Between them, six
 * completely different facts arrive at the same number:
 *
 *   - the provider reported a real zero
 *   - the provider does not support the metric at all
 *   - the campaign was not running
 *   - the sync for that provider FAILED
 *   - the sync has not run recently enough to cover the window
 *   - the money was withheld because no FX rate exists
 *
 * The first is a measurement. The other five are absences, and the absence of a row is not evidence
 * of anything — a point the money half of this file already had to learn once (FX-001) and which the
 * count half never did.
 *
 * The damaging direction is always the same: a provider that SHOULD be contributing and is not
 * disappears from the denominator, and the remaining subset is published as the complete total. That
 * is not a smaller number; it is a wrong number wearing the label of a right one.
 *
 * ## These tests are written to FAIL against current behaviour
 *
 * Each one sets up evidence the aggregator does not consult today, so each fails now and is the
 * specification for the contract that follows.
 */
final class AggregationTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'agg-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── A: the case that must keep working ───────────────────────────────────────────────────────

    /**
     * Two active platforms and one that never ran: the total is the two, and nothing is invented.
     *
     * This is the shape that is ALREADY correct and must stay correct — it is here so the contract
     * cannot buy the failing cases below by breaking this one.
     */
    public function test_an_inactive_platform_neither_contributes_a_zero_nor_blocks_the_total(): void
    {
        $this->spend('snapchat', 'snap', 1000);
        $this->spend('tiktok', 'tik', 500);
        // Meta is connected and has no campaign in this window at all.
        $this->account('meta', 'meta-idle');

        $totals = $this->totals();

        $this->assertEqualsWithDelta(1500.0, (float) $totals['spend'], 0.01);
        $this->assertSame(
            'complete',
            $totals['spend_coverage']['state'] ?? 'complete',
            'A platform that was never running must not make the total partial.',
        );
    }

    // ── E: stale sync ────────────────────────────────────────────────────────────────────────────

    /**
     * A provider whose sync never covered this window is NOT a provider that spent nothing.
     *
     * Snapchat and TikTok are synced through the window. Meta is connected, has a running campaign,
     * and its last sync run stopped a week before the window opens — so nothing it spent in this
     * period has arrived yet. Today the SUM simply omits it and returns 1,500 as «spend», and the
     * reader is told the programme cost 1,500 when a third of it is merely late.
     */
    public function test_a_provider_synced_only_up_to_last_week_does_not_silently_leave_the_total(): void
    {
        $this->spend('snapchat', 'snap', 1000);
        $this->spend('tiktok', 'tik', 500);

        $meta = $this->account('meta', 'meta-stale');
        $this->campaign($meta, 'meta-cmp', status: 'active');
        // Its sync covered a window that ENDS before this one begins.
        $this->syncRun('meta', '2026-07-01', '2026-07-24', 'success');

        $totals = $this->totals();

        $this->assertSame(
            'partial',
            $totals['spend_coverage']['state'] ?? null,
            'A stale contributor was dropped and the remainder published as the complete total.',
        );
        $this->assertContains('meta', $totals['spend_coverage']['stale_contributors'] ?? []);
    }

    // ── F: failed sync ───────────────────────────────────────────────────────────────────────────

    /**
     * A provider whose sync FAILED for this window must not read as a provider that spent nothing.
     *
     * The difference matters most exactly when it is least visible: the number still looks like a
     * number, and the only thing distinguishing «we spent 1,500» from «we spent 1,500 that we know
     * of» is evidence nobody consulted.
     */
    public function test_a_failed_sync_makes_the_total_partial_rather_than_smaller(): void
    {
        $this->spend('snapchat', 'snap', 1000);
        $this->spend('tiktok', 'tik', 500);

        $meta = $this->account('meta', 'meta-broken');
        $this->campaign($meta, 'meta-cmp', status: 'active');
        $this->syncRun('meta', '2026-08-01', '2026-08-31', 'failed', error: 'OAuthException: token expired');

        $totals = $this->totals();

        $this->assertSame('partial', $totals['spend_coverage']['state'] ?? null);
        $this->assertContains('meta', $totals['spend_coverage']['failed_contributors'] ?? []);
        $this->assertNotSame(
            'complete',
            $totals['spend_coverage']['state'] ?? 'complete',
            'A failed contributor was presented as though it had reported nothing.',
        );
    }

    // ── C: stopped mid-period ────────────────────────────────────────────────────────────────────

    /**
     * A platform that stopped mid-window keeps the days it ran and is absent from the days it did not.
     *
     * Neither half may be invented: the days before the stop are real spend, and the days after are
     * not a zero — they are days on which this platform was not a contributor at all. A zero there
     * draws a cliff on a chart that nobody's budget actually fell off.
     */
    public function test_a_platform_that_stopped_mid_window_is_not_a_zero_after_its_stop_date(): void
    {
        $this->spend('snapchat', 'snap', 300, '2026-08-05');
        $this->spend('snapchat', 'snap', 300, '2026-08-20');

        $meta = $this->account('meta', 'meta-stopped');
        $this->campaign($meta, 'meta-cmp', status: 'paused', endsAt: '2026-08-15');
        $this->spend('meta', 'meta-stopped', 200, '2026-08-05', account: $meta);

        $series = $this->series();
        $after = collect($series)->firstWhere('date', '2026-08-20');

        $this->assertNotNull($after, 'The window lost a day that had real spend on it.');
        $this->assertNotContains(
            'meta',
            $after['expected_contributors'] ?? ['meta'],
            'A platform that had already stopped was still expected on a later day, so its absence reads as a drop.',
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function totals(): array
    {
        app(ProjectContext::class)->setProjectId($this->project->id);
        $out = app(MetricsAggregator::class)->totals(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        app(ProjectContext::class)->forget();

        return $out;
    }

    private function series(): array
    {
        app(ProjectContext::class)->setProjectId($this->project->id);
        $out = app(MetricsAggregator::class)->timeseries(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        app(ProjectContext::class)->forget();

        return $out;
    }

    private function account(string $provider, string $label): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $label, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => 'act-'.$label,
            'name' => $label,
            'status' => 'active',
            'currency' => 'USD',
        ])->save();

        return $account;
    }

    private function campaign(ExternalAccount $account, string $label, string $status, ?string $endsAt = null): ExternalCampaign
    {
        $campaign = new ExternalCampaign;
        $campaign->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'external_id' => $label,
            'name' => $label,
            'status' => $status,
            'ends_at' => $endsAt,
        ])->save();

        return $campaign;
    }

    /** One day of spend for a provider, with the account and campaign behind it. */
    private function spend(string $provider, string $label, float $amount, string $date = '2026-08-10', ?ExternalAccount $account = null): void
    {
        $account ??= $this->account($provider, $label);
        $campaign = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)->first()
            ?? $this->campaign($account, $label.'-cmp', status: 'active');

        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'external_campaign_id' => $campaign->id,
            'provider' => $provider,
            'metric_key' => 'spend',
            'metric_date' => $date,
            'value' => $amount,
            'original_amount' => $amount,
            'original_currency' => 'USD',
            'project_currency' => 'USD',
            'exchange_rate' => 1,
        ]);
    }

    /** A sync checkpoint — the evidence that says whether a window was actually covered. */
    private function syncRun(string $provider, string $from, string $to, string $status, ?string $error = null): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider' => $provider,
            'status' => $status,
            'window_start' => $from,
            'window_end' => $to,
            'started_at' => Carbon::parse($from)->toDateTimeString(),
            'finished_at' => Carbon::parse($to)->toDateTimeString(),
            'error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
