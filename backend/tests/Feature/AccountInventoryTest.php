<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INTEG-RUNTIME §3 §5 — the inventory, and the ONE distinction it draws.
 *
 * ## What is actually being held here
 *
 * One real Snapchat authorisation returned 309 ad accounts. Every assertion below is written from
 * that number rather than from a tidy fixture of two, because every defect this file pins only
 * appears at that scale:
 *
 *  - a page that lists everything is a page nobody can read, so the counts must describe the whole
 *    while the list describes a filtered part — and the two must not disagree;
 *  - an account no project owns must not be counted, billed, synced or reported on;
 *  - a row whose provider supplied no name must not be presented as an identifier, because choosing
 *    between two UUIDs is not a hard question, it is an unanswerable one.
 *
 * ## Two states, not four
 *
 * An earlier cut of this file held a four-state curation workflow — discovered / enabled / excluded /
 * assigned — with its own column and its own endpoints. It was internal bookkeeping promoted to
 * customer-facing vocabulary: enabling an account attached nothing, synced nothing and cost nothing,
 * so «enabled» meant nothing to the person reading it. §5 removes the step. An account is linked to
 * a project or it is not, and that answer comes from `ProjectIntegrationBinding`.
 */
final class AccountInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private ClientWorkspace $workspace;

    private Project $project;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create([
            'name' => 'Owner', 'email' => 'owner-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'عميل', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => 'حملة الصيف',
            'status' => 'active',
        ]);

        $this->connection = $this->connection('snapchat');

        app(TenantContext::class)->forget();
    }

    // ── The three states are three states ─────────────────────────────────────────────────────────

    /**
     * **Linked, or not.** Two accounts, one bound to a project, and the rows say which is which.
     *
     * Structured assertions on the field of named rows — not a substring search of the response body,
     * which would pass just as happily if every row claimed to be linked.
     */
    public function test_an_account_is_linked_to_a_project_or_it_is_not(): void
    {
        $unlinked = $this->account('snap-unlinked', 'غير مرتبط');
        $linked = $this->account('snap-linked', 'مرتبط');
        $this->assign($linked);

        $byId = collect($this->inventory()->json('data.accounts'))->keyBy('id');

        $this->assertFalse($byId[$unlinked->id]['is_linked']);
        $this->assertNull($byId[$unlinked->id]['assigned_project_id']);
        $this->assertTrue(
            $byId[$linked->id]['is_linked'],
            'the product said «متصل» for both, and that one word is what let an account nobody chose '
                .'be counted as though somebody had.',
        );
    }

    /** The project is named, not merely identified — an id is not an answer to «where does this go». */
    public function test_an_assigned_row_names_the_project_that_owns_it(): void
    {
        $account = $this->account('snap-1', 'حساب');
        $this->assign($account);

        $row = $this->row($account);

        $this->assertSame($this->project->id, $row['assigned_project_id']);
        $this->assertSame('حملة الصيف', $row['assigned_project_name']);
    }

    /** Health is reported where syncing happens, and is absent — not green — where it does not. */
    public function test_an_unassigned_account_has_no_health_rather_than_a_green_tick(): void
    {
        $discovered = $this->account('snap-1', 'حساب');
        $assigned = $this->account('snap-2', 'حساب آخر');
        $this->assign($assigned);

        $rows = collect($this->inventory()->json('data.accounts'))->keyBy('id');

        $this->assertNull($rows[$discovered->id]['health']);
        $this->assertNotNull($rows[$assigned->id]['health']);
    }

    // ── The counts describe the whole ─────────────────────────────────────────────────────────────

    /**
     * «4 of 309» — the summary counts the entire inventory, and the filter only cuts the list.
     *
     * A summary computed after the filter would say «4 of 4», which is true of the page and useless
     * to the person deciding whether they have finished choosing.
     */
    public function test_the_summary_counts_the_whole_inventory_not_the_filtered_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->account('snap-u-'.$i, 'غير مرتبط '.$i);
        }
        foreach (range(1, 3) as $i) {
            $this->assign($this->account('snap-l-'.$i, 'مرتبط '.$i));
        }

        $response = $this->inventory(['link' => 'linked']);

        $this->assertCount(3, $response->json('data.accounts'), 'the LIST is the filtered part');

        $summary = $response->json('data.summary');
        $this->assertSame(3, $summary['linked']);
        $this->assertSame(30, $summary['unlinked']);
        $this->assertSame(33, $summary['total'], 'the SUMMARY is the whole');
    }

    /**
     * The link filter is applied in SQL, so a page of 25 is 25 matching rows — not 25 minus the misses.
     *
     * Filtering after pagination is the classic version of this bug: the query cuts 25 rows, PHP
     * removes the ones that do not match, and the customer pages through an inventory that appears
     * to have holes in it. It matters here because «linked» has no column — it is a join to the
     * bindings — which is exactly the shape that tempts somebody to filter in PHP.
     */
    public function test_a_filtered_page_is_full(): void
    {
        foreach (range(1, 40) as $i) {
            $this->account('snap-u-'.$i, 'غير مرتبط '.$i);
        }
        foreach (range(1, 30) as $i) {
            $this->assign($this->account('snap-l-'.$i, 'مرتبط '.$i));
        }

        $response = $this->inventory(['link' => 'linked', 'per_page' => 25]);

        $this->assertCount(25, $response->json('data.accounts'));
        $this->assertSame(30, $response->json('data.meta.total'));
        foreach ($response->json('data.accounts') as $row) {
            $this->assertTrue($row['is_linked']);
        }
    }

    // ── A name is a name ──────────────────────────────────────────────────────────────────────────

    /**
     * **The unanswerable question, pinned.** A UUID is never returned as a name.
     *
     * Connectors normalise a missing name by writing the external id into `name`, so this is not a
     * hypothetical shape — it is what several of the eight providers actually produce.
     */
    public function test_an_identifier_is_never_presented_as_a_name(): void
    {
        $uuid = '8f3ac1de-90b2-4c77-b0e1-2a4419d7c5aa';
        $account = $this->account($uuid, $uuid);

        $row = $this->row($account);

        $this->assertNotSame($uuid, $row['name'], 'COMMAND-CENTER §12: choosing between two UUIDs is not a hard question, it is an unanswerable one');
        $this->assertFalse($row['named_by_provider']);
        $this->assertSame($uuid, $row['reference'], 'the id is still there — as a reference, beside the name, never as one');
        $this->assertStringContainsString('سناب شات', $row['name'], 'the words name the provider, so one blank row is distinguishable from the next');
    }

    /** A long numeric id is an identifier too — Meta and Google both return them. */
    public function test_a_long_numeric_identifier_is_not_a_name(): void
    {
        $account = $this->account('act_1234567890123', 'act_1234567890123');

        $this->assertFalse($this->row($account)['named_by_provider']);
    }

    /** A real name is left exactly alone — including one that happens to be a short number. */
    public function test_a_real_name_is_untouched(): void
    {
        $named = $this->account('snap-1', 'متجر العطور');
        $numeric = $this->account('snap-2', '2024');

        $this->assertSame('متجر العطور', $this->row($named)['name']);
        $this->assertTrue($this->row($named)['named_by_provider']);
        $this->assertSame('2024', $this->row($numeric)['name'], 'refusing this would invent a blank the provider did not have');
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────────

    /** Another tenant's account is not one this tenant may read the history of. */
    public function test_another_tenants_account_is_a_404(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $theirConnection = $this->connection('meta', $other->id);
        $theirs = $this->account('meta-1', 'ليس لك', $theirConnection, $other->id);
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/accounts/{$theirs->id}/logs")
            ->assertStatus(404);
    }

    // ── Quota ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * COMMERCE-QUOTA-001, said on the row. A store never spends an advertising slot.
     *
     * The Connected Ad Accounts cap is sold on the six advertising platforms; a shop is not
     * advertising, and no store quota is invented here that the product does not sell.
     */
    public function test_a_store_row_says_it_costs_no_ad_account_slot(): void
    {
        $sallaConnection = $this->connection('salla');
        $store = $this->account('store-1', 'متجر العطور', $sallaConnection, null, 'store');
        $adAccount = $this->account('snap-1', 'حساب إعلاني');

        $rows = collect($this->inventory()->json('data.accounts'))->keyBy('id');

        $this->assertFalse($rows[$store->id]['counts_toward_ad_account_quota']);
        $this->assertTrue($rows[$adAccount->id]['counts_toward_ad_account_quota']);
    }

    /** Stores and ad accounts appear in ONE inventory — the customer has one set of sources. */
    public function test_stores_and_ad_accounts_are_listed_together(): void
    {
        $store = $this->account('store-1', 'متجر', $this->connection('zid'), null, 'store');
        $adAccount = $this->account('snap-1', 'حساب');

        $ids = collect($this->inventory()->json('data.accounts'))->pluck('id');

        $this->assertTrue($ids->contains($store->id));
        $this->assertTrue($ids->contains($adAccount->id));
    }

    // ── Backfill ──────────────────────────────────────────────────────────────────────────────────

    /** A backfill for an assigned account queues exactly the window that was asked for. */
    public function test_a_backfill_queues_the_requested_window(): void
    {
        Queue::fake();
        $account = $this->account('snap-1', 'حساب');
        $this->assign($account);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/backfill", ['from' => '2026-06-01', 'to' => '2026-06-30'])
            ->assertOk();

        Queue::assertPushed(
            SyncAccountMetricsJob::class,
            fn (SyncAccountMetricsJob $job): bool => $job->accountId === (string) $account->id
                && $job->from === '2026-06-01'
                && $job->to === '2026-06-30',
        );
    }

    /**
     * **Refused.** A backfill for an account no project owns has nowhere honest to land.
     *
     * This is the same fault RUNTIME-100 closed everywhere else — data that used to end up in
     * whichever project sorted first.
     */
    public function test_a_backfill_for_an_unassigned_account_is_refused(): void
    {
        Queue::fake();
        $account = $this->account('snap-1', 'حساب');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/backfill", ['from' => '2026-06-01', 'to' => '2026-06-30'])
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    /** A window longer than the cap is refused, and the boundary is exact rather than approximate. */
    public function test_the_backfill_window_boundary_is_exact(): void
    {
        Queue::fake();
        $account = $this->account('snap-1', 'حساب');
        $this->assign($account);

        // 90 days inclusive of both ends — the last window that is allowed.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/backfill", ['from' => '2026-01-01', 'to' => '2026-03-31'])
            ->assertOk();

        // 91.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/backfill", ['from' => '2026-01-01', 'to' => '2026-04-01'])
            ->assertStatus(422);
    }

    /** A window that runs backwards is refused rather than silently fetching nothing. */
    public function test_a_reversed_window_is_refused(): void
    {
        $account = $this->account('snap-1', 'حساب');
        $this->assign($account);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/backfill", ['from' => '2026-06-30', 'to' => '2026-06-01'])
            ->assertStatus(422);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────

    private function inventory(array $query = []): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/accounts?'.http_build_query($query + ['per_page' => 100]))
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function row(ExternalAccount $account): array
    {
        $row = collect($this->inventory()->json('data.accounts'))->firstWhere('id', (string) $account->id);
        $this->assertNotNull($row, 'the account was not in the inventory at all');

        return $row;
    }

    private function connection(string $provider, ?string $tenantId = null): ProviderConnection
    {
        $tenantId ??= $this->tenant->id;

        $credential = new IntegrationCredential([
            'tenant_id' => $tenantId, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.' — '.uniqid(),
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function account(
        string $externalId,
        string $name,
        ?ProviderConnection $connection = null,
        ?string $tenantId = null,
        string $type = 'ad_account',
    ): ExternalAccount {
        $connection ??= $this->connection;

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => $type,
            'external_id' => $externalId,
            'name' => $name,
            'status' => 'active',
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function assign(ExternalAccount $account): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => $account->account_type === 'store' ? 'ecommerce' : 'advertising',
            'is_active' => true,
        ]);
    }
}
