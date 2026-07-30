<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The permission catalogue, integrations and operational status (ADMIN-003).
 *
 * All three are READ surfaces, and the tests are mostly about why:
 *
 *   The catalogue is code. A permission invented at runtime would grant nothing, because no
 *   `hasPermission()` call checks for it — it would appear in every role editor as though it did.
 *
 *   The integration view counts; it never reinterprets a provider state. "Awaiting credentials" and
 *   "failed" collapsed into "not connected" would delete the whole content of the answer.
 *
 *   The status check is the SAME one `/dev/status` runs, reached through a different gate rather
 *   than reimplemented — two status pages drift, and the one you are not looking at is the wrong one.
 */
final class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function owner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'status' => 'active',
        ]);
    }

    public function test_the_catalogue_lists_every_permission_grouped(): void
    {
        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/permissions')->assertOk()->json('data');

        $this->assertSame(Permission::count(), $data['total']);
        $this->assertNotEmpty($data['groups']);

        $keys = collect($data['groups'])->flatMap(fn ($g) => array_column($g['permissions'], 'key'));
        $this->assertTrue($keys->contains('clients.view_all'));
        $this->assertTrue($keys->contains('influencers.view_costs'));
    }

    /**
     * A key granted by nothing is either newly added or dead, and a flat list of keys cannot say
     * which. The count is what makes the catalogue readable rather than merely complete.
     */
    public function test_each_permission_reports_how_many_roles_grant_it(): void
    {
        $tenant = $this->tenant('Counting Co');
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('clients.view');

        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/permissions')->assertOk()->json('data');

        $all = collect($data['groups'])->flatMap(fn ($g) => $g['permissions']);
        $this->assertSame(1, $all->firstWhere('key', 'clients.view')['granted_by_roles']);
        $this->assertSame(0, $all->firstWhere('key', 'clients.delete')['granted_by_roles']);
    }

    /** The surface says outright that it is not editable, so nobody hunts for a button. */
    public function test_the_catalogue_declares_itself_read_only(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/permissions')
            ->assertOk()->assertJsonPath('data.editable', false);
    }

    /** There is no write route at all — declaring read-only in the payload is not the enforcement. */
    public function test_there_is_no_endpoint_that_creates_a_permission(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/admin/permissions', ['key' => 'invented.permission'])
            ->assertStatus(405);

        $this->assertDatabaseMissing('permissions', ['key' => 'invented.permission']);
    }

    /**
     * The states are preserved verbatim. Collapsing "awaiting credentials" and "failed" into "not
     * connected" would leave the owner unable to tell a provider nobody has set up from one that is
     * actively broken.
     */
    public function test_integration_states_are_counted_never_reinterpreted(): void
    {
        $a = $this->tenant('A Co');
        $b = $this->tenant('B Co');

        $this->connection($a, 'meta', 'awaiting_credentials');
        $this->connection($b, 'meta', 'failed');

        $providers = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/integrations')->assertOk()->json('data.providers');

        $meta = collect($providers)->firstWhere('provider', 'meta');
        $this->assertSame(1, $meta['by_status']['awaiting_credentials']);
        $this->assertSame(1, $meta['by_status']['failed']);
        $this->assertArrayNotHasKey('connected', $meta['by_status']);
    }

    /** Status is reachable for the owner, and answers with the real checks. */
    public function test_the_owner_can_read_operational_status(): void
    {
        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/status')->assertOk()->json('data');

        $this->assertSame('running', $data['backend']['state']);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('queue_worker', $data);
    }

    public function test_all_three_surfaces_are_closed_to_a_tenant_user(): void
    {
        $tenant = $this->tenant('Outsider Co');
        $user = User::create([
            'name' => 'Outsider', 'email' => 'outsider@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        foreach (['/api/v1/admin/permissions', '/api/v1/admin/integrations', '/api/v1/admin/status'] as $path) {
            $this->actingAs($user, 'sanctum')->getJson($path)->assertForbidden();
        }
    }

    /** A connection needs a credential row and a scope — both NOT NULL on the real table. */
    private function connection(Tenant $tenant, string $provider, string $status): void
    {
        $credential = IntegrationCredential::create([
            'tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'tenant',
            'credential_type' => 'oauth', 'encrypted_payload' => 'x', 'status' => $status,
        ]);

        ProviderConnection::create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.' '.$status, 'scope' => 'tenant', 'status' => $status,
        ]);
    }

    public function test_all_three_surfaces_require_a_session(): void
    {
        foreach (['/api/v1/admin/permissions', '/api/v1/admin/integrations', '/api/v1/admin/status'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }
}
