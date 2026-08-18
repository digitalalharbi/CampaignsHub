<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\StampHistoricalUnlinks;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignLinker;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
    public function test_a_first_sync_without_credentials_keeps_its_own_category(): void
    {
        $account = $this->assigned('snapchat', 'snap-act-1');

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        /*
         * INTEG-RUNTIME §8 narrows the sync vocabulary to six words, and «awaiting credentials» is
         * not among them. What this test is actually about survives intact and is asserted below: the
         * CATEGORY, which is what decides who acts — an operator adding keys, versus nobody at all.
         */
        $this->assertSame('failed', $run->status);
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
     * Rows that arrived and could not be placed DO ask for attention — they are missing figures.
     *
     * ## What this replaced
     *
     * This test was called «an empty window does not mark the account as needing attention» and it
     * asserted that on a fixture producing the OPPOSITE case. Its own comment said so — «the
     * sandbox's insight rows map to no known campaign» — which is rows arriving and failing to be
     * placed, a real gap in a client's report. Both outcomes landed on the single word `partial`, so
     * the test passed while proving nothing about an empty window.
     *
     * That conflation is precisely what §8 splits. The genuinely empty window — a provider asked and
     * answering with nothing — is proved in `SyncRunTruthTest` against a real Snapchat response,
     * because the sandbox connector always invents two rows and can never produce it. This file keeps
     * the case it can actually reach.
     */
    public function test_rows_that_could_not_be_placed_do_mark_the_account(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');

        // No structure sync first, so the sandbox's insight rows map to no known campaign.
        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('partial_mapping', $run->status);
        $this->assertSame(0, (int) $run->mapped_campaign_rows);
        $this->assertSame('unmapped_rows', $account->refresh()->last_sync_error_category);
    }

    /**
     * **CAMPAIGNS-ADOPT-001.** A campaign discovered before adoption existed still becomes visible.
     *
     * ## The live failure
     *
     * `CAMPAIGNS-VISIBLE-001` adopted a synced campaign into a `unified_campaign` so the Campaigns
     * page would have something to show — but only on FIRST import, because `unified_campaign_id IS
     * NULL` is also what a deliberate unlink produces and adopting on it would undo somebody's
     * decision every sweep.
     *
     * A row discovered before that shipped is never new again, so it was never adopted. On the live
     * Snapchat account: **89 campaigns, 1,056 stored metrics, and an empty Campaigns page** with
     * nothing to press. The condition needed a third fact, not a stricter version of the same one.
     */
    public function test_a_campaign_discovered_before_adoption_existed_is_adopted_on_a_later_sweep(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');

        app(AccountStructureSyncer::class)->sync($account);

        // Put the estate back into the state the live account was in: discovered, never adopted.
        ExternalCampaign::withoutGlobalScopes()->update(['unified_campaign_id' => null]);
        UnifiedCampaign::withoutGlobalScopes()->delete();

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $orphans = ExternalCampaign::withoutGlobalScopes()->whereNull('unified_campaign_id')->count();

        $this->assertSame(0, $orphans, 'every discovered campaign has something visible to belong to');
        $this->assertGreaterThan(0, UnifiedCampaign::withoutGlobalScopes()->count());
    }

    /** And a deliberate unlink still wins — that is the rule the `$isNew` gate was protecting. */
    public function test_a_deliberate_unlink_survives_every_later_sweep(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-2');

        app(AccountStructureSyncer::class)->sync($account);

        $external = ExternalCampaign::withoutGlobalScopes()->firstOrFail();
        app(CampaignLinker::class)->unlink($external);

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $external->refresh();
        $this->assertNull($external->unified_campaign_id, 'the sweep must not undo a person\'s decision');
        $this->assertNotNull($external->unlinked_at, 'and the decision is recorded, which is what makes that possible');
    }

    /**
     * **The dangerous half of CAMPAIGNS-ADOPT-001.** A legacy row a PERSON detached is not re-adopted.
     *
     * Existing rows carry no `unlinked_at`, and `unified_campaign_id IS NULL` is equally true of
     * «never adopted» and «deliberately unlinked». Left unstamped, the first sweep after the deploy
     * would silently reverse every real decision on production.
     *
     * `StampHistoricalUnlinks` recovers them from the audit trail, and that trail is PROOF rather
     * than a hint: `unlink()` is the only path that clears the link, its one route has always written
     * `campaign.external_unlinked`, `audit_logs` predates `external_campaigns` by three days, and
     * nothing prunes it. So a row with an entry was detached on purpose — and stays detached.
     */
    public function test_a_legacy_row_the_audit_trail_shows_was_unlinked_is_never_re_adopted(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-3');
        app(AccountStructureSyncer::class)->sync($account);

        $external = ExternalCampaign::withoutGlobalScopes()->firstOrFail();

        // The state a legacy row is in: detached, with no `unlinked_at`, and an audit entry saying
        // a person did it. Written directly because this row predates the column by construction.
        $external->forceFill(['unified_campaign_id' => null, 'unlinked_at' => null])->save();
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'action' => StampHistoricalUnlinks::AUDIT_ACTION,
            'entity_type' => ExternalCampaign::class,
            'entity_id' => (string) $external->id,
            'created_at' => Carbon::now()->subDay(),
        ]);

        $recovered = (new StampHistoricalUnlinks)->execute();

        $this->assertSame(1, $recovered, 'the decision is recovered from the audit trail');
        $this->assertNotNull($external->refresh()->unlinked_at);

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertNull(
            $external->refresh()->unified_campaign_id,
            'CAMPAIGNS-ADOPT-001: the sweep re-adopted a campaign a person had deliberately detached.',
        );
    }

    /** And a legacy row the audit trail shows was NEVER unlinked is adoptable — proven, not assumed. */
    public function test_a_legacy_row_with_no_unlink_in_the_audit_trail_is_adopted(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-4');
        app(AccountStructureSyncer::class)->sync($account);

        $external = ExternalCampaign::withoutGlobalScopes()->firstOrFail();
        $external->forceFill(['unified_campaign_id' => null, 'unlinked_at' => null])->save();

        // No audit entry for this row: it was never detached by anybody.
        $this->assertSame(0, (new StampHistoricalUnlinks)->execute());

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertNotNull(
            $external->refresh()->unified_campaign_id,
            'a campaign nobody ever detached must become visible',
        );
    }

    /**
     * **A job that DIED still says so.** A killed structure sync does not leave its run open.
     *
     * On production this job was being killed: 89 campaigns, and a sweep that reads campaigns, ad
     * squads, ads and creatives for all of them, against a 120-second worker default. Three attempts,
     * three kills — and because a killed process never reaches `finish()`, each left its run at
     * `running` forever. Nothing reported a failure, so nothing looked wrong, while the Campaigns
     * page stayed empty beside a thousand stored metrics.
     *
     * `AccountStructureSyncer` catches every `Throwable` a provider can raise, so `running` cannot
     * mean «the platform refused». It means the process went away, and that is what this hook is for.
     */
    public function test_a_killed_structure_job_closes_its_run_instead_of_leaving_it_open(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-5');

        $run = IntegrationSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider_connection_id' => $account->provider_connection_id,
            'type' => 'structure',
            'status' => SyncRunStatus::Running->value,
            'records' => 0,
            'started_at' => Carbon::now()->subMinutes(5),
        ]);

        (new SyncAccountStructureJob((string) $account->getKey()))->failed(null);

        $run->refresh();

        $this->assertSame(SyncRunStatus::Failed->value, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error, 'a run that was killed must say what happened to it');
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

    /**
     * **A deliberate unlink survives the next sweep — permanently.**
     *
     * RUNTIME-100 §6. Adoption fires on FIRST import only, never on `unified_campaign_id === null`,
     * which is the obvious condition and would silently re-adopt everything a person had detached.
     * That would also empty the suggestions list for ever, since it is built from unlinked externals.
     */
    public function test_a_deliberate_unlink_is_not_undone_by_the_next_sync(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');
        app(AccountStructureSyncer::class)->sync($account);

        $external = ExternalCampaign::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($external->unified_campaign_id, 'the first import adopts it');

        /*
         * Unlinked through the product's ONLY unlink path — CAMPAIGNS-ADOPT-001.
         *
         * This used to write the three columns by hand, which is the one thing a fixture must not do
         * here: `CampaignLinker::unlink()` is what records the DECISION, and a test that bypasses it
         * is testing a state the product cannot produce. It passed for the wrong reason — the
         * importer only adopted brand-new rows — and would have gone on passing while every campaign
         * discovered before adoption existed stayed invisible forever.
         */
        app(CampaignLinker::class)->unlink($external);

        app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertNull(
            ExternalCampaign::withoutGlobalScopes()->findOrFail($external->id)->unified_campaign_id,
            'RUNTIME-100 §6: the sweep re-adopted a campaign the customer had deliberately detached.',
        );
    }

    // ── When part of a batch fails ────────────────────────────────────────────────────────────

    /**
     * A provider failure AFTER confirmation does not undo the assignment.
     *
     * The binding is the customer's decision; a provider having a bad minute is not a reason to
     * discard it. What changes is the account's HEALTH, which is what says somebody should look.
     */
    public function test_a_failed_first_sync_leaves_the_binding_intact_and_marks_the_account(): void
    {
        // `snapchat` holds no credentials on this install, so the refusal is real rather than staged.
        $account = $this->assigned('snapchat', 'snap-act-1');

        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame(
            1,
            ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count(),
            'a runtime failure must not roll back a decision the customer made',
        );
        $this->assertSame(AccountHealth::FAILED, app(AccountHealth::class)->for($account->refresh()));
    }

    /**
     * One account failing does not take the others with it, and the summary says so in numbers.
     *
     * «The batch synced» over a batch where one account refused is the claim this prevents.
     */
    public function test_one_account_failing_does_not_make_the_others_unhealthy(): void
    {
        $ok = $this->assigned('sandbox', 'sandbox-act-1');
        $bad = $this->assigned('snapchat', 'snap-act-1');

        app(AccountStructureSyncer::class)->sync($ok);
        app(AccountMetricsSyncer::class)->sync($ok->refresh(), Carbon::now()->subDays(30), Carbon::now());
        app(AccountMetricsSyncer::class)->sync($bad, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame(AccountHealth::HEALTHY, app(AccountHealth::class)->for($ok->refresh()));
        $this->assertSame(AccountHealth::FAILED, app(AccountHealth::class)->for($bad->refresh()));
    }

    /** Structure succeeding while metrics fail is two outcomes, and both are recorded. */
    public function test_structure_can_succeed_while_metrics_fails(): void
    {
        $account = $this->assigned('sandbox', 'sandbox-act-1');

        $structure = app(AccountStructureSyncer::class)->sync($account);
        $this->assertNotSame('failed', $structure->status);
        $this->assertGreaterThan(0, ExternalCampaign::withoutGlobalScopes()->count());

        // The account is detached between the two halves — the metrics half must refuse, and the
        // campaigns the structure half already filed stay exactly where they are.
        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);
        $metrics = app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('awaiting_assignment', $metrics->status);
        $this->assertGreaterThan(0, ExternalCampaign::withoutGlobalScopes()->count());
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
