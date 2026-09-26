<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * BRANDING-RENDER-EVIDENCE-001 — the Branding Center answered 500, and nothing noticed.
 *
 * `present()` built an asset's url with `route('branding.assets.file', …)`. These routes are
 * registered inside the api group, which prefixes every name with `api.v1.`, so that name matched
 * nothing and `route()` threw `RouteNotFoundException` — rendered as a 500.
 *
 * `present()` is on the way OUT of both `upload()` and `assets()`, so the whole surface was down: an
 * agency could not upload a logo, and could not list the ones it already had. Every other branding
 * suite in this tree calls `BrandingService::storeAsset()` directly and never goes through the
 * controller, which is exactly why a dead endpoint could sit there while eight tests stayed green —
 * this requirement's own «code containing `logo_url` is not completion», one layer further out than
 * it was written for.
 *
 * Found by driving the real endpoint from a browser rather than from a service.
 */
final class BrandingCenterUploadTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'bc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        /*
         * The permission CATALOGUE first, then the role.
         *
         * `givePermissionTo()` grants what exists; with an unseeded table it grants nothing and the
         * endpoint answers 403, which reads as an authorisation defect rather than an empty fixture.
         */
        $this->seed(PermissionSeeder::class);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Operator',
            'email' => 'op-'.uniqid().'@agency.test',
            'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $tenant);
        $this->operator->assignRole($role);
    }

    /** The upload a brand manager actually performs, through the route the interface calls. */
    public function test_a_mark_can_be_uploaded_through_the_endpoint_the_interface_calls(): void
    {
        $this->assertTrue($this->operator->fresh()?->hasPermission('branding.manage') ?? false, 'the fixture granted no branding.manage');

        $response = $this->actingAs($this->operator, 'sanctum')->post('/api/v1/branding/assets', [
            'scope' => 'tenant',
            'kind' => 'primary_horizontal',
            'theme' => 'any',
            'file' => UploadedFile::fake()->createWithContent('mark.png', $this->png()),
        ]);

        $response->assertCreated();

        // The url it hands back has to be a url — a name that resolves to nothing is the defect.
        $url = (string) ($response->json('data.url') ?? '');
        $this->assertStringContainsString('/api/v1/branding/assets/', $url);
        $this->assertStringEndsWith('/file', $url);
    }

    /** And the list, which goes through the same presenter and was down for the same reason. */
    public function test_the_asset_list_answers_rather_than_failing_on_the_same_name(): void
    {
        $this->actingAs($this->operator, 'sanctum')->post('/api/v1/branding/assets', [
            'scope' => 'tenant',
            'kind' => 'primary_horizontal',
            'theme' => 'any',
            'file' => UploadedFile::fake()->createWithContent('mark.png', $this->png()),
        ])->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/branding/assets')
            ->assertOk()
            ->assertJsonPath('data.0.scope', 'tenant');
    }

    /** The smallest real PNG — the validator reads the bytes, not the extension. */
    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }
}
