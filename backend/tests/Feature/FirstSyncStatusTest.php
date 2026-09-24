<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * INTEGRATION-FIRST-SYNC-VISIBILITY-001 — the answer the wizard waits for, about the WHOLE selection.
 *
 * ## The owner's report
 *
 * «After confirming, the UI appears as if nothing happened. Only after manually refreshing the page
 * do I discover that the sync actually completed.» Nothing was broken underneath: the run finished,
 * the rows landed, the binding was live. There was simply no question the browser could ask whose
 * answer changed when the work finished — the dialog watched ONE account's log, and the rest of the
 * product watched nothing at all.
 *
 * ## What this endpoint has to get right
 *
 * Three things, and each one is a way the old single-account panel lied:
 *
 *  1. **It is about the selection.** Five accounts confirmed together are one decision; reporting
 *     the first one's run as «the» outcome describes a stranger to four fifths of the people who
 *     read it.
 *  2. **`settled` is the only success signal.** A selection with one account still queued is not
 *     finished however good the others look. The caller refreshes the rest of the product on this
 *     flag, so a premature `true` is the defect re-armed: a card refreshed at the wrong moment shows
 *     the pre-sync world and stays there.
 *  3. **History is not this confirmation.** An account already bound to another project carries runs
 *     from yesterday, and yesterday's success is not this button's result.
 *
 * Plus the vocabulary the browser's own rules never had: `partial_mapping` and `awaiting_assignment`
 * are attention states, and collapsing them into imported/no_data by row count is how an unplaced
 * campaign reached a customer wearing a green tick.
 */
final class FirstSyncStatusTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ProviderConnection $connection;

    /** @var list<ExternalAccount> */
    private array $accounts = [];

    private Carbon $confirmedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->connection = $this->connection('snapchat');
        $this->accounts = [
            $this->account('act-1', 'Riyadh Retail'),
            $this->account('act-2', 'Jeddah Retail'),
            $this->account('act-3', 'Dammam Retail'),
        ];

        $this->confirmedAt = Carbon::now();
    }

    /**
     * **The defect, pinned.** One account has answered and two have not, and the selection is not
     * settled — which is the flag the dialog refreshes the product on.
     */
    public function test_a_selection_with_one_account_still_queued_is_not_settled(): void
    {
        $this->recordRun($this->accounts[0], 'success', rows: 936);

        $data = $this->readStatus();

        $this->assertFalse($data['summary']['settled'], 'two accounts had not run and the answer claimed to be final');
        $this->assertSame('queued', $data['summary']['state']);
        $this->assertSame(2, $data['summary']['queued']);
        $this->assertSame(1, $data['summary']['imported']);
        $this->assertSame('imported', $data['accounts'][0]['state']);
        $this->assertSame('queued', $data['accounts'][1]['state']);
    }

    /** Settled, and the rows are the selection's rather than the first account's. */
    public function test_a_settled_selection_totals_the_rows_every_account_imported(): void
    {
        $this->recordRun($this->accounts[0], 'success', rows: 936);
        $this->recordRun($this->accounts[1], 'success', rows: 64);
        $this->recordRun($this->accounts[2], 'no_data', rows: 0);

        $data = $this->readStatus();

        $this->assertTrue($data['summary']['settled']);
        $this->assertSame('imported', $data['summary']['state']);
        $this->assertSame(1000, $data['summary']['rows'], 'the total described one account, not the selection');
        $this->assertSame(3, $data['summary']['succeeded']);
        $this->assertSame(0, $data['summary']['needs_attention']);
    }

    /**
     * A refusal among successes is `partial`, never `imported`.
     *
     * The single-account panel reported whichever account happened to be first, so the same
     * confirmation read as a clean success or as a failure depending on selection order.
     */
    public function test_one_refusal_among_successes_is_reported_as_partial_with_the_provider_words(): void
    {
        $this->recordRun($this->accounts[0], 'success', rows: 936);
        $this->recordRun($this->accounts[1], 'failed', rows: 0, error: '(#200) Ad account owner has NOT grant ads_read permission');
        $this->recordRun($this->accounts[2], 'success', rows: 12);

        $data = $this->readStatus();

        $this->assertTrue($data['summary']['settled']);
        $this->assertSame('partial', $data['summary']['state']);
        $this->assertSame(1, $data['summary']['failed']);
        $this->assertSame(1, $data['summary']['needs_attention']);
        $this->assertStringContainsString('ads_read', (string) $data['accounts'][1]['error']);
    }

    /** Every one refused is a different sentence from «one of three refused». */
    public function test_a_selection_where_every_account_was_refused_is_failed(): void
    {
        foreach ($this->accounts as $account) {
            $this->recordRun($account, 'failed', rows: 0, error: 'token expired');
        }

        $this->assertSame('failed', $this->readStatus()['summary']['state']);
    }

    /**
     * `partial_mapping` and `awaiting_assignment` are attention states, not successes.
     *
     * The browser's rules had neither word, so a run that placed rows it could not attach to a known
     * campaign arrived as «اكتملت» with a row count beside it.
     */
    public function test_rows_that_could_not_be_placed_are_not_reported_as_a_clean_import(): void
    {
        $this->recordRun($this->accounts[0], 'partial_mapping', rows: 500);
        $this->recordRun($this->accounts[1], 'awaiting_assignment', rows: 0);
        $this->recordRun($this->accounts[2], 'success', rows: 10);

        $data = $this->readStatus();

        $this->assertSame('partial', $data['summary']['state']);
        $this->assertSame('partial', $data['accounts'][0]['state']);
        $this->assertSame('awaiting_assignment', $data['accounts'][1]['state']);
        $this->assertSame(2, $data['summary']['needs_attention']);
    }

    /**
     * Yesterday's success is not this button's outcome.
     *
     * An account bound to a second project already has history, and a status that reads it reports a
     * finished sync the instant somebody presses confirm — the most convincing possible version of
     * the defect, because the dialog would then refresh the product before anything had run.
     */
    public function test_runs_that_started_before_the_confirmation_are_not_this_confirmation(): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'external_account_id' => $this->accounts[0]->id,
            'provider' => 'snapchat', 'status' => 'success', 'metrics_upserted' => 4212,
            'window_start' => now()->subDays(7)->toDateString(), 'window_end' => now()->toDateString(),
            'started_at' => $this->confirmedAt->copy()->subHours(6),
            'finished_at' => $this->confirmedAt->copy()->subHours(6)->addMinute(),
        ]);

        $data = $this->readStatus();

        $this->assertSame('queued', $data['accounts'][0]['state'], 'history was read as this confirmation');
        $this->assertSame(0, $data['summary']['rows']);
        $this->assertFalse($data['summary']['settled']);
    }

    /** A success that imported nothing is `no_data` — never a green «0 imported». */
    public function test_a_success_that_imported_nothing_reads_as_no_data(): void
    {
        foreach ($this->accounts as $account) {
            $this->recordRun($account, 'success', rows: 0);
        }

        $data = $this->readStatus();

        $this->assertSame('no_data', $data['summary']['state']);
        $this->assertSame(0, $data['summary']['imported']);
        $this->assertSame(3, $data['summary']['no_data']);
    }

    /**
     * An account from someone else's connection is refused, not silently dropped.
     *
     * A status answered about a smaller set than was asked for is worse than no status: it reads as
     * final while a real member of the selection was never looked at.
     */
    public function test_an_account_outside_this_connection_is_refused(): void
    {
        $other = $this->connection('meta');
        $stranger = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $other->id,
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'meta-1', 'name' => 'Elsewhere',
            'status' => 'active', 'discovered_at' => now(),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url([$this->accounts[0]->id, $stranger->id]))
            ->assertNotFound();
    }

    /** Another tenant's connection is not readable at all. */
    public function test_another_tenants_connection_is_not_found(): void
    {
        $intruder = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        $theirs = User::create(['name' => 'X', 'email' => 'x@ot.test', 'password' => 'secret123']);
        $this->grantMembership($theirs, $intruder);
        $role = Role::create(['tenant_id' => $intruder->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $theirs->assignRole($role);

        app(TenantContext::class)->setTenantId($intruder->id);

        $this->actingAs($theirs, 'sanctum')
            ->getJson($this->url([$this->accounts[0]->id]))
            ->assertNotFound();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $ids */
    private function url(array $ids): string
    {
        $query = http_build_query([
            'accounts' => $ids,
            'since' => $this->confirmedAt->toIso8601String(),
        ]);

        return "/api/v1/connections/{$this->connection->id}/first-sync?{$query}";
    }

    /** @return array<string,mixed> */
    private function readStatus(): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url(array_map(static fn (ExternalAccount $a): string => (string) $a->id, $this->accounts)))
            ->assertOk()
            ->json('data');
    }

    private function recordRun(ExternalAccount $account, string $status, int $rows, ?string $error = null): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'status' => $status,
            'metrics_upserted' => $rows,
            'error' => $error,
            'window_start' => now()->subDays(7)->toDateString(),
            'window_end' => now()->toDateString(),
            // After the confirmation, which is what marks a run as this one's rather than history.
            'started_at' => $this->confirmedAt->copy()->addSeconds(5),
            'finished_at' => $status === 'running' ? null : $this->confirmedAt->copy()->addSeconds(30),
        ]);
    }

    private function account(string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $name,
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
            'discovered_at' => now(),
            'last_synced_at' => null,
        ]);
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }
}
