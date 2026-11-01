<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — client-workspace writes and project creation obey the same client ceiling as the reads next to
 * them, and a project's account manager is a person of the same tenant.
 */
final class ClientWorkspaceWriteCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    public function test_a_member_confined_to_one_client_cannot_rename_archive_or_restore_a_sibling(): void
    {
        $op = $this->scopedTo($this->clientA, ['workspaces.view', 'clients.update', 'clients.delete']);
        $gone = $this->makeClient('Gone', $this->agency);
        $gone->delete();

        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/client-workspaces/{$this->clientB->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($op, 'sanctum')->deleteJson("/api/v1/client-workspaces/{$this->clientB->id}")->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/client-workspaces/{$gone->id}/restore")->assertNotFound();

        $this->assertSame('Client B', $this->clientB->fresh()->name);
        $this->assertNull($this->clientB->fresh()->deleted_at);

        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/client-workspaces/{$this->clientA->id}", ['name' => 'Renamed A'])->assertOk();
        $this->actingAs($this->owner(), 'sanctum')->patchJson("/api/v1/client-workspaces/{$this->clientB->id}", ['name' => 'Renamed B'])->assertOk();
    }

    public function test_a_confined_member_cannot_file_a_project_under_a_sibling_client(): void
    {
        $op = $this->scopedTo($this->clientA, ['projects.create', 'projects.view']);

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/projects', ['name' => 'Sneaky', 'client_workspace_id' => (string) $this->clientB->id])
            ->assertStatus(422);
        $this->assertSame(0, Project::query()->where('name', 'Sneaky')->count());

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/projects', ['name' => 'Fine', 'client_workspace_id' => (string) $this->clientA->id])
            ->assertCreated();
    }

    public function test_a_projects_account_manager_must_belong_to_the_same_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $stranger = $this->operatorIn($other, ['tasks.view']);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/projects', [
            'name' => 'P', 'client_workspace_id' => (string) $this->clientA->id, 'account_manager_id' => $stranger->id,
        ])->assertStatus(422)->assertJsonValidationErrors('account_manager_id');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/projects/{$this->projectA->id}", ['account_manager_id' => $stranger->id])
            ->assertStatus(422);
        $this->assertNull($this->projectA->fresh()->account_manager_id);

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/projects/{$this->projectA->id}", ['account_manager_id' => $owner->id])->assertOk();
    }
}
