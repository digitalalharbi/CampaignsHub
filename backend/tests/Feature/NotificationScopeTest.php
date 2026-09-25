<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * PROJECT-NOTIFICATION-SCOPE-001 — a project notification and an agency one are different things.
 *
 * ## The ambiguity
 *
 * «تكلفة الطلب ارتفعت في مشروع رزة أفينيو» and «انتهت صلاحية تفويض سناب شات» are not the same kind
 * of message. One is about one client's money; the other is about the agency's plumbing and belongs
 * to the whole team. In the payload they were indistinguishable except that one carried a project id
 * and the other did not — so every surface that drew them had to re-derive the distinction from a
 * null check, and four components deriving the same thing is four chances to draw them alike.
 *
 * The owner's instruction is that the two must not share copy or treatment. That starts with the
 * server saying which is which.
 *
 * ## Derived, not stored
 *
 * `project_id` already IS the fact. A column repeating it would be a second thing to keep true, and
 * the first time they disagreed the interface would believe the wrong one.
 *
 * ## And the boundary underneath it
 *
 * The scope label is only worth having if the rows are actually separated, so this also pins that a
 * project's own listing never returns another project's notification.
 */
final class NotificationScopeTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $first;

    private Project $second;

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

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);

        $this->first = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'رزة أفينيو', 'status' => 'active',
        ]);
        $this->second = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'عميل آخر', 'status' => 'active',
        ]);

        foreach ([$this->first, $this->second] as $p) {
            ProjectMembership::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $p->id,
                'user_id' => $this->operator->id, 'role' => 'manager', 'status' => 'active',
            ]);
        }
    }

    /** **The distinction, stated by the server.** */
    public function test_a_notification_about_one_project_says_it_is_project_scoped(): void
    {
        $this->notification($this->first, 'ارتفعت تكلفة الطلب');

        $row = $this->listFor($this->first)[0];

        $this->assertSame('project', $row['scope']);
        $this->assertSame((string) $this->first->id, $row['project_id']);
    }

    /**
     * The agency's own plumbing is `portfolio`, not «a project notification with a missing field».
     */
    public function test_a_tenant_wide_alert_says_it_is_portfolio_scoped(): void
    {
        $this->notification(null, 'انتهت صلاحية التفويض');

        $row = collect($this->listAll())->firstWhere('title', 'انتهت صلاحية التفويض');

        $this->assertNotNull($row);
        $this->assertSame('portfolio', $row['scope']);
        $this->assertNull($row['project_id']);
    }

    /**
     * **The boundary the label depends on.** A project's listing is that project's.
     *
     * A scope word is worth nothing if the rows beneath it are mixed, so this asserts the separation
     * rather than trusting the global scope to have been applied.
     */
    public function test_a_projects_listing_never_carries_another_projects_notification(): void
    {
        $this->notification($this->first, 'خاص بالأول');
        $this->notification($this->second, 'خاص بالثاني');

        $titles = array_column($this->listFor($this->first), 'title');

        $this->assertContains('خاص بالأول', $titles);
        $this->assertNotContains('خاص بالثاني', $titles, 'another project’s notification reached this project');
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function listFor(Project $project): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/notifications")
            ->assertOk()
            ->json('data');
    }

    /** @return list<array<string,mixed>> */
    private function listAll(): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('data');
    }

    private function notification(?Project $project, string $title): void
    {
        AppNotification::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project?->id,
            'user_id' => null,
            'type' => 'operational',
            'severity' => 'info',
            'title' => $title,
            'message' => $title,
            'status' => 'unread',
        ]);
    }
}
