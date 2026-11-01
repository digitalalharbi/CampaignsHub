<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — a task's assignee is a member of the SAME tenant, and creating a task obeys the client ceiling
 * and keeps project and client together.
 */
final class TaskCeilingAndAssigneeTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    public function test_the_assignee_must_belong_to_the_same_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $stranger = $this->operatorIn($other, ['tasks.view']);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'T', 'assignee_id' => $stranger->id])
            ->assertStatus(422)->assertJsonValidationErrors('assignee_id');

        $task = Task::create(['tenant_id' => $this->agency->id, 'title' => 'Mine', 'created_by' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/tasks/{$task->id}", ['assignee_id' => $stranger->id])
            ->assertStatus(422)->assertJsonValidationErrors('assignee_id');
        $this->assertNull($task->fresh()->assignee_id);

        $colleague = $this->operatorIn($this->agency, ['tasks.view']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'T', 'assignee_id' => $colleague->id])->assertCreated();
    }

    public function test_a_scoped_member_cannot_file_a_task_under_another_clients_project_or_client(): void
    {
        $op = $this->scopedTo($this->clientB, ['tasks.view', 'tasks.create']);

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'x', 'project_id' => (string) $this->projectA->id])->assertStatus(422);
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'x', 'client_workspace_id' => (string) $this->clientA->id])->assertStatus(422);
        $this->assertSame(0, Task::query()->count());

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'ok', 'project_id' => (string) $this->projectB->id])->assertCreated();
        // A personal, client-less task stays possible: it is the creator's own.
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'mine'])->assertCreated();
    }

    public function test_a_project_and_a_client_that_disagree_are_refused_and_the_project_fixes_the_client(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/tasks', [
            'title' => 'x', 'project_id' => (string) $this->projectB->id, 'client_workspace_id' => (string) $this->clientA->id,
        ])->assertStatus(422);

        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'y', 'project_id' => (string) $this->projectB->id])
            ->assertCreated()->json('data.id');
        $this->assertSame((string) $this->clientB->id, (string) Task::withoutGlobalScopes()->findOrFail($id)->client_workspace_id);
    }
}
