<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\AI\Models\PlatformAiKey;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Usage\UsageMeter;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * AI-PLATFORM-KEY-001 — the platform key: write-only in the admin console, used only when a workspace has no key of its own,
 * and counted per person against the plan's allowance.
 */
final class PlatformAiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-ant-platform-test-0000000000000000wxyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private ?User $admin = null;

    private function admin(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }
        $user = User::create(['name' => 'Owner', 'email' => 'owner@platform-ai.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $this->admin = $user->refresh();
    }

    private function project(): Project
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_only_the_platform_owner_reaches_it(): void
    {
        $customer = User::create(['name' => 'C', 'email' => 'c@platform-ai.test', 'password' => 'secret1234']);

        $this->getJson('/api/v1/admin/settings/ai')->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/settings/ai')->assertForbidden();
    }

    public function test_the_secret_is_stored_encrypted_and_never_returned(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/ai/anthropic', ['secret' => self::SECRET, 'enabled' => true])
            ->assertOk();

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertSame('••••wxyz', $response->json('data.providers.0.key'));
        $this->assertSame('anthropic', $response->json('data.active_provider'));

        $stored = (string) DB::table('platform_ai_keys')->where('provider', 'anthropic')->value('encrypted_secret');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertSame(self::SECRET, PlatformAiKey::active()?->revealSecret());
    }

    public function test_a_key_cannot_be_switched_on_before_it_is_stored(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/ai/openai', ['enabled' => true])
            ->assertStatus(422);
    }

    public function test_a_workspace_with_no_key_uses_the_platform_key_and_without_it_is_refused(): void
    {
        $project = $this->project();

        try {
            app(ModelClientFactory::class)->for($project, 'analyst');
            $this->fail('expected no_ai_credential');
        } catch (RuntimeException $e) {
            $this->assertSame('no_ai_credential', $e->getMessage());
        }

        $key = new PlatformAiKey(['provider' => 'anthropic', 'enabled' => true]);
        $key->setSecret(self::SECRET);
        $key->save();

        $picked = app(ModelClientFactory::class)->for($project, 'analyst');
        $this->assertTrue($picked['platform'] ?? false);
        $this->assertSame('platform', $picked['credential_id']);
        $this->assertSame('anthropic', $picked['provider']);
    }

    public function test_the_allowance_is_set_per_plan_and_audited(): void
    {
        SubscriptionPlan::query()->firstOrCreate(['code' => 'team'], ['name' => 'Team', 'name_ar' => 'Team']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/ai/allowance', ['plans' => ['team' => 120]])
            ->assertOk();

        $this->assertSame(120, UsageMeter::query()->where('code', 'ai_messages_platform')->firstOrFail()->includedFor('team'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.ai_allowance.updated']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/ai/allowance', ['plans' => ['no_such_plan' => 5]])
            ->assertStatus(422);
    }
}
