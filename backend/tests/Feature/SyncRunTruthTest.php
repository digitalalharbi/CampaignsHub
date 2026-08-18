<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Metrics\Services\InsightPayloadRows;
use App\Domains\Metrics\Services\SyncRunLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-RUNTIME §7 §8 — the four counts, and the six words that are no longer two words doing five
 * jobs.
 *
 * ## The failure this is written against
 *
 * A live Snapchat connection reached 309 real ad accounts and produced zero metrics. The run said
 * `partial` and `metrics_upserted = 0`, and that sentence is equally true of «the provider had no
 * rows» and «the provider sent rows we could not place» — an ordinary weekend and a mapping defect,
 * under one word, in one colour. Nobody could tell them apart from the record, which is precisely why
 * the zero survived long enough to be reported as a mystery.
 *
 * Every assertion below is structured — a status compared to an enum case, a count compared to an
 * integer. None of them looks for a substring in a message, because a message is prose and prose is
 * not a contract.
 */
final class SyncRunTruthTest extends TestCase
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

    // ── The words ─────────────────────────────────────────────────────────────────────────────

    /**
     * Rows arrived, every one of them named a campaign nobody has discovered → `partial_mapping`.
     *
     * This is the shape of the Snapchat failure, reproduced without a provider: an account that was
     * assigned and synced before its structure landed. The old pipeline called this `partial` and
     * stamped the account as healthy; it is now named, categorised and counted.
     */
    public function test_rows_that_match_no_campaign_are_partial_mapping_not_a_quiet_partial(): void
    {
        $account = $this->assignedAccount();

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'));

        $this->assertSame(SyncRunStatus::PartialMapping->value, $run->status);
        $this->assertSame(0, (int) $run->metrics_upserted);
        $this->assertGreaterThan(0, (int) $run->parsed_rows, 'the connector produced rows');
        $this->assertSame(0, (int) $run->mapped_campaign_rows, 'and not one of them could be placed');

        $account->refresh();
        $this->assertSame('unmapped_rows', $account->last_sync_error_category);
    }

    /** Structure first, then metrics — the product's own order — and the run is a real success. */
    public function test_a_sync_after_discovery_succeeds_and_counts_every_stage(): void
    {
        $account = $this->assignedAccount();

        app(AccountStructureSyncer::class)->sync($account);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'));

        $this->assertSame(SyncRunStatus::Success->value, $run->status);
        $this->assertGreaterThan(0, (int) $run->metrics_upserted);
        $this->assertSame(
            (int) $run->parsed_rows,
            (int) $run->mapped_campaign_rows,
            'a success means every row the connector produced was placed',
        );
        $this->assertNull($run->error);

        $account->refresh();
        $this->assertNull($account->last_sync_error_category);
    }

    /** An account nobody assigned is `awaiting_assignment`, and it measured nothing. */
    public function test_an_unassigned_account_is_awaiting_assignment_and_counts_nothing(): void
    {
        $account = $this->discoveredAccount();

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'));

        $this->assertSame(SyncRunStatus::AwaitingAssignment->value, $run->status);
        $this->assertNull($run->project_id);

        // NOT zero. Nothing was measured, and a 0 would be a count nobody took.
        $this->assertNull($run->provider_raw_rows);
        $this->assertNull($run->parsed_rows);
        $this->assertNull($run->mapped_campaign_rows);
    }

    /**
     * A platform with no credentials is `failed`, and its category still says which failure it is.
     *
     * §8 gives the sync six words and «awaiting credentials» is not one of them. The distinction it
     * carried — an operator adds keys, versus a platform had a bad minute — survives as the category,
     * which is what actually decides who acts.
     */
    public function test_a_platform_without_credentials_fails_and_keeps_its_category(): void
    {
        $account = $this->assignedAccount(provider: 'snapchat', externalId: 'snap-1');

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'));

        $this->assertSame(SyncRunStatus::Failed->value, $run->status);

        $account->refresh();
        $this->assertSame('awaiting_credentials', $account->last_sync_error_category);
        $this->assertNull($account->last_synced_at, 'a refusal is not a sync');
    }

    /**
     * `no_data` is a reach, not a failure — proved against a real platform that answered with nothing.
     *
     * Snapchat rather than the sandbox, because the sandbox always invents two rows and can never
     * produce this outcome. Here the connector genuinely makes the request and the platform genuinely
     * returns an empty `timeseries_stats`, which is what a paused account looks like.
     */
    public function test_no_data_moves_the_checkpoint_and_asks_for_no_attention(): void
    {
        $this->configureSnapchat();
        $account = $this->assignedAccount(provider: 'snapchat', externalId: 'snap-1');

        Http::fake(['adsapi.snapchat.com/*' => Http::response(['timeseries_stats' => []])]);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'));

        $this->assertSame(SyncRunStatus::NoData->value, $run->status);
        $this->assertSame(0, (int) $run->provider_raw_rows);
        $this->assertSame(0, (int) $run->parsed_rows);
        $this->assertNull($run->error, 'nothing went wrong, so there is nothing to report');

        $account->refresh();
        $this->assertNotNull($account->last_synced_at, 'we asked and they answered — that is a reach');
        $this->assertNull($account->last_sync_error_category);
    }

    /**
     * The platform sent rows and none could be placed — and the record now says BOTH halves.
     *
     * This is the Snapchat production shape end to end: a real request, a real body with real day
     * points, an account whose campaigns have not been discovered. `provider_raw_rows` proves the
     * platform answered; `mapped_campaign_rows = 0` proves where it stopped. Before the counters,
     * this and the test above were the same row in the database.
     */
    public function test_rows_arrived_and_none_were_placed_is_told_apart_from_no_rows_at_all(): void
    {
        $this->configureSnapchat();
        $account = $this->assignedAccount(provider: 'snapchat', externalId: 'snap-1');

        Http::fake(['adsapi.snapchat.com/*' => Http::response([
            'timeseries_stats' => [[
                'timeseries_stat' => [
                    'id' => 'cmp-never-discovered',
                    'timeseries' => [
                        ['start_time' => '2026-08-01T00:00:00.000+03:00', 'stats' => ['spend' => 1_000_000, 'impressions' => 10]],
                        ['start_time' => '2026-08-02T00:00:00.000+03:00', 'stats' => ['spend' => 2_000_000, 'impressions' => 20]],
                    ],
                ],
            ]],
        ])]);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $this->assertSame(SyncRunStatus::PartialMapping->value, $run->status);
        $this->assertGreaterThan(0, (int) $run->provider_raw_rows, 'Snapchat answered with day points');
        $this->assertSame((int) $run->provider_raw_rows, (int) $run->parsed_rows, 'and the parser kept every one');
        $this->assertSame(0, (int) $run->mapped_campaign_rows, 'and not one named a campaign we know');
        $this->assertSame(0, (int) $run->metrics_upserted);
    }

    // ── The log ───────────────────────────────────────────────────────────────────────────────

    /** A run states its cause, its duration and all four counts in the one shape every log renders. */
    public function test_a_run_states_its_trigger_duration_and_counts(): void
    {
        $account = $this->assignedAccount();
        app(AccountStructureSyncer::class)->sync($account);

        $run = app(AccountMetricsSyncer::class)->sync(
            $account,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-03'),
            ['manual' => true],
        );

        $row = $run->logRow('Sandbox account', 'act-1');

        $this->assertSame('manual', $row['trigger']);
        $this->assertSame(SyncRunStatus::Success->value, $row['status']);
        $this->assertSame('Sandbox account', $row['account']);
        $this->assertIsInt($row['metrics_imported']);
        $this->assertIsInt($row['duration_seconds']);
        $this->assertArrayHasKey('provider_rows', $row);
        $this->assertArrayHasKey('parsed_rows', $row);
        $this->assertArrayHasKey('mapped_rows', $row);
    }

    /** The three causes are told apart, because the customer's first question is which one it was. */
    public function test_the_trigger_is_read_from_what_asked_for_the_run(): void
    {
        $run = new MetricSyncRun;

        $run->meta = ['backfill' => true, 'manual' => true];
        $this->assertSame('backfill', $run->trigger(), 'a backfill is a backfill even when a human asked');

        $run->meta = ['triggered_by' => 'user-1'];
        $this->assertSame('manual', $run->trigger());

        $run->meta = ['source' => 'assignment', 'first_sync' => true];
        $this->assertSame('automatic', $run->trigger());

        $run->meta = null;
        $this->assertSame('automatic', $run->trigger());
    }

    // ── The log says the same answer once ─────────────────────────────────────────────────────

    /**
     * §8 — «لا تكرر نفس الخطأ كل ٣٠ دقيقة في واجهة مزعجة».
     *
     * The sweep runs every half hour, so an account the platform has nothing to report for produces
     * forty-eight indistinguishable rows a day. Every one is still recorded; the LOG says it once.
     */
    public function test_consecutive_identical_runs_are_said_once_with_a_count(): void
    {
        $rows = [
            $this->logRow('no_data', '2026-08-18T06:30:00+00:00'),
            $this->logRow('no_data', '2026-08-18T06:00:00+00:00'),
            $this->logRow('no_data', '2026-08-18T05:30:00+00:00'),
        ];

        $collapsed = SyncRunLog::collapse($rows);

        $this->assertCount(1, $collapsed);
        $this->assertSame(3, $collapsed[0]['repeats']);
        // The rows arrive newest first, so the streak's marker is its OLDEST attempt, not its newest.
        $this->assertSame('2026-08-18T05:30:00+00:00', $collapsed[0]['repeats_since']);
    }

    /** Anything that CHANGED starts a new row — that is the moment a reader needs to notice. */
    public function test_a_change_of_any_kind_breaks_the_streak(): void
    {
        $collapsed = SyncRunLog::collapse([
            $this->logRow('success', '2026-08-18T06:30:00+00:00', metrics: 12),
            $this->logRow('no_data', '2026-08-18T06:00:00+00:00'),
            $this->logRow('no_data', '2026-08-18T05:30:00+00:00'),
            $this->logRow('failed', '2026-08-18T05:00:00+00:00'),
        ]);

        $this->assertCount(3, $collapsed);
        $this->assertSame(['success', 'no_data', 'failed'], array_column($collapsed, 'status'));
        $this->assertSame([1, 2, 1], array_column($collapsed, 'repeats'));
    }

    /**
     * A run that STORED something is never folded into one that did not.
     *
     * «Nothing happened, forty-eight times» and «nothing happened forty-seven times and then data
     * arrived» are different days, and the second is the one being waited for.
     */
    public function test_a_run_that_stored_metrics_is_never_folded_into_one_that_did_not(): void
    {
        $collapsed = SyncRunLog::collapse([
            $this->logRow('success', '2026-08-18T06:30:00+00:00', metrics: 40),
            $this->logRow('success', '2026-08-18T06:00:00+00:00', metrics: 0),
        ]);

        $this->assertCount(2, $collapsed);
    }

    // ── Reading a past run back out of the provider's own body ────────────────────────────────

    /** Snapchat's shape, counted: points across every series, and the campaign ids they name. */
    public function test_a_kept_snapchat_body_yields_its_row_count_and_campaign_ids(): void
    {
        $read = InsightPayloadRows::of('snapchat', [
            'timeseries_stats' => [
                ['timeseries_stat' => ['id' => 'c-1', 'timeseries' => [['stats' => []], ['stats' => []]]]],
                ['timeseries_stat' => ['id' => 'c-2', 'timeseries' => [['stats' => []]]]],
            ],
        ]);

        $this->assertNotNull($read);
        $this->assertSame(3, $read['rows']);
        $this->assertSame(['c-1', 'c-2'], $read['campaign_ids']);
    }

    /** A shape this reader does not know returns NULL — never a plausible zero. */
    public function test_an_unreadable_body_says_so_rather_than_reporting_zero(): void
    {
        $this->assertNull(InsightPayloadRows::of('mystery_platform', ['data' => [1, 2, 3]]));
        $this->assertNull(InsightPayloadRows::of('snapchat', ['something_else' => []]));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** Give Snapchat everything it `requires()`, so the connector calls out instead of refusing. */
    private function configureSnapchat(): void
    {
        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }
    }

    /**
     * One log row, in the shape `MetricSyncRun::logRow()` produces.
     *
     * Built by hand rather than from saved runs because what is under test is the COLLAPSE — its
     * identity rule and its ordering — and a fixture of forty-eight real runs would test the sweep.
     *
     * @return array<string,mixed>
     */
    private function logRow(string $status, string $startedAt, int $metrics = 0): array
    {
        return [
            'id' => $status.$startedAt,
            'provider' => 'snapchat',
            'status' => $status,
            'trigger' => 'automatic',
            'window_start' => '2026-08-11',
            'window_end' => '2026-08-18',
            'metrics_imported' => $metrics,
            'error' => $status === 'failed' ? 'boom' : null,
            'started_at' => $startedAt,
        ];
    }

    private function assignedAccount(string $provider = 'sandbox', string $externalId = 'act-1'): ExternalAccount
    {
        $account = $this->discoveredAccount($provider, $externalId);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }

    private function discoveredAccount(string $provider = 'sandbox', string $externalId = 'act-1'): ExternalAccount
    {
        /*
         * A real provider gets REAL tokens in the vault, not a bare credential row.
         *
         * `ApiAdvertisingConnector::tokens()` reads the vault, so a connection assembled by hand has
         * no access token and every provider sync fails before it reaches the wire — which would make
         * this file assert on refusals while claiming to assert on answers. The sandbox has no
         * platform configuration at all (`TokenVault` refuses it, correctly), so it keeps the plain
         * credential row it has always used.
         */
        $connection = $provider === 'sandbox'
            ? $this->sandboxConnection()
            : app(TokenVault::class)->open(
                tenantId: $this->tenant->id,
                provider: $provider,
                tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
                connectionName: $provider,
            );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function sandboxConnection(): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => 'sandbox', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'sandbox',
            'connection_name' => 'sandbox',
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }
}
