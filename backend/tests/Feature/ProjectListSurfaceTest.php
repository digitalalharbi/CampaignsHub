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
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT-LIST-SURFACE-001 §10 §32 — a list of clients that answers the questions asked of a list.
 *
 * ## The card before this
 *
 * Name, client, status, «الإعداد 40%». How many ad accounts, which platforms, when data last
 * arrived and whether anything needs doing all required opening the project. A list that cannot
 * answer those is a menu, and the rail was already the menu.
 *
 * ## What the tests are actually protecting
 *
 * Not «the numbers appear» — that a summary is a per-project statement and stays one. Every figure
 * here has a neighbouring project set up to be counted by mistake: a binding belonging to the other
 * client, an account the other client syncs hourly, a membership on the other project. The one that
 * matters most is freshness, because it is read through a join and a join is where a scope is lost.
 *
 * `needs attention` is three named states and no score. A badge meaning «something is worse than it
 * was» is a badge people stop seeing; `no_accounts`, `never_synced` and `stale` each name a thing to
 * go and do.
 *
 * ## And §32 — clone copies configuration, never a client's data
 *
 * The controller has always said bindings are not copied. Nothing asserted it, and clone is exactly
 * the operation where an incautious `replicate()` quietly hands one client's ad accounts, history
 * and links to another project.
 */
final class ProjectListSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $workspace;

    private ProviderConnection $snapchat;

    private Project $mine;

    private Project $neighbour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'cl-'.uniqid(), 'mode' => 'managed']);

        $this->mine = $this->project('رزة أفينيو');
        $this->neighbour = $this->project('عميل آخر');

        $this->snapchat = $this->connection('snapchat');
    }

    /** The four facts, each about this project and not the one beside it. */
    public function test_a_card_carries_its_own_accounts_platforms_freshness_and_team(): void
    {
        $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 3));
        $this->bind($this->mine, $this->account($this->connection('meta'), 'act-2', syncedHoursAgo: 10));

        // The neighbour is busier and more current — and must not appear in the other card.
        $this->bind($this->neighbour, $this->account($this->snapchat, 'act-3', syncedHoursAgo: 0));
        $this->bind($this->neighbour, $this->account($this->snapchat, 'act-4', syncedHoursAgo: 0));

        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->mine->id,
            'user_id' => $this->owner->id, 'role' => 'manager', 'status' => 'active',
        ]);

        $cards = $this->cards();

        $this->assertSame(2, $cards['رزة أفينيو']['summary']['accounts']);
        $this->assertSame(['meta', 'snapchat'], $cards['رزة أفينيو']['summary']['providers']);
        $this->assertSame(1, $cards['رزة أفينيو']['summary']['team_members']);
        $this->assertNull($cards['رزة أفينيو']['summary']['attention']);

        $this->assertSame(2, $cards['عميل آخر']['summary']['accounts']);
        $this->assertSame(['snapchat'], $cards['عميل آخر']['summary']['providers']);
        $this->assertSame(0, $cards['عميل آخر']['summary']['team_members']);
    }

    /**
     * Freshness is the newest among the accounts THIS project has bound.
     *
     * A neighbour syncing hourly would otherwise make a dormant client look current — the account
     * scope invariant, applied to a date, through the join where a scope is easiest to drop.
     */
    public function test_freshness_is_read_only_from_this_projects_bound_accounts(): void
    {
        $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 30));
        $this->bind($this->neighbour, $this->account($this->snapchat, 'act-2', syncedHoursAgo: 0));

        $mine = $this->cards()['رزة أفينيو']['summary'];

        $this->assertNotNull($mine['data_last_synced_at']);
        $this->assertTrue(
            now()->subHours(31)->lt($mine['data_last_synced_at'])
            && now()->subHours(29)->gt($mine['data_last_synced_at']),
            'the card took its freshness from another project’s account',
        );
    }

    /** A deselected account is not this project's any more, and stops counting at once. */
    public function test_a_deactivated_binding_stops_counting(): void
    {
        $binding = $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 1));

        $this->assertSame(1, $this->cards()['رزة أفينيو']['summary']['accounts']);

        $binding->forceFill(['is_active' => false])->save();

        $after = $this->cards()['رزة أفينيو']['summary'];
        $this->assertSame(0, $after['accounts']);
        $this->assertSame([], $after['providers']);
        $this->assertNull($after['data_last_synced_at']);
        $this->assertSame('no_accounts', $after['attention']);
    }

    /** Bound and never heard from is a different problem from bound and quiet lately. */
    public function test_the_three_attention_states_are_told_apart(): void
    {
        $this->assertSame('no_accounts', $this->cards()['رزة أفينيو']['summary']['attention']);

        $account = $this->account($this->snapchat, 'act-1', syncedHoursAgo: null);
        $this->bind($this->mine, $account);
        $this->assertSame('never_synced', $this->cards()['رزة أفينيو']['summary']['attention']);

        $account->forceFill(['last_synced_at' => now()->subHours(72)])->save();
        $this->assertSame('stale', $this->cards()['رزة أفينيو']['summary']['attention']);

        $account->forceFill(['last_synced_at' => now()->subHour()])->save();
        $this->assertNull($this->cards()['رزة أفينيو']['summary']['attention']);
    }

    /** The summary is a LISTING fact; the single-project read is unchanged. */
    public function test_the_single_project_read_does_not_carry_a_summary(): void
    {
        $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 1));

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->mine->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('summary', $body);
    }

    // ── §32 — clone copies configuration, never a client's data ───────────────────────────────

    /**
     * **The audit, pinned.** A clone starts empty: no accounts, no history, no links.
     */
    public function test_cloning_a_project_copies_no_bindings_reports_or_history(): void
    {
        $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 1));
        Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->mine->id,
            'name' => 'تقرير', 'type' => 'performance', 'form' => 'detailed', 'audience' => 'client',
            'mode' => 'snapshot', 'status' => 'completed',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'currency' => 'SAR', 'data' => ['sections' => []], 'generated_at' => now(),
        ]);

        $copyId = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->mine->id}/clone")
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $copyId)->count(),
            'the clone inherited a client’s ad accounts',
        );
        $this->assertSame(
            0,
            Report::withoutGlobalScopes()->where('project_id', $copyId)->count(),
            'the clone inherited a client’s reports',
        );

        // And the original keeps everything it had.
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->mine->id)->count());
    }

    /** A freshly cloned project reads as what it is: connected to nothing yet. */
    public function test_a_clone_reads_as_a_project_with_no_accounts(): void
    {
        $this->bind($this->mine, $this->account($this->snapchat, 'act-1', syncedHoursAgo: 1));

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->mine->id}/clone")
            ->assertCreated();

        $clone = collect($this->list())->first(fn (array $row): bool => $row['id'] !== (string) $this->mine->id
            && $row['id'] !== (string) $this->neighbour->id);

        $this->assertNotNull($clone);
        $this->assertSame(0, $clone['summary']['accounts']);
        $this->assertSame('no_accounts', $clone['summary']['attention']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function list(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->json('data');
    }

    /** @return array<string,array<string,mixed>> keyed by project name */
    private function cards(): array
    {
        return collect($this->list())->keyBy('name')->all();
    }

    private function project(string $name): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
            'status' => 'active',
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

    private function account(ProviderConnection $connection, string $externalId, ?int $syncedHoursAgo): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => now(),
            'last_synced_at' => $syncedHoursAgo === null ? null : now()->subHours($syncedHoursAgo),
        ]);
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'reporting',
            'is_active' => true,
        ]);
    }
}
