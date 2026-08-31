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
use Tests\TestCase;

/**
 * PROJECT-CREATE-WORKSPACE-001 — who owns an advertiser's project, and who decides for an agency.
 *
 * ## The live failure this is written from
 *
 * A customer completed the first real Snapchat authorisation, 309 ad accounts were discovered, and
 * the wizard offered «إنشاء مشروع ومتابعة الربط» because the workspace had no projects. Pressing it
 * answered **«حدث خطأ غير متوقع.»** and created nothing.
 *
 * Nothing crashed. `POST /projects` was never called. The wizard did this first:
 *
 * ```ts
 * const workspaceId = workspaces.data?.[0]?.id
 * if (!workspaceId) throw new Error('لا توجد مساحة عميل.')
 * ```
 *
 * — and `toApiError` reads a message off an axios ENVELOPE, so a locally thrown `Error` carries no
 * status, no response and no envelope, lands in the `unexpected` branch, and its own message is
 * discarded in favour of the generic string. Two defects stacked: a domain rule that had no answer
 * for an advertiser, and an error path that hid which rule had refused.
 *
 * ## What `workspaces[0]` was standing in for
 *
 * A `client_workspaces` row is an AGENCY's client. `projects.client_workspace_id` is NOT NULL, so
 * every project needs one — including the projects of an advertiser who has no clients and never
 * will. The product's answer was to take whichever row came back first, which is wrong twice over:
 * it is nothing at all for an advertiser, and for an agency it silently files a new project under
 * whichever client sorted first.
 *
 * The rule these tests hold:
 *
 *  - an ADVERTISER has one canonical, tenant-owned container, created deterministically and
 *    idempotently, and never has to invent a «client» to hold their own work;
 *  - an AGENCY names the client explicitly, always — the answer is never «the first one».
 */
final class AdvertiserOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ── The advertiser ────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** An advertiser with no client workspace can still create a project.
     *
     * Before the fix this was impossible from anywhere: the wizard could not name a workspace, and
     * the endpoint required one.
     */
    public function test_an_advertiser_with_no_client_workspace_can_create_a_project(): void
    {
        [$tenant, $user] = $this->advertiser();

        $this->assertSame(0, ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', ['name' => 'حملة الإطلاق']);

        $response->assertCreated()->assertJsonPath('data.name', 'حملة الإطلاق');

        $project = Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotNull(
            $project->client_workspace_id,
            'PROJECT-CREATE-WORKSPACE-001: projects.client_workspace_id is NOT NULL, so an advertiser '
                .'project must be given a real container rather than left to fail.',
        );

        $workspace = ClientWorkspace::withoutGlobalScopes()->findOrFail($project->client_workspace_id);
        $this->assertSame($tenant->id, $workspace->tenant_id, 'the container belongs to the tenant that owns it');
    }

    /** Creating a second project does not create a second container — the resolution is idempotent. */
    public function test_the_canonical_container_is_created_once_however_many_projects(): void
    {
        [$tenant, $user] = $this->advertiser();

        foreach (['أول', 'ثانٍ', 'ثالث'] as $name) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', ['name' => $name])->assertCreated();
        }

        $this->assertSame(
            1,
            ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'a canonical container is resolved, not minted per request',
        );
        $this->assertSame(3, Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /** An advertiser who already has their one workspace keeps it rather than gaining a second. */
    public function test_an_existing_single_workspace_is_adopted_and_not_duplicated(): void
    {
        [$tenant, $user] = $this->advertiser();

        app(TenantContext::class)->setTenantId($tenant->id);
        $existing = ClientWorkspace::create(['name' => 'المتجر', 'slug' => 'store', 'mode' => 'managed']);
        app(TenantContext::class)->forget();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', ['name' => 'P'])->assertCreated();

        $this->assertSame(1, ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            $existing->id,
            Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('client_workspace_id'),
        );
    }

    // ── The agency ────────────────────────────────────────────────────────────────────────────

    /**
     * **No arbitrary fallback.** An agency with several clients is asked, never guessed at.
     *
     * This is the assertion that forbids `workspaces[0]`, `first()` and `oldest()` from coming back
     * as a server-side convenience: filing a new project under whichever client sorted first is how
     * one client's work ends up in another client's portal.
     */
    public function test_an_agency_with_several_clients_is_refused_rather_than_guessed_at(): void
    {
        [$tenant, $user] = $this->agency();

        app(TenantContext::class)->setTenantId($tenant->id);
        $a = ClientWorkspace::create(['name' => 'عميل أ', 'slug' => 'client-a', 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'عميل ب', 'slug' => 'client-b', 'mode' => 'managed']);
        app(TenantContext::class)->forget();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', ['name' => 'حملة']);

        $response->assertStatus(422)->assertJsonValidationErrors('client_workspace_id');

        $this->assertSame(0, Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertNotSame($a->id, $b->id);
    }

    /** An agency with ONE client is asked too — one client today is not a rule about tomorrow. */
    public function test_an_agency_with_a_single_client_is_still_asked(): void
    {
        [$tenant, $user] = $this->agency();

        app(TenantContext::class)->setTenantId($tenant->id);
        ClientWorkspace::create(['name' => 'العميل الوحيد', 'slug' => 'only', 'mode' => 'managed']);
        app(TenantContext::class)->forget();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', ['name' => 'حملة'])
            ->assertStatus(422)->assertJsonValidationErrors('client_workspace_id');
    }

    /** And when the agency does name one, that is where the project goes. */
    public function test_a_named_client_workspace_is_honoured(): void
    {
        [$tenant, $user] = $this->agency();

        app(TenantContext::class)->setTenantId($tenant->id);
        ClientWorkspace::create(['name' => 'عميل أ', 'slug' => 'client-a', 'mode' => 'managed']);
        $chosen = ClientWorkspace::create(['name' => 'عميل ب', 'slug' => 'client-b', 'mode' => 'managed']);
        app(TenantContext::class)->forget();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $chosen->id, 'name' => 'حملة',
        ])->assertCreated();

        $this->assertSame(
            $chosen->id,
            Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('client_workspace_id'),
        );
    }

    /** Another tenant's workspace is not a container this tenant may name. */
    public function test_another_tenants_workspace_is_refused(): void
    {
        [, $user] = $this->advertiser();
        [$other] = $this->agency('other');

        app(TenantContext::class)->setTenantId($other->id);
        $theirs = ClientWorkspace::create(['name' => 'ليس لك', 'slug' => 'not-yours', 'mode' => 'managed']);
        app(TenantContext::class)->forget();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $theirs->id, 'name' => 'حملة',
        ])->assertStatus(422)->assertJsonValidationErrors('client_workspace_id');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Tenant, 1: User} */
    private function advertiser(string $slug = 'brand'): array
    {
        return $this->tenantWithOwner($slug, 'brand');
    }

    /** @return array{0: Tenant, 1: User} */
    private function agency(string $slug = 'agency'): array
    {
        return $this->tenantWithOwner($slug, 'agency');
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithOwner(string $slug, string $accountType): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug.'-'.uniqid(), 'status' => 'active',
            'account_type' => $accountType,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.$slug]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create([
            'name' => 'Owner', 'email' => $slug.'-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        app(TenantContext::class)->forget();

        return [$tenant, $user];
    }
}
