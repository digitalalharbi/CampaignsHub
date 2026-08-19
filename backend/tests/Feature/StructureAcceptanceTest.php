<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\StructureAcceptance;
use App\Domains\Integrations\Services\StructureSweepTargets;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SNAP-STRUCTURE-RETRY-001 — the acceptance check, and every way it must refuse to pass.
 *
 * The check exists because a fixed wait cannot distinguish the two outcomes that matter: a sweep
 * that took eleven minutes and finished, and a sweep the broker restarted three times and never let
 * finish. Both leave a recent-looking run row. Only watching the run rows created by ONE invocation,
 * for longer than the job is allowed to take, tells them apart.
 *
 * So the criteria are tested directly, one refusal per failure mode, rather than by waiting.
 */
final class StructureAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    /** Per-test, never static: a static would outlive the transaction that created the row. */
    private ?ExternalAccount $account = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
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

    // ── The window itself ─────────────────────────────────────────────────────────────────────

    /**
     * The defect this whole command replaces: a wait shorter than the work.
     */
    public function test_an_observation_window_shorter_than_the_job_is_refused_outright(): void
    {
        $ceiling = (new SyncAccountStructureJob('any'))->timeout;

        $this->artisan('integrations:accept-structure', ['--observe' => $ceiling])
            ->expectsOutputToContain("does not outlast the job's own timeout of {$ceiling}s")
            ->assertFailed();

        $this->artisan('integrations:accept-structure', ['--observe' => 300])
            ->expectsOutputToContain('does not outlast')
            ->assertFailed();
    }

    /**
     * And the production default is on the right side of it, with room.
     */
    public function test_the_default_window_outlasts_the_job_ceiling(): void
    {
        $ceiling = (new SyncAccountStructureJob('any'))->timeout;

        $this->assertGreaterThan($ceiling, 1500, 'The --observe default must outlast the job it watches.');
    }

    // ── The criteria ──────────────────────────────────────────────────────────────────────────

    public function test_a_single_successful_run_with_records_is_accepted(): void
    {
        $run = $this->structureRun(SyncRunStatus::Success, records: 412);

        $this->assertSame([], app(StructureAcceptance::class)->problems(collect([$run]), 1, 1500));
    }

    public function test_a_second_run_for_one_account_is_the_redelivery_this_exists_to_catch(): void
    {
        $problems = app(StructureAcceptance::class)->problems(
            collect([$this->structureRun(SyncRunStatus::Success, 400), $this->structureRun(SyncRunStatus::Success, 400)]),
            1,
            1500,
        );

        $this->assertStringContainsString('re-delivered while still running', implode(' ', $problems));
    }

    public function test_max_attempts_exceeded_is_refused_however_the_run_ended(): void
    {
        $run = $this->structureRun(SyncRunStatus::Failed, 0, 'SyncAccountStructureJob has been attempted too many times.');

        $problems = implode(' ', app(StructureAcceptance::class)->problems(collect([$run]), 1, 1500));

        $this->assertStringContainsString('MaxAttemptsExceeded', $problems);
        $this->assertStringContainsString('re-queued while still running', $problems);
    }

    public function test_a_run_left_running_past_the_window_is_refused(): void
    {
        $run = $this->structureRun(SyncRunStatus::Running, 0, finished: false);

        $problems = implode(' ', app(StructureAcceptance::class)->problems(collect([$run]), 1, 1500));

        $this->assertStringContainsString('still «running» after 1500s', $problems);
    }

    public function test_a_job_that_was_never_queued_is_refused_rather_than_read_as_quiet(): void
    {
        $problems = implode(' ', app(StructureAcceptance::class)->problems(collect(), 1, 1500));

        $this->assertStringContainsString('Only 0 of 1 account(s)', $problems);
        $this->assertStringContainsString('unique-job lock', $problems);
    }

    public function test_success_with_no_records_is_refused_as_impossible(): void
    {
        $problems = implode(' ', app(StructureAcceptance::class)->problems(
            collect([$this->structureRun(SyncRunStatus::Success, 0)]), 1, 1500,
        ));

        $this->assertStringContainsString('success with records=0', $problems);
    }

    /**
     * SNAP-BREAKDOWN-001 as a check: «no rows» and «no rows in the response» are different claims.
     */
    public function test_no_data_beside_a_payload_that_carried_rows_is_named_a_defect(): void
    {
        $run = $this->structureRun(SyncRunStatus::NoData, 0);

        IntegrationRawPayload::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'external_account_id' => $this->account()->id,
            'sync_run_id' => $run->getKey(),
            'provider' => 'snapchat',
            'resource' => 'structure',
            'payload' => ['campaigns' => [['campaign' => ['id' => 'c1']]]],
            'normalised_rows' => 87,
            'fetched_at' => Carbon::now(),
        ]);

        $problems = implode(' ', app(StructureAcceptance::class)->problems(collect([$run]), 1, 1500));

        $this->assertStringContainsString('carries 87 row(s)', $problems);
        $this->assertStringContainsString('a defect, not a quiet account', $problems);
    }

    public function test_no_data_with_an_empty_payload_still_asks_for_the_body_before_accepting(): void
    {
        $problems = implode(' ', app(StructureAcceptance::class)->problems(
            collect([$this->structureRun(SyncRunStatus::NoData, 0)]), 1, 1500,
        ));

        $this->assertStringContainsString('--payload', $problems);
    }

    public function test_partial_mapping_is_not_quietly_accepted_as_a_working_sweep(): void
    {
        $run = $this->structureRun(SyncRunStatus::PartialMapping, 12, '3 row(s) named a parent that has not been discovered yet.');

        $problems = implode(' ', app(StructureAcceptance::class)->problems(collect([$run]), 1, 1500));

        $this->assertStringContainsString('partial_mapping', $problems);
    }

    // ── The invocation, end to end ────────────────────────────────────────────────────────────

    /**
     * The queue is `sync` here, so the sweep runs inline and the first poll already sees it finished.
     * That is enough to prove the parts a constructed run row cannot: that the command finds the same
     * accounts the scheduler sweeps, attributes the run it created to itself, and reports the
     * measured runtime rather than a guess.
     */
    public function test_the_command_queues_watches_and_accepts_one_real_sweep(): void
    {
        $account = $this->account();
        $this->configureSnapchat();

        /*
         * Order matters, and not for a tidy reason: Snapchat's host is `adsapi.snapchat.com`, so the
         * pattern `*ads*` matches EVERY call including the campaigns one. Campaigns must be claimed
         * first or the sweep discovers no parents and skips every squad and ad — which is what this
         * fixture did on the first run, and a fair imitation of the defect it is here to guard.
         */
        Http::fake([
            '*/campaigns*' => Http::response(['campaigns' => [['campaign' => [
                'id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'ACTIVE', 'objective' => 'AWARENESS',
            ]]]], 200),
            '*/adsquads*' => Http::response(['adsquads' => [['adsquad' => [
                'id' => 'sq-1', 'campaign_id' => 'cmp-1', 'name' => 'Squad', 'status' => 'ACTIVE',
            ]]]], 200),
            '*/creatives*' => Http::response(['creatives' => [['creative' => [
                'id' => 'cr-1', 'name' => 'Creative', 'type' => 'SNAP_AD',
            ]]]], 200),
            '*/ads*' => Http::response(['ads' => [['ad' => [
                'id' => 'ad-1', 'ad_squad_id' => 'sq-1', 'name' => 'Ad', 'status' => 'ACTIVE', 'creative_id' => 'cr-1',
            ]]]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->assertSame(
            [$account->id],
            app(StructureSweepTargets::class)->accounts('snapchat')->pluck('id')->all(),
            'The acceptance check must watch exactly the accounts the scheduled sweep queues.',
        );

        $this->artisan('integrations:accept-structure', ['--provider' => 'snapchat', '--interval' => 1])
            ->expectsOutputToContain('Measured structure sweep runtime')
            ->assertSuccessful();

        $this->assertSame(
            0,
            IntegrationSyncRun::withoutGlobalScopes()
                ->where('type', 'structure')->where('status', SyncRunStatus::Running->value)->count(),
            'No structure run may be left open.',
        );
    }

    /**
     * A row already open when the check starts is a finding, not a starting condition — it is the
     * §49 symptom, and accepting on top of it would hide it.
     */
    public function test_a_run_left_open_from_before_stops_the_check_at_the_start(): void
    {
        $this->account();
        $this->structureRun(SyncRunStatus::Running, 0, finished: false);

        $this->artisan('integrations:accept-structure', ['--provider' => 'snapchat'])
            ->expectsOutputToContain('were already «running» before this started')
            ->assertFailed();
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function configureSnapchat(): void
    {
        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }
    }

    private function account(): ExternalAccount
    {
        if ($this->account !== null) {
            return $this->account;
        }

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_snap',
            'name' => 'Snap',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        return $this->account;
    }

    private function structureRun(SyncRunStatus $status, int $records = 0, ?string $error = null, bool $finished = true): IntegrationSyncRun
    {
        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider_connection_id' => $this->account()->provider_connection_id,
            'type' => 'structure',
            'status' => $status->value,
            'records' => $records,
            'error' => $error,
            'started_at' => Carbon::now()->subSeconds(430),
            'finished_at' => $finished ? Carbon::now() : null,
        ])->save();

        return $run;
    }
}
