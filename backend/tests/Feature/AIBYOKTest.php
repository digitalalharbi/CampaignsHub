<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AIBYOKTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Scope is request-scoped since ADR 0002; these tests assert on persisted rows,
        // not on what one tenant can see, so they read across tenants deliberately.
        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->user->assignRole($role);
    }

    public function test_storing_a_key_encrypts_it_and_never_returns_the_secret(): void
    {
        app(TenantContext::class)->forget();

        $secret = 'sk-supersecret-1234';
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/ai/credentials', [
            'provider' => 'openai',
            'credential_scope' => 'tenant',
            'secret' => $secret,
        ])->assertCreated();

        // The API response must not leak the secret, only a masked hint.
        $this->assertStringNotContainsString($secret, $response->getContent());
        $response->assertJsonPath('data.masked_key', '••••1234');
        $response->assertJsonMissingPath('data.encrypted_secret');
        $response->assertJsonMissingPath('data.secret');

        // At rest it is encrypted (the raw column is not the plaintext secret).
        $stored = DB::table('ai_provider_credentials')->value('encrypted_secret');
        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, (string) $stored);

        // But it can be decrypted server-side for real use.
        $this->assertSame($secret, AIProviderCredential::first()->revealSecret());
    }

    public function test_index_returns_masked_keys_only(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $c = new AIProviderCredential(['provider' => 'anthropic', 'credential_scope' => 'tenant', 'status' => 'active']);
        $c->setSecret('anthropic-key-9999');
        $c->save();
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/ai/credentials')
            ->assertOk()
            ->assertJsonPath('data.0.masked_key', '••••9999')
            ->assertJsonMissingPath('data.0.encrypted_secret');
    }

    public function test_keys_are_isolated_between_tenants(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $mine = new AIProviderCredential(['provider' => 'openai', 'credential_scope' => 'tenant', 'status' => 'active']);
        $mine->setSecret('mine-0001');
        $mine->save();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $theirs = new AIProviderCredential(['provider' => 'openai', 'credential_scope' => 'tenant', 'status' => 'active']);
        $theirs->setSecret('theirs-0002');
        $theirs->save();

        app(TenantContext::class)->forget();

        // Our user sees exactly one key — ours.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/ai/credentials')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.masked_key', '••••0001');
    }

    public function test_storing_a_key_requires_ai_manage_permission(): void
    {
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@agency.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('ai.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/ai/credentials', [
            'provider' => 'openai', 'credential_scope' => 'tenant', 'secret' => 'nope-1234',
        ])->assertStatus(403);
    }
}
