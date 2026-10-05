<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REPORT-IDENTITY-001 — the two identities a report will carry, asked for before one exists.
 *
 * Every report surface already resolved «who prepared this» and «who is it for». The BUILDER could
 * not, because there is no report yet and no share token to address one by — so the one screen where
 * an operator decides what to send showed neither identity.
 *
 * What these hold is that it resolves THE SAME WAY (same service, same client resolution, nothing
 * written), and that asking by project cannot reach another tenant's marks. The second is the one
 * that matters: an endpoint that resolves branding is an endpoint somebody will try to point
 * elsewhere.
 */
final class ReportIdentityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private User $operator;

    private Project $alphaProject;

    private Project $betaProject;

    private ClientWorkspace $alpha;

    private ClientWorkspace $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);

        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'ri-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->agency->id);

        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->agency);
        $this->operator->assignRole($role);

        // Two clients of ONE company — the case the whole rule exists for.
        $this->alpha = ClientWorkspace::create(['name' => 'العميل الأول', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $this->beta = ClientWorkspace::create(['name' => 'العميل الثاني', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);

        $this->alphaProject = Project::create(['client_workspace_id' => $this->alpha->id, 'name' => 'PA', 'status' => 'active']);
        $this->betaProject = Project::create(['client_workspace_id' => $this->beta->id, 'name' => 'PB', 'status' => 'active']);
    }

    /** The company prepares; the client is the subject. Two separate identities, both named. */
    public function test_it_answers_with_the_company_and_the_client_separately(): void
    {
        $this->mark('tenant', null);
        $this->mark('client', $this->alpha->id);

        $data = $this->identityFor($this->alphaProject);

        $this->assertSame((string) $this->agency->name, $data['agency']['name']);
        $this->assertSame('العميل الأول', $data['client']['name']);
        $this->assertNotNull($data['agency']['logo_url']);
        $this->assertNotNull($data['client']['logo_url']);
        $this->assertNotSame($data['agency']['logo_url'], $data['client']['logo_url'], 'both marks resolved to one URL');
    }

    /**
     * **Two clients, one company: the same company mark, different client marks.**
     *
     * The failure this prevents is the one that matters most on a client's report — one client's
     * mark appearing on another client's document.
     */
    public function test_two_clients_share_the_company_mark_and_keep_their_own(): void
    {
        $this->mark('tenant', null);
        $this->mark('client', $this->alpha->id, 'alpha');
        $this->mark('client', $this->beta->id, 'beta');

        $a = $this->identityFor($this->alphaProject);
        $b = $this->identityFor($this->betaProject);

        /*
         * Compared as BYTES, not as URLs.
         *
         * Every URL here is project-scoped — that is the isolation — so the company's mark has a
         * different address under each client while being the same file. Asserting the addresses
         * would have asserted the scoping and called it a logo.
         */
        $this->assertSame(
            $this->bytesOf($this->alphaProject, 'agency'),
            $this->bytesOf($this->betaProject, 'agency'),
            'the company mark differs between its own clients',
        );
        $this->assertNotSame(
            $this->bytesOf($this->alphaProject, 'client'),
            $this->bytesOf($this->betaProject, 'client'),
            'two clients resolved to one mark',
        );

        $this->assertSame('العميل الأول', $a['client']['name']);
        $this->assertSame('العميل الثاني', $b['client']['name']);
    }

    /** A client with no mark is still named — the fallback the whole rule rests on. */
    public function test_a_client_with_no_mark_is_still_named(): void
    {
        $this->mark('tenant', null);

        $data = $this->identityFor($this->betaProject);

        $this->assertSame('العميل الثاني', $data['client']['name']);
        $this->assertNull($data['client']['logo_url']);
        // And the company's mark is unaffected by the client having none.
        $this->assertNotNull($data['agency']['logo_url']);
    }

    /** The bytes resolve per role, and the URL carries no asset id to edit. */
    public function test_the_marks_bytes_are_addressed_by_role(): void
    {
        $this->mark('tenant', null);
        $this->mark('client', $this->alpha->id);

        foreach (['agency', 'client'] as $role) {
            $this->actingAs($this->operator, 'sanctum')
                ->withHeader('X-Tenant-ID', $this->agency->id)
                ->get("/api/v1/projects/{$this->alphaProject->id}/report-identity/logo/{$role}")
                ->assertOk();
        }

        $this->assertStringNotContainsString(
            (string) $this->alpha->id,
            $this->identityFor($this->alphaProject)['client']['logo_url'],
            'the URL carries an id somebody can edit',
        );
    }

    /** **And another agency cannot ask about this project at all.** */
    public function test_another_agency_cannot_reach_this_projects_identity(): void
    {
        $this->mark('tenant', null);
        $this->mark('client', $this->alpha->id);

        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid(), 'status' => 'active']);
        $outsider = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($outsider, $rival);

        $this->actingAs($outsider, 'sanctum')
            ->withHeader('X-Tenant-ID', $rival->id)
            ->getJson("/api/v1/projects/{$this->alphaProject->id}/report-identity")
            ->assertStatus(404);

        $this->actingAs($outsider, 'sanctum')
            ->withHeader('X-Tenant-ID', $rival->id)
            ->get("/api/v1/projects/{$this->alphaProject->id}/report-identity/logo/client")
            ->assertStatus(404);
    }

    /** Asking writes nothing: there is no report, and none is created by asking what one would carry. */
    public function test_asking_creates_no_report(): void
    {
        $before = Report::withoutGlobalScopes()->count();

        $this->identityFor($this->alphaProject);

        $this->assertSame($before, Report::withoutGlobalScopes()->count());
    }

    /** What a role's mark actually IS, for a comparison that is about the file rather than its URL. */
    private function bytesOf(Project $project, string $role): string
    {
        $response = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->agency->id)
            ->get("/api/v1/projects/{$project->id}/report-identity/logo/{$role}")
            ->assertOk();

        return $response->streamedContent();
    }

    /** @return array<string, mixed> */
    private function identityFor(Project $project): array
    {
        return (array) $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->agency->id)
            ->getJson("/api/v1/projects/{$project->id}/report-identity")
            ->assertOk()
            ->json('data');
    }

    /**
     * One mark, with CONTENT of its own.
     *
     * `UploadedFile::fake()->create()` makes files of identical filler whatever they are called, so
     * two clients' marks came back byte-identical and the test that two clients keep their own marks
     * could not fail. A real PNG with a distinguishing tail is the smallest fixture that can.
     */
    private function mark(string $scope, ?string $scopeId, string $marker = 'x'): void
    {
        app(TenantContext::class)->setTenantId($this->agency->id);

        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        app(BrandingService::class)->storeAsset(
            $scope,
            $scopeId,
            'report_logo',
            'any',
            UploadedFile::fake()->createWithContent('mark-'.uniqid().'.png', $png.$marker),
        );
    }
}
