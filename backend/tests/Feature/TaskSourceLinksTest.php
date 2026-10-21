<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OPS-LINK-001 — a task says what it came from and who holds it, from stored facts only.
 */
final class TaskSourceLinksTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner Person', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant, Portal::App);
        $this->owner->assignRole($role);
        $this->assignee = User::create(['name' => 'Assigned Person', 'email' => 'x-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
    }

    private function rule(Tenant $tenant): string
    {
        return (string) AlertRule::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'type' => 'roas_drop', 'name' => 'R', 'severity' => 'critical', 'create_task' => true, 'active' => true,
        ])->id;
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'title' => 'T-'.uniqid(),
            'status' => 'todo', 'priority' => 'high', 'created_by' => $this->owner->id,
        ], $over));
    }

    /** @return array<string, array<string,mixed>> keyed by task id */
    private function rows(): array
    {
        $rows = (array) $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/tasks?per_page=50')->assertOk()->json('data');

        return collect($rows)->keyBy('id')->all();
    }

    public function test_a_task_an_alert_opened_links_back_to_that_alert(): void
    {
        $task = $this->task(['meta' => ['source' => 'alert', 'alert_type' => 'spend_spike'], 'assignee_id' => $this->assignee->id]);
        $event = AlertEvent::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $this->rule($this->tenant), 'type' => 'spend_spike',
            'dedup_key' => 'k-'.uniqid(), 'status' => 'open', 'severity' => 'critical', 'task_id' => $task->id,
            'context' => ['title' => 'Spend jumped on Snapchat'],
        ]);

        $row = $this->rows()[(string) $task->id];

        $this->assertSame('alert', $row['source']['kind']);
        $this->assertSame((string) $event->id, $row['source']['id']);
        $this->assertSame('Spend jumped on Snapchat', $row['source']['title']);
        $this->assertSame('critical', $row['source']['severity']);
        $this->assertSame('Assigned Person', $row['assignee_name']);
        $this->assertSame('Owner Person', $row['created_by_name']);
    }

    public function test_a_task_without_an_alert_says_what_its_creator_recorded_or_that_a_person_made_it(): void
    {
        $manual = $this->task();
        $fromDelivery = $this->task(['meta' => ['source' => 'delivery']]);

        $rows = $this->rows();

        $this->assertSame(['kind' => 'manual'], $rows[(string) $manual->id]['source']);
        $this->assertSame(['kind' => 'delivery'], $rows[(string) $fromDelivery->id]['source']);
        $this->assertNull($rows[(string) $manual->id]['assignee_name'], 'nobody assigned is said as nobody, not guessed');
    }

    public function test_another_workspaces_alert_never_claims_this_workspaces_task(): void
    {
        $task = $this->task();
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        AlertEvent::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'rule_id' => $this->rule($other), 'type' => 'x', 'dedup_key' => 'k-'.uniqid(),
            'status' => 'open', 'severity' => 'warning', 'task_id' => $task->id, 'context' => ['title' => 'Not yours'],
        ]);

        $this->assertSame(['kind' => 'manual'], $this->rows()[(string) $task->id]['source']);
    }
}
