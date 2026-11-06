<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Http\Controllers\IntegrationAuditController;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-002 (contract §1.12) — the Integrations Centre's «التدقيق» section reads the EXISTING audit
 * trail: read-only, the tenant's own rows only, paged at 10 / 25 / 50, and never a payload.
 */
final class IntegrationAuditEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $rival;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->rival = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'Owner One', 'email' => 'owner-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    public function test_it_lists_the_tenants_integration_acts_newest_first_with_the_actor_by_name_and_the_subject(): void
    {
        $connection = $this->connection($this->tenant, 'meta');
        $account = $this->account($connection, 'act_1', 'Razzah Store');

        $this->log('integration.connection.created', ProviderConnection::class, (string) $connection->id, minutesAgo: 30);
        $this->log('integration.account.unlinked', ExternalAccount::class, (string) $account->id, minutesAgo: 20);
        $this->log('metrics.sync_requested', 'external_account', (string) $account->id, minutesAgo: 10);

        $data = $this->audit()->assertOk()->json('data');

        $this->assertSame(
            ['metrics.sync_requested', 'integration.account.unlinked', 'integration.connection.created'],
            array_column($data['entries'], 'action'),
        );
        $this->assertSame(['sync', 'binding', 'authorisation'], array_column($data['entries'], 'category'));

        $sync = $data['entries'][0];
        $this->assertSame('Owner One', $sync['actor_name']);
        $this->assertSame('account', $sync['subject_kind']);
        $this->assertSame('Razzah Store', $sync['subject_name']);
        $this->assertSame('meta', $sync['provider']);
        $this->assertNotNull($sync['provider_label_ar']);

        $this->assertSame('connection', $data['entries'][2]['subject_kind']);
        $this->assertSame('meta', $data['entries'][2]['provider']);
        $this->assertSame([10, 25, 50], $data['page_sizes']);
    }

    public function test_another_tenants_rows_are_never_listed_and_its_subjects_never_resolved(): void
    {
        $theirs = $this->connection($this->rival, 'tiktok');
        $theirAccount = $this->account($theirs, 'act_rival', 'Rival Secret Account');

        // The rival's own act, on its own connection.
        $this->log('integration.connection.created', ProviderConnection::class, (string) $theirs->id, tenant: $this->rival);
        // A row of OURS that names the rival's account: it must be listed without that account's name or provider.
        $this->log('integration.account.unlinked', ExternalAccount::class, (string) $theirAccount->id);

        $data = $this->audit()->assertOk()->json('data');

        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame('integration.account.unlinked', $data['entries'][0]['action']);
        $this->assertNull($data['entries'][0]['subject_name']);
        $this->assertNull($data['entries'][0]['provider']);
        $this->assertStringNotContainsString('Rival Secret Account', json_encode($data));

        // And from the other side: the rival sees its one row and none of ours.
        $rivalOwner = $this->member($this->rival, Permission::pluck('key')->all());
        app(TenantContext::class)->setTenantId($this->rival->id);
        $theirView = $this->actingAs($rivalOwner, 'sanctum')->getJson('/api/v1/integrations/audit')->assertOk()->json('data');
        $this->assertSame(['integration.connection.created'], array_column($theirView['entries'], 'action'));
    }

    public function test_only_integration_acts_are_listed_never_the_rest_of_the_trail(): void
    {
        $this->log('security.password_changed', 'user', '1');
        $this->log('settings.branding.updated', 'tenant', (string) $this->tenant->id);
        $this->log('integration.bound', null, null);

        $data = $this->audit()->assertOk()->json('data');

        $this->assertSame(['integration.bound'], array_column($data['entries'], 'action'));
    }

    public function test_no_payload_address_or_agent_ever_leaves_the_endpoint(): void
    {
        AuditLog::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'action' => 'integration.connection.revoked',
            'entity_type' => ProviderConnection::class, 'entity_id' => (string) $this->connection($this->tenant, 'meta')->id,
            'before' => ['access_token' => 'tok-BEFORE-secret'], 'after' => ['provider' => 'meta', 'refresh_token' => 'tok-AFTER-secret'],
            'ip_address' => '203.0.113.77', 'user_agent' => 'SecretAgent/9', 'reason' => 'reason-with-secret',
        ]);

        $response = $this->audit()->assertOk();
        $body = (string) $response->getContent();

        foreach (['tok-BEFORE-secret', 'tok-AFTER-secret', '203.0.113.77', 'SecretAgent/9', 'reason-with-secret', $this->owner->email] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertSame(
            ['id', 'action', 'category', 'actor_name', 'provider', 'provider_label', 'provider_label_ar', 'subject_kind', 'subject_name', 'created_at'],
            array_keys($response->json('data.entries.0')),
        );
    }

    public function test_it_pages_at_ten_twenty_five_and_fifty_and_refuses_any_other_size(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->log('integration.bound', null, null, minutesAgo: $i);
        }

        $first = $this->audit()->assertOk()->json('data');
        $this->assertCount(10, $first['entries']);
        $this->assertSame(['total' => 27, 'per_page' => 10, 'current_page' => 1, 'last_page' => 3], $first['meta']);

        $this->assertCount(25, $this->audit('?per_page=25')->assertOk()->json('data.entries'));
        $this->assertCount(27, $this->audit('?per_page=50')->assertOk()->json('data.entries'));
        $this->assertCount(7, $this->audit('?per_page=10&page=3')->assertOk()->json('data.entries'));

        $this->audit('?per_page=500')->assertStatus(422);
        $this->audit('?per_page=11')->assertStatus(422);
    }

    public function test_a_category_narrows_the_list_and_an_unknown_one_is_refused(): void
    {
        $this->log('integration.connection.removed', null, null);
        $this->log('integration.selection.confirmed', null, null);
        $this->log('integration.discovery.refreshed', null, null);

        $this->assertSame(['integration.connection.removed'], array_column($this->audit('?category=authorisation')->json('data.entries'), 'action'));
        $this->assertSame(['integration.selection.confirmed'], array_column($this->audit('?category=binding')->json('data.entries'), 'action'));
        $this->assertSame(['integration.discovery.refreshed'], array_column($this->audit('?category=sync')->json('data.entries'), 'action'));
        $this->audit('?category=everything')->assertStatus(422);

        // Every action the endpoint lists belongs to exactly one category.
        foreach (['integration.connect', 'integration.connection.opened', 'integration.bound', 'metrics.sync_requested'] as $action) {
            $this->assertNotNull(IntegrationAuditController::categoryOf($action), $action);
        }
        $this->assertNull(IntegrationAuditController::categoryOf('security.password_changed'));
    }

    public function test_it_needs_the_integrations_view_permission(): void
    {
        // Nobody signed in, first: `actingAs` stays for the rest of a test.
        $this->getJson('/api/v1/integrations/audit')->assertUnauthorized();

        $bare = $this->member($this->tenant, ['projects.view']);
        $this->actingAs($bare, 'sanctum')->getJson('/api/v1/integrations/audit')->assertForbidden();
    }

    public function test_a_member_narrowed_to_some_projects_sees_only_their_own_acts(): void
    {
        $project = $this->project('P1');
        $narrowed = $this->member($this->tenant, ['projects.view', 'integrations.view']);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $narrowed->id,
            'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active',
        ]);

        $this->log('integration.selection.confirmed', Project::class, (string) $this->project('Another Client')->id);
        $this->log('integration.bound', Project::class, (string) $project->id, user: $narrowed);

        $data = $this->actingAs($narrowed, 'sanctum')->getJson('/api/v1/integrations/audit')->assertOk()->json('data');

        $this->assertSame(['integration.bound'], array_column($data['entries'], 'action'));
        $this->assertStringNotContainsString('Another Client', json_encode($data));
    }

    /** INTEG-PROJECT-SPACE-001 — asked for one project, the log carries nothing of another project. */
    public function test_asked_for_one_project_it_lists_only_that_projects_acts(): void
    {
        $a = $this->project('Project A');
        $b = $this->project('Project B');
        $connectionA = $this->connection($this->tenant, 'meta');
        $connectionA->forceFill(['project_id' => $a->id])->save();
        $connectionB = $this->connection($this->tenant, 'tiktok');
        $connectionB->forceFill(['project_id' => $b->id])->save();
        $accountB = $this->account($connectionB, 'act_b', 'B Secret Account');

        $this->log('integration.connection.created', ProviderConnection::class, (string) $connectionA->id, minutesAgo: 30);
        $this->log('integration.bound', Project::class, (string) $a->id, minutesAgo: 20);
        $this->log('integration.connection.created', ProviderConnection::class, (string) $connectionB->id, minutesAgo: 10);
        $this->log('integration.account.unlinked', ExternalAccount::class, (string) $accountB->id, minutesAgo: 5);
        $this->log('integration.bound', Project::class, (string) $b->id);
        // A row that names nothing belongs to no project.
        $this->log('integration.bound', null, null);

        $forA = $this->audit('?project_id='.$a->id)->assertOk()->json('data');

        $this->assertSame(['integration.bound', 'integration.connection.created'], array_column($forA['entries'], 'action'));
        $this->assertSame(2, $forA['meta']['total']);
        $this->assertStringNotContainsString('Project B', json_encode($forA));
        $this->assertStringNotContainsString('B Secret Account', json_encode($forA));
        $this->assertStringNotContainsString('tiktok', json_encode($forA));

        // Unasked, the tenant's whole log is unchanged.
        $this->assertSame(6, $this->audit()->assertOk()->json('data.meta.total'));

        // Another tenant's project, and one that does not exist, are the same 404.
        $theirs = $this->project('Rival Project', $this->rival);
        $this->audit('?project_id='.$theirs->id)->assertNotFound();
        $this->audit('?project_id=00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->audit('?project_id=not-a-uuid')->assertStatus(422);
    }

    public function test_it_is_read_only(): void
    {
        $this->log('integration.bound', null, null);
        $before = AuditLog::count();

        $this->audit()->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/audit')->assertStatus(405);
        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/v1/integrations/audit')->assertStatus(405);

        $this->assertSame($before, AuditLog::count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function audit(string $query = '')
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/integrations/audit'.$query);
    }

    private function log(string $action, ?string $entityType, ?string $entityId, int $minutesAgo = 0, ?Tenant $tenant = null, ?User $user = null): AuditLog
    {
        $log = AuditLog::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => ($user ?? $this->owner)->id,
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
        ]);
        $log->forceFill(['created_at' => Carbon::now()->subMinutes($minutesAgo)])->save();

        return $log;
    }

    /** @param  list<string>  $permissions */
    private function member(Tenant $tenant, array $permissions): User
    {
        $user = User::create(['name' => 'Member '.uniqid(), 'email' => 'm-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        return $user;
    }

    private function connection(Tenant $tenant, string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->forceFill(['tenant_id' => $tenant->id]);
        $credential->setPayload(json_encode(['access_token' => 'secret-token']));
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => ucfirst($provider), 'scope' => 'tenant_shared', 'status' => 'connected', 'scopes' => ['ads_read'],
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->id, 'provider' => $connection->provider,
            'external_id' => $externalId, 'name' => $name, 'account_type' => 'ad_account', 'status' => 'active',
        ]);
    }

    private function project(string $name, ?Tenant $tenant = null): Project
    {
        $tenant ??= $this->tenant;
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }
}
