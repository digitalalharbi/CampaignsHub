<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->user->assignRole($role);
    }

    public function test_notifications_are_scoped_to_the_recipient(): void
    {
        $other = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'email' => 'x@agency.test', 'password' => 'secret123']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        AppNotification::create(['user_id' => $this->user->id, 'type' => 'campaign.stopped', 'severity' => 'warning', 'title' => 'For me']);
        AppNotification::create(['user_id' => $other->id, 'type' => 'campaign.stopped', 'severity' => 'warning', 'title' => 'For other']);
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'For me')
            ->assertJsonPath('meta.unread', 1);
    }

    public function test_marking_read_updates_status(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $n = AppNotification::create(['user_id' => $this->user->id, 'type' => 't', 'title' => 'x']);
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertOk()->assertJsonPath('data.status', 'read');
    }

    public function test_can_create_task_and_it_is_tenant_isolated(): void
    {
        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/tasks', [
            'title' => 'Prepare tracking', 'priority' => 'high',
        ])->assertCreated()->assertJsonPath('data.title', 'Prepare tracking');

        // Another tenant cannot see it.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherUser = User::create(['tenant_id' => $other->id, 'name' => 'O2', 'email' => 'o2@other.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo('tasks.view');
        $otherUser->assignRole($role);

        app(TenantContext::class)->forget();
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/v1/tasks')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
