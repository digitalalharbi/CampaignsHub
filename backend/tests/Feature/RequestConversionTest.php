<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestConversion;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequestConversionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);

        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'owner@agency.test', 'password' => 'secret123']);
        $this->owner->assignRole($ownerRole);
        $this->viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'email' => 'viewer@agency.test', 'password' => 'secret123']);
        $this->viewer->assignRole($viewerRole);
    }

    private function submit(string $type = 'paid_campaign_launch'): ExternalRequest
    {
        app(TenantContext::class)->forget();
        $ref = $this->postJson('/api/v1/requests', [
            'type' => $type, 'contact_name' => 'Client Co', 'contact_email' => 'c@co.test',
            'company_name' => 'Client Co LLC', 'budget' => 30000, 'currency' => 'SAR',
            'objective' => 'Scale Ramadan sales', 'metadata' => ['platforms' => ['Meta', 'Google']],
        ])->json('data.reference');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return ExternalRequest::where('reference', $ref)->firstOrFail();
    }

    public function test_new_client_conversion_creates_client_project_draft_campaign_and_tasks(): void
    {
        $req = $this->submit();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert")
            ->assertCreated()->assertJsonPath('data.status', 'completed');

        $client = ClientWorkspace::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertEquals('onboarding', $client->client_status);
        $this->assertEquals('request_portal', $client->client_source);
        $this->assertEquals($req->id, $client->source_request_id);

        $project = Project::where('client_workspace_id', $client->id)->firstOrFail();
        $campaign = UnifiedCampaign::where('project_id', $project->id)->firstOrFail();
        $this->assertEquals('draft', $campaign->status); // honest — never Active before a real sync
        $this->assertEquals('sales', $campaign->objective); // paid_media default mapping
        $this->assertEquals('30000.0000', (string) $campaign->total_budget);

        // Service-specific setup tasks for paid_media (8 of them).
        $this->assertEquals(8, Task::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'title' => 'Connect Advertising Platforms']);

        // The request now carries the relations.
        $req->refresh();
        $this->assertEquals($client->id, $req->client_id);
        $this->assertEquals($campaign->id, $req->campaign_id);
    }

    public function test_conversion_is_idempotent_no_duplicates_on_retry(): void
    {
        $req = $this->submit();
        $first = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert")->assertCreated();
        $second = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert")->assertCreated();

        $this->assertEquals($first->json('data.client_id'), $second->json('data.client_id'));
        $this->assertEquals(1, ClientWorkspace::where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(1, Project::count());
        $this->assertEquals(1, UnifiedCampaign::count());
        $this->assertEquals(8, Task::count()); // not doubled
        $this->assertEquals(1, RequestConversion::count());
    }

    public function test_conversion_into_an_existing_client_reuses_it(): void
    {
        $existing = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Existing', 'slug' => 'existing', 'mode' => 'managed', 'status' => 'active']);
        $req = $this->submit();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert", ['client_id' => $existing->id])
            ->assertCreated()->assertJsonPath('data.client_id', $existing->id);

        $this->assertEquals(1, ClientWorkspace::where('tenant_id', $this->tenant->id)->count()); // no new client
        $this->assertEquals(1, Project::where('client_workspace_id', $existing->id)->count());
    }

    public function test_selecting_a_cross_tenant_client_is_rejected_and_nothing_is_created(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreign = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'mode' => 'managed', 'status' => 'active']);
        $req = $this->submit();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert", ['client_id' => $foreign->id])
            ->assertStatus(500); // RuntimeException → rolled back

        $this->assertEquals(0, Project::count());
        $this->assertEquals(0, UnifiedCampaign::count());
        $this->assertEquals(0, RequestConversion::where('status', 'completed')->count());
    }

    public function test_rollback_leaves_no_partial_entities_on_failure(): void
    {
        // Convert request A with an idempotency key.
        $a = $this->submit();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$a->id}/convert", ['idempotency_key' => 'SHARED-KEY'])->assertCreated();
        $clientsAfterA = ClientWorkspace::count();

        // Request B reuses the SAME idempotency key → the unique constraint fails AFTER building the entities,
        // so the whole transaction rolls back — no partial client/project/campaign/tasks for B.
        $b = $this->submit();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$b->id}/convert", ['idempotency_key' => 'SHARED-KEY'])
            ->assertStatus(500);

        $this->assertEquals($clientsAfterA, ClientWorkspace::count()); // no extra client from B
        $this->assertEquals(1, Project::count());
        $this->assertEquals(1, UnifiedCampaign::count());
        $b->refresh();
        $this->assertNull($b->client_id); // B remains convertible
    }

    public function test_conversion_requires_permission_and_blocks_archived(): void
    {
        $req = $this->submit();
        $this->actingAs($this->viewer, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert")->assertForbidden();

        $req->forceFill(['archived_at' => now()])->save();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/requests/{$req->id}/convert")->assertStatus(422);
    }
}
