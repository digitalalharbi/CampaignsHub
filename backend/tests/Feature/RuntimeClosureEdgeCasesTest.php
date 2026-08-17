<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RUNTIME-100 — the cases a happy-path pipeline test cannot reach.
 *
 * A first sync that works is one outcome out of several, and the others are the ones that decide
 * whether somebody trusts the product: a provider that answers with nothing, a provider that refuses
 * outright, the same window synced twice, and the sweep deciding what to touch at all. Each of those
 * has a right answer that differs from «looks fine», and each is asserted here rather than assumed.
 */
final class RuntimeClosureEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => 'Retainer',
            'status' => 'active',
        ]);
    }

    // ── Several providers, one project ────────────────────────────────────────────────────────

    /**
     * RUNTIME-100 §45 — a project may draw from several platforms, and the totals are one total.
     *
     * The point is not that addition works. It is that two providers' data reaches ONE project
     * without either of them being able to claim it: both go through the same binding, so the sum is
     * the sum of what somebody assigned, not of what happened to be discovered.
     */
    public function test_a_project_aggregates_across_providers(): void
    {
        $sandbox = $this->assigned('sandbox', 'sandbox-act-1');
        $second = $this->assigned('sandbox', 'sandbox-act-2');

        foreach ([$sandbox, $second] as $account) {
            app(AccountStructureSyncer::class)->sync($account);
            app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());
        }

        $accountsWithMetrics = DailyMetric::withoutGlobalScopes()
            ->distinct()->count('external_account_id');

        $this->assertSame(2, $accountsWithMetrics, 'both assigned accounts fed the same project');
        $this->assertSame(
            [$this->project->id],
            DailyMetric::withoutGlobalScopes()->distinct()->pluck('project_id')->all(),
        );
    }

    // ── When the first sync does not go well ──────────────────────────────────────────────────

    /**
     * A provider we hold no credentials for is NOT reported as broken.
     *
     * «Awaiting credentials» and «failed» need different people to act — an operator provisions keys,
     * nobody at all acts on a failure that never happened — and collapsing them is how a real failure
     * gets buried under thousands that mean «we already knew».
     */
    public function test_a_first_sync_without_credentials_is_not_a_failure(): void
    {
        $account = $this->assigned('snapchat', 'snap-act-1');

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('awaiting_credentials', $run->status);
        $this->assertStringContainsString('No credentials', (string) $run->error);
        $this->assertSame('awaiting_credentials', $account->refresh()->last_sync_error_category);
        $this->assertNull($account->last_synced_at, 'a refusal is not a sync');
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->count());
    }

    /** A refusal still records the ATTEMPT, so «we tried» is distinguishable from «we never did». */
    public function test_a_refused_first_sync_still_records_that_we_tried(): void
    {
        $account = $this->assigned('snapchat', 'snap-act-1');

        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertNotNull($account->refresh()->last_sync_attempt_at);
        $this->assertNotNull($account->next_sync_at, 'and when we will ask again');
    }

    /**
     * A window the provider had no rows for is `partial` and carries no error category.
     *
     * An account with no spend last Tuesday is not an account that needs attention, and filling the
     * attention count with that noise is how people learn to ignore it.
     */
    public function test_an_empty_window_does_not_mark_the_account_as_needing_attention(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');

        // No structure sync first, so the sandbox's insight rows map to no known campaign.
        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('partial', $run->status);
        $this->assertNull($account->refresh()->last_sync_error_category);
    }

    // ── Doing it twice ────────────────────────────────────────────────────────────────────────

    /** The same window synced twice writes the same rows, not twice as many. */
    public function test_syncing_the_same_window_twice_is_idempotent(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');
        app(AccountStructureSyncer::class)->sync($account);

        app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());
        $after = DailyMetric::withoutGlobalScopes()->count();

        app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame($after, DailyMetric::withoutGlobalScopes()->count());
    }

    /** And a second structure sync neither duplicates the platform campaign nor the visible one. */
    public function test_a_second_structure_sync_duplicates_nothing(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');

        app(AccountStructureSyncer::class)->sync($account);
        $external = ExternalCampaign::withoutGlobalScopes()->count();
        $unified = UnifiedCampaign::withoutGlobalScopes()->count();

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertSame($external, ExternalCampaign::withoutGlobalScopes()->count());
        $this->assertSame($unified, UnifiedCampaign::withoutGlobalScopes()->count());
    }

    /** A campaign somebody renamed or re-classified is not overwritten by the next sweep. */
    public function test_a_persons_edits_to_a_visible_campaign_survive_the_next_sync(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');
        app(AccountStructureSyncer::class)->sync($account);

        $campaign = UnifiedCampaign::withoutGlobalScopes()->firstOrFail();
        $campaign->forceFill(['name' => 'اسم اختاره العميل'])->save();

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertSame(
            'اسم اختاره العميل',
            UnifiedCampaign::withoutGlobalScopes()->findOrFail($campaign->id)->name,
            'adoption happens once; after that the campaign belongs to the person who edited it',
        );
    }

    // ── The sweep ─────────────────────────────────────────────────────────────────────────────

    /** RUNTIME-100 §29 — the scheduled sweep queues assigned accounts and only those. */
    public function test_the_scheduled_sweep_queues_assigned_accounts_only(): void
    {
        Queue::fake();

        $assigned = $this->assigned('sandbox', 'sandbox-act-1');
        $this->discovered('sandbox', 'sandbox-act-unassigned');

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(
            SyncAccountMetricsJob::class,
            fn (SyncAccountMetricsJob $job) => $job->accountId === (string) $assigned->id,
        );
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
    }

    /** The sweep asks for a settling window, not only today, so late attribution can land. */
    public function test_the_sweep_asks_for_more_than_today(): void
    {
        Queue::fake();
        $this->assigned('sandbox', 'sandbox-act-1');

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(
            SyncAccountMetricsJob::class,
            fn (SyncAccountMetricsJob $job) => Carbon::parse($job->from)->lt(Carbon::now()->subDay()),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function discovered(string $provider, string $externalId): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.'-'.$externalId,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
            'last_synced_at' => null,
        ]);
    }

    private function assigned(string $provider, string $externalId): ExternalAccount
    {
        $account = $this->discovered($provider, $externalId);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }
}
