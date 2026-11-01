<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-1 — GET /notifications served every `user_id IS NULL` row of the tenant to every member,
 * including a client-portal user of another client. Those rows carry campaign names, spend figures
 * and a client's message text for ONE project / client.
 */
final class Sec1NotificationReachTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Sec Agency', 'slug' => 'sec-agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);

        $this->clientA = $this->client('Alpha');
        $this->clientB = $this->client('Bravo');
        $this->projectA = $this->project($this->clientA, 'A1');
        $this->projectB = $this->project($this->clientB, 'B1');

        $this->note('a-project', $this->projectA->id, $this->clientA->id);
        $this->note('b-project', $this->projectB->id, $this->clientB->id);
        $this->note('b-client-only', null, $this->clientB->id);
        $this->note('a-client-only', null, $this->clientA->id);
        $this->note('tenant-wide', null, null);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(ClientWorkspace $client, string $name): Project
    {
        return Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    private function note(string $title, ?string $projectId, ?string $clientId, ?int $userId = null): AppNotification
    {
        return AppNotification::create([
            'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'client_workspace_id' => $clientId,
            'user_id' => $userId, 'type' => 'alert', 'title' => $title, 'message' => 'm '.$title, 'status' => 'unread',
            'severity' => 'info', 'source' => 'alerts',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $clients
     */
    private function member(string $email, Portal $portal, array $permissions, ?array $clients = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: $portal, role: 'member', clientScopeIds: $clients,
        ));

        return $user;
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function titles(User $user, string $query = ''): array
    {
        $json = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications'.$query)->assertOk()->json();
        $titles = array_column($json['data'], 'title');
        sort($titles);

        return [$titles, $json['meta']];
    }

    public function test_a_client_portal_user_never_receives_team_wide_rows_but_keeps_their_own(): void
    {
        $portalUser = $this->member('portal-b@sec.dev', Portal::ClientPortal, ['projects.view'], [(string) $this->clientB->id]);
        $this->note('addressed-to-b-user', $this->projectB->id, $this->clientB->id, $portalUser->id);

        [$titles, $meta] = $this->titles($portalUser);
        $this->assertSame(['addressed-to-b-user'], $titles);
        $this->assertSame(1, $meta['unread']);
        $this->assertSame(1, $meta['total']);

        // The project_id filter cannot be used to pull another project's team rows either.
        [$filtered] = $this->titles($portalUser, '?project_id='.$this->projectA->id);
        $this->assertSame([], $filtered);
        [$filteredB] = $this->titles($portalUser, '?project_id='.$this->projectB->id);
        $this->assertSame(['addressed-to-b-user'], $filteredB);
    }

    public function test_a_client_scoped_agency_manager_receives_only_their_clients_team_rows(): void
    {
        $manager = $this->member('mgr@sec.dev', Portal::Agency, ['projects.view', 'projects.view.all'], [(string) $this->clientA->id]);

        [$titles, $meta] = $this->titles($manager);
        $this->assertSame(['a-client-only', 'a-project'], $titles);
        $this->assertSame(2, $meta['total']);
        $this->assertSame(2, $meta['unread']);

        [$other] = $this->titles($manager, '?project_id='.$this->projectB->id);
        $this->assertSame([], $other);
    }

    public function test_a_project_member_without_view_all_receives_only_rows_of_a_current_project_membership(): void
    {
        $buyer = $this->member('buyer@sec.dev', Portal::Agency, ['projects.view']);
        ProjectMembership::create(['project_id' => $this->projectA->id, 'user_id' => $buyer->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);
        ProjectMembership::create(['project_id' => $this->projectB->id, 'user_id' => $buyer->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active', 'expires_at' => now()->subDay()]);

        [$titles] = $this->titles($buyer);
        $this->assertSame(['a-project'], $titles);
    }

    public function test_an_unrestricted_agency_reader_still_receives_every_team_wide_row(): void
    {
        $owner = $this->member('owner@sec.dev', Portal::Agency, ['projects.view', 'projects.view.all', ClientScopeResolver::ALL_CLIENTS]);

        [$titles, $meta] = $this->titles($owner);
        $this->assertSame(['a-client-only', 'a-project', 'b-client-only', 'b-project', 'tenant-wide'], $titles);
        $this->assertSame(5, $meta['total']);
    }

    public function test_the_delivery_log_lists_only_the_readers_own_notifications(): void
    {
        $portalUser = $this->member('portal-d@sec.dev', Portal::ClientPortal, ['projects.view'], [(string) $this->clientB->id]);
        $teamRow = AppNotification::query()->where('title', 'b-project')->firstOrFail();
        \DB::table('notification_deliveries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'notification_id' => $teamRow->id,
            'channel' => 'in_app', 'status' => 'sent', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->actingAs($portalUser, 'sanctum')->getJson('/api/v1/notifications/deliveries')->assertOk()->json('data');
        $this->assertSame([], $rows);
    }
}
