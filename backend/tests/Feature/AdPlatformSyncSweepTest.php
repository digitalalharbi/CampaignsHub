<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * INTEG-SYNC-001 / INTEG-RAW-001 — synced data arrives on its own, and what arrived is kept.
 *
 * Two claims, each of which has an expensive wrong version:
 *
 * - **The sweep drives itself.** Before it, every figure in the product was as current as the last
 *   time somebody opened the integrations page and pressed sync. The pipeline was real and nothing
 *   drove it.
 * - **The platform's own answer is retained.** A normalised row cannot answer «لماذا الرقم مختلف عن
 *   لوحة المنصة؟», and a mis-mapped field is invisible in the normalised table because a wrong number
 *   looks exactly like a right one.
 */
final class AdPlatformSyncSweepTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Sweep', 'slug' => 'sweep-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── What the platform said is kept beside what we made of it ──────────────────────────────

    public function test_a_sync_retains_the_platform_payload_beside_the_normalised_rows(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');
        $this->knownCampaign($account, 'c1');

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'campaign_id' => 'c1', 'date_start' => '2026-08-01',
            'spend' => '25.00', 'impressions' => '1000', 'clicks' => '50',
        ]]])]);

        $run = app(AccountMetricsSyncer::class)->sync(
            $account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'),
        );

        $this->assertSame('success', $run->status);

        $raw = IntegrationRawPayload::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('meta', $raw->provider);
        $this->assertSame('insights', $raw->resource);
        $this->assertSame($run->getKey(), $raw->sync_run_id);
        $this->assertSame('2026-08-01', $raw->window_start->toDateString());
        // The platform's own body, unchanged — the campaign id is still where Meta put it.
        $this->assertSame('c1', $raw->payload['data'][0]['campaign_id']);

        // …and how many metrics came OUT of it, which is what makes a mapping bug visible.
        $this->assertSame(3, $raw->normalised_rows);
        $this->assertSame(3, DailyMetric::withoutGlobalScopes()->count());

        // The account now knows when it was last spoken to.
        $this->assertNotNull($account->refresh()->last_synced_at);
    }

    /**
     * A payload that produced nothing still gets kept, and that is the point.
     *
     * Four hundred rows in and zero metrics out is the signature of a mapping bug — and it is exactly
     * the case where a system that only retained SUCCESSFUL mappings would have thrown away the
     * evidence.
     */
    public function test_a_payload_that_normalised_to_nothing_is_still_retained(): void
    {
        $this->configure('meta');
        $account = $this->account('meta');

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'campaign_id' => 'never-discovered', 'date_start' => '2026-08-01', 'spend' => '9.00',
        ]]])]);

        $run = app(AccountMetricsSyncer::class)->sync(
            $account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'),
        );

        $this->assertSame('partial', $run->status);

        $raw = IntegrationRawPayload::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, $raw->normalised_rows);
        $this->assertSame('never-discovered', $raw->payload['data'][0]['campaign_id']);
    }

    /** Nothing is retained for a platform we never called. */
    public function test_an_awaiting_credentials_sync_retains_no_payload(): void
    {
        $account = $this->account('linkedin');

        $run = app(AccountMetricsSyncer::class)->sync(
            $account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'),
        );

        $this->assertSame('awaiting_credentials', $run->status);
        $this->assertSame(0, IntegrationRawPayload::withoutGlobalScopes()->count());
    }

    // ── The sweep ─────────────────────────────────────────────────────────────────────────────

    public function test_the_sweep_queues_a_job_for_every_assigned_account(): void
    {
        Queue::fake();

        $this->account('meta');
        $this->account('tiktok');

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, 2);
    }

    /**
     * An account behind a broken connection is SKIPPED, not attempted.
     *
     * Attempting it writes a failure row every half hour for ever, burying the one failure that means
     * something under thousands that mean "we already knew".
     */
    public function test_the_sweep_skips_accounts_behind_a_connection_that_is_not_connected(): void
    {
        Queue::fake();

        $working = $this->account('meta');
        $broken = $this->account('tiktok');

        ProviderConnection::withoutGlobalScopes()
            ->whereKey($broken->provider_connection_id)
            ->update(['status' => 'error', 'last_error' => 'Session revoked']);

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(
            SyncAccountMetricsJob::class,
            fn (SyncAccountMetricsJob $job) => $job->uniqueId() !== ''
                && str_contains($job->uniqueId(), (string) $working->id),
        );
    }

    /** The window looks back, because platforms restate recent days. */
    public function test_the_sweep_re_asks_for_the_last_week_so_late_attribution_can_settle(): void
    {
        Queue::fake();
        $this->account('meta');

        $this->artisan('integrations:sync', ['--days' => 7])->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job) => str_contains(
            $job->uniqueId(),
            Carbon::now()->subDays(7)->toDateString(),
        ));
    }

    /**
     * One in-flight job per account per window.
     *
     * The scheduler, an operator pressing sync and a webhook can all ask for the same window at once.
     * The upsert makes the WRITE idempotent; the unique id makes the WORK idempotent — three calls to
     * a rate-limited API for identical data is a quota spent on nothing.
     */
    public function test_the_same_account_and_window_is_one_job_and_a_different_window_is_another(): void
    {
        $account = $this->account('meta');

        $a = new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-07');
        $b = new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-07');
        $backfill = new SyncAccountMetricsJob((string) $account->id, '2026-01-01', '2026-01-31');

        $this->assertSame($a->uniqueId(), $b->uniqueId());
        // A backfill must not queue behind the daily sweep.
        $this->assertNotSame($a->uniqueId(), $backfill->uniqueId());
    }

    // ── Retention ─────────────────────────────────────────────────────────────────────────────

    public function test_pruning_removes_old_payloads_and_keeps_recent_ones(): void
    {
        $account = $this->account('meta');

        foreach ([120, 100, 10] as $daysAgo) {
            IntegrationRawPayload::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'external_account_id' => $account->id,
                'provider' => 'meta',
                'resource' => 'insights',
                'payload' => ['day' => $daysAgo],
                'fetched_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        $this->artisan('integrations:prune-raw', ['--days' => 90])->assertSuccessful();

        $kept = IntegrationRawPayload::withoutGlobalScopes()->pluck('payload');
        $this->assertCount(1, $kept);
        $this->assertSame(10, $kept->first()['day']);
    }

    // ── Token refresh, ahead of need ──────────────────────────────────────────────────────────

    public function test_the_refresh_command_renews_a_token_before_it_expires(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'FRESH', 'expires_in' => 5184000])]);

        $connection = $this->connection('meta', Carbon::now()->addMinutes(10));

        $this->artisan('integrations:refresh-tokens')->assertSuccessful();

        $this->assertSame('FRESH', app(TokenVault::class)->stored($connection->refresh())->accessToken);
        $this->assertSame('connected', $connection->status);
    }

    /** A revoked authorisation becomes a visible connection state, hours before figures stop. */
    public function test_a_refresh_that_cannot_succeed_marks_the_connection_for_re_authorisation(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has been revoked']], 400)]);

        $connection = $this->connection('meta', Carbon::now()->addMinutes(10));

        $this->artisan('integrations:refresh-tokens')->assertSuccessful();

        $connection->refresh();
        $this->assertSame('error', $connection->status);
        $this->assertStringContainsString('revoked', (string) $connection->last_error);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function connection(string $provider, ?Carbon $expiresAt = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', $expiresAt ?? Carbon::now()->addDays(30)),
            connectionName: $provider,
        );
    }

    private function account(string $provider): ExternalAccount
    {
        $connection = $this->connection($provider);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => "act_{$provider}",
            'name' => ucfirst($provider),
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        /*
         * ORCH-100 — an account this sweep is expected to pick up is an ASSIGNED account.
         *
         * The sweep used to queue every discovered row, so this fixture never needed the assignment.
         * It does now, and that is the point: discovery alone catalogued 309 Snapchat accounts, and a
         * sweep that treats a catalogue as a work list pulls all 309. The discrimination itself is
         * asserted in `ProjectIntegrationAssignmentTest`; here the accounts are assigned so these
         * tests keep testing what they are about — windows, uniqueness and connection health.
         */
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        return $account;
    }

    /** A campaign the pipeline already knows about, so its insights can be mapped. */
    private function knownCampaign(ExternalAccount $account, string $externalId): void
    {
        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Known', 'objective' => 'awareness', 'status' => 'active',
        ]);

        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'unified_campaign_id' => $unified->id,
            'provider' => $account->provider,
            'external_id' => $externalId,
            'name' => 'Known',
            'status' => 'active',
        ]);
    }
}
