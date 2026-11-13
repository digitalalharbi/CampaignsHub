<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\ClientRequestManagerNotifier;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLIENT-REQUEST-PM-NOTICE-001 — a client's request reaches THAT client's project manager: the bell always, the
 * inbox as their own settings say; the owner when no manager is left; nobody from another workspace or client.
 */
final class ClientRequestManagerNoticeTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private User $pm;

    private User $otherPm;

    private ClientWorkspace $client;

    private ClientWorkspace $otherClient;

    private Project $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency, 'owner');
        $this->pm = User::create(['name' => 'PM', 'email' => 'pm@a.test', 'password' => 'secret123']);
        $this->otherPm = User::create(['name' => 'Other PM', 'email' => 'pm2@a.test', 'password' => 'secret123']);

        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active']);
        $this->otherClient = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Henka', 'slug' => 'henka', 'mode' => 'managed', 'status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Alpha', 'status' => 'active', 'account_manager_id' => $this->pm->id]);
        Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->otherClient->id, 'name' => 'Foreign', 'status' => 'active', 'account_manager_id' => $this->otherPm->id]);
        // Each manager's membership names the client they follow — what a «مدير مشروع» is given.
        $this->grantMembership($this->pm, $this->tenant, Portal::Agency, 'member', [(string) $this->client->id]);
        $this->grantMembership($this->otherPm, $this->tenant, Portal::Agency, 'member', [(string) $this->otherClient->id]);
    }

    private function clientUser(): User
    {
        $user = User::create(['name' => 'Client', 'email' => 'c@client.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->client->id], projectScopeIds: null, grantedBy: $this->owner, enrolNotifications: false,
        ));

        return $user;
    }

    private function submit(?string $projectId = null): string
    {
        $this->actingAs($this->clientUser());
        $body = ['type' => 'consulting', 'message' => 'Please review our June plan.'] + ($projectId !== null ? ['project_id' => $projectId] : []);

        return (string) $this->postJson('/api/v1/client/requests', $body, $this->spa)->assertCreated()->json('data.reference');
    }

    /** @return list<int> */
    private function notifiedUsers(): array
    {
        return AppNotification::withoutGlobalScopes()->where('type', ClientRequestManagerNotifier::TYPE)
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    public function test_the_clients_project_manager_is_told_and_nobody_else(): void
    {
        $this->submit((string) $this->a->id);

        $this->assertSame([$this->pm->id], $this->notifiedUsers());
        $n = AppNotification::withoutGlobalScopes()->where('type', ClientRequestManagerNotifier::TYPE)->firstOrFail();
        $this->assertSame((string) $this->tenant->id, (string) $n->tenant_id);
        $this->assertSame((string) $this->client->id, (string) $n->client_workspace_id);
        $this->assertStringStartsWith('/agency/requests/', (string) $n->action_url);
        $this->assertNotEmpty($n->title_ar);
        Mail::assertNothingSent();
    }

    public function test_with_no_project_named_the_clients_managers_are_told(): void
    {
        $this->submit();

        $this->assertSame([$this->pm->id], $this->notifiedUsers());
    }

    public function test_the_owner_is_told_when_the_client_has_no_manager(): void
    {
        $this->a->update(['account_manager_id' => null]);
        $this->submit((string) $this->a->id);

        $this->assertSame([$this->owner->id], $this->notifiedUsers());
    }

    public function test_a_manager_who_left_or_belongs_elsewhere_is_not_addressed(): void
    {
        Membership::query()->where('user_id', $this->pm->id)->update(['status' => 'suspended']);
        $this->submit((string) $this->a->id);
        $this->assertSame([$this->owner->id], $this->notifiedUsers());

        // A manager of another workspace recorded on this project by a bad row is never a recipient.
        AppNotification::withoutGlobalScopes()->delete();
        $outsider = User::create(['name' => 'Out', 'email' => 'out@b.test', 'password' => 'secret123']);
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);
        $this->grantMembership($outsider, $rival, Portal::Agency, 'owner');
        $this->a->update(['account_manager_id' => $outsider->id]);
        $recipients = app(ClientRequestManagerNotifier::class)->recipients(
            ExternalRequest::withoutGlobalScopes()->firstOrFail(),
        );
        $this->assertSame([$this->owner->id], $recipients);
    }

    public function test_the_managers_own_settings_decide_the_bell(): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->pm->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode([]),
            'types' => json_encode(['request' => ['in_app' => false, 'email' => false]]),
            'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->submit((string) $this->a->id);

        $this->assertSame([], $this->notifiedUsers());
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'in_app', 'status' => 'suppressed_by_preference']);
    }

    public function test_a_manager_whose_reach_to_this_client_was_removed_is_not_told(): void
    {
        // Still recorded on the project, but now confined to another client.
        $this->a->update(['account_manager_id' => $this->otherPm->id]);
        $this->submit((string) $this->a->id);

        $this->assertSame([$this->owner->id], $this->notifiedUsers());
    }
}
