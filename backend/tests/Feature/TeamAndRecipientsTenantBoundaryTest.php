<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — people named in a request belong to the caller's tenant: the project team enrols only members of
 * it and never names a stranger; the agency team names only the clients the reader reaches.
 */
final class TeamAndRecipientsTenantBoundaryTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        $this->other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
    }

    public function test_the_project_team_enrols_only_people_of_this_tenant(): void
    {
        $owner = $this->owner();
        $stranger = $this->operatorIn($this->other, ['tasks.view'], null, 'stranger@elsewhere.test');
        $colleague = $this->operatorIn($this->agency, ['tasks.view']);

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/projects/{$this->projectA->id}/team", ['user_id' => $stranger->id, 'role' => 'viewer'])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame(0, ProjectMembership::withoutGlobalScopes()->where('user_id', $stranger->id)->count());

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/projects/{$this->projectA->id}/team", ['user_id' => $colleague->id, 'role' => 'viewer'])->assertCreated();
    }

    public function test_the_project_team_list_never_names_a_user_of_another_tenant(): void
    {
        $owner = $this->owner();
        $stranger = $this->operatorIn($this->other, ['tasks.view'], null, 'stranger@elsewhere.test');
        // A row written before the enrolment check existed.
        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'project_id' => $this->projectA->id, 'user_id' => $stranger->id, 'role' => 'viewer', 'status' => 'active',
        ]);

        $res = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/team")->assertOk();

        $this->assertStringNotContainsString('stranger@elsewhere.test', (string) $res->getContent());
    }

    public function test_the_agency_team_names_only_clients_the_reader_reaches(): void
    {
        $this->operatorIn($this->agency, ['clients.view'], [(string) $this->clientA->id], 'ma@ceiling.test');
        $reader = $this->operatorIn($this->agency, ['users.view', 'clients.view'], [(string) $this->clientB->id], 'mb@ceiling.test');

        $res = $this->actingAs($reader, 'sanctum')->getJson('/api/v1/agency/team')->assertOk();
        $row = collect($res->json('data.members'))->firstWhere('user.email', 'ma@ceiling.test');

        $this->assertSame([(string) $this->clientA->id], $row['client_scope_ids'], 'the id is counted');
        $this->assertNull($row['clients'][0]['name'], 'but a client the reader cannot reach is not named');
    }
}
