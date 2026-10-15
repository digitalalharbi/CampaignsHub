<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SAVE-ACCOUNTS-SILENCE-001 — «Save accounts» all the way through, not just its button.
 *
 * The owner's report is that pressing Save appeared to do nothing. The UI half of that is a missing
 * failure state and is fixed in `ConnectionWizard`. This is the other half, and it had NO test at
 * all: `applySelection` — the endpoint that press reaches — was covered by nothing, so «it persisted»
 * rested on reading the code.
 *
 * Covered here, in the order a person experiences them: the request is accepted, the bindings are
 * really written, they are still there when the dialog is reopened, an unticked account is
 * DEACTIVATED rather than deleted, and the answer names what changed.
 */
final class SaveAccountsRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-save', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->getKey());

        $role = Role::create(['tenant_id' => $tenant->getKey(), 'name' => 'Owner', 'slug' => 'owner-save']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@save.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-save', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    public function test_a_save_persists_and_is_still_there_when_the_dialog_reopens(): void
    {
        [$connectionId, $accounts] = $this->discover();
        $first = $accounts[0];

        $diff = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $connectionId,
                'external_account_ids' => [$first],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([$first], $diff['added']);

        // Written, not merely answered for.
        $this->assertDatabaseHas('project_integration_bindings', [
            'project_id' => $this->project->getKey(), 'external_account_id' => $first, 'is_active' => true,
        ]);

        /*
         * Reopening the dialog reads the BINDINGS, which is what «still ticked after a reload» means.
         * Asserted through the endpoint the wizard actually calls rather than the table, so a change
         * of shape there fails this too.
         */
        $bound = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/integrations")
            ->assertOk()
            ->json('data');

        /*
         * The binding nests its account — `account.id` — rather than carrying the id flat. Written
         * against the payload the wizard reads, so a change of shape there fails here too.
         */
        $active = array_values(array_filter($bound, static fn (array $b): bool => $b['is_active'] === true));
        $this->assertContains($first, array_map(static fn (array $b) => $b['account']['id'] ?? null, $active));
    }

    /**
     * Unticking deactivates; it must never delete.
     *
     * The binding is what makes months of metrics this project's, so a delete would orphan them —
     * and the row coming back as `is_active = false` rather than vanishing is the difference.
     */
    public function test_unticking_an_account_deactivates_its_binding_rather_than_deleting_it(): void
    {
        [$connectionId, $accounts] = $this->discover();
        $first = $accounts[0];

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $connectionId, 'external_account_ids' => [$first],
            ])->assertOk();

        $diff = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                // «None» is a legitimate answer, and it is not the same as «unset».
                'connection_id' => $connectionId, 'external_account_ids' => [],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([$first], $diff['removed']);
        $this->assertDatabaseHas('project_integration_bindings', [
            'project_id' => $this->project->getKey(), 'external_account_id' => $first, 'is_active' => false,
        ]);
        $this->assertSame(1, DB::table('project_integration_bindings')
            ->where('project_id', $this->project->getKey())->where('external_account_id', $first)->count());
    }

    /** A save that changes nothing says so, and writes nothing. */
    public function test_saving_the_same_set_again_reports_no_change(): void
    {
        [$connectionId, $accounts] = $this->discover();
        $first = $accounts[0];

        $payload = ['connection_id' => $connectionId, 'external_account_ids' => [$first]];
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", $payload)->assertOk();

        $diff = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", $payload)
            ->assertOk()->json('data');

        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([$first], $diff['unchanged']);
    }

    /**
     * A refusal is a refusal, not a silent success.
     *
     * The UI had no failure state at all, which is only safe if the endpoint never refuses. It does:
     * without the permission this answers 403, and the dialog has to be able to say so.
     */
    public function test_the_endpoint_refuses_an_operator_without_the_permission(): void
    {
        [$connectionId, $accounts] = $this->discover();

        $stripped = Role::create(['tenant_id' => $this->owner->memberships()->first()->tenant_id, 'name' => 'Viewer', 'slug' => 'viewer-save']);
        $stripped->givePermissionTo('integrations.view');
        $viewer = User::create(['name' => 'V', 'email' => 'v@save.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, Tenant::firstOrFail());
        $viewer->assignRole($stripped);

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $connectionId, 'external_account_ids' => [$accounts[0]],
            ])
            ->assertForbidden();
    }

    /**
     * Establish a sandbox connection and return its id with the ad accounts it discovered.
     *
     * @return array{0: string, 1: list<string>}
     */
    /**
     * ACCOUNT-SELECTION-SCOPE-001 (Owner, ReSano / TikTok) — saving one platform's choice on a
     * project that already holds another platform's account.
     *
     * «Manage accounts» seeded the dialog from every binding the project had and sent them all with
     * the connection being edited; the connection did not own the other platform's id, the save was
     * refused as a whole, and the screen said only «البيانات المُدخلة غير صحيحة».
     */
    public function test_a_save_on_one_connection_keeps_another_connections_binding_and_succeeds(): void
    {
        [$firstConnection, $first] = $this->discover();
        [$secondConnection, $second] = $this->discover();
        $this->assertNotSame($firstConnection, $secondConnection);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $firstConnection, 'external_account_ids' => [$first[0]],
            ])->assertOk();

        // What the dialog really sent: the project's other binding AND the new choice.
        $diff = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $secondConnection, 'external_account_ids' => [$first[0], $second[0]],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([$second[0]], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertDatabaseHas('project_integration_bindings', [
            'project_id' => $this->project->getKey(), 'external_account_id' => $first[0], 'is_active' => true,
        ]);
        $this->assertDatabaseHas('project_integration_bindings', [
            'project_id' => $this->project->getKey(), 'external_account_id' => $second[0], 'is_active' => true,
        ]);

        // And the bindings list says which connection each came through.
        $bound = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/integrations")->assertOk()->json('data');
        $byAccount = collect($bound)->keyBy(static fn (array $b) => $b['account']['id']);
        $this->assertSame($firstConnection, $byAccount[$first[0]]['account']['connection_id']);
        $this->assertSame($secondConnection, $byAccount[$second[0]]['account']['connection_id']);
    }

    public function test_an_id_no_connection_owns_is_refused_naming_the_field(): void
    {
        [$connectionId] = $this->discover();

        $res = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $connectionId,
                'external_account_ids' => ['5b0d6b48-6b0a-4d1c-8c8a-6a1f4f0c9a11'],
            ])
            ->assertStatus(422);

        $this->assertArrayHasKey('external_account_ids', $res->json('errors'));
        $this->assertStringContainsString('5b0d6b48', (string) $res->json('errors.external_account_ids.0'));
    }

    private function discover(): array
    {
        $connect = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/connect")
            ->assertCreated()
            ->json('data');

        $accounts = collect($connect['accounts'])
            ->where('account_type', 'ad_account')
            ->pluck('id')
            ->values()
            ->all();

        $this->assertNotEmpty($accounts, 'the sandbox connector discovered no ad account to select');

        return [(string) $connect['connection']['id'], $accounts];
    }
}
