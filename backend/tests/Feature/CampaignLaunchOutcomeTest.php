<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LAUNCH-SUCCESS-001 — a celebration may only ever describe something the server did.
 *
 * The premium launch moment in the UI renders exactly what these assertions prove is in the
 * response, and nothing else: an activation timestamp from the server's own clock, the platforms
 * that actually carry the campaign with the status each one reported, and an outcome that separates
 * «live everywhere» from «live here, not there». A pause carries no launch payload at all, so no
 * amount of client-side eagerness can turn one into a success screen.
 */
final class CampaignLaunchOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-launch', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Owner', 'slug' => 'owner-launch']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@launch.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-launch', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project', 'status' => 'active']);

        // `external_account_id` is NOT NULL — a platform campaign always belongs to an account.
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act-1', 'name' => 'Ad account', 'status' => 'active', 'discovered_at' => now(),
        ]);

        app(TenantContext::class)->forget();
    }

    public function test_activating_returns_the_servers_own_launch_evidence(): void
    {
        $campaign = $this->campaign(['status' => 'draft', 'name' => 'Riyadh spring push']);
        $before = Carbon::now()->subSecond();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->id}/activate")
            ->assertOk();

        $launch = $response->json('meta.launch');

        $this->assertSame('launched', $launch['outcome']);
        $this->assertSame((string) $campaign->id, $launch['campaign_id']);
        $this->assertSame((string) $this->project->id, $launch['project_id']);
        $this->assertSame('Riyadh spring push', $launch['name']);

        // The timestamp is the server's, it is persisted, and it belongs to this activation.
        $this->assertNotNull($launch['activated_at']);
        $this->assertTrue(Carbon::parse($launch['activated_at'])->greaterThanOrEqualTo($before));
        $this->assertNotNull($campaign->fresh()->activated_at);
        $this->assertSame($launch['activated_at'], $response->json('data.activated_at'));
    }

    public function test_a_platform_the_provider_has_not_turned_on_makes_the_launch_partial(): void
    {
        $campaign = $this->campaign(['status' => 'draft']);
        $this->external($campaign, ['provider' => 'meta', 'status' => 'active', 'name' => 'Meta — spring']);
        $this->external($campaign, ['provider' => 'google', 'status' => 'pending', 'name' => 'Google — spring']);

        $launch = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->id}/activate")
            ->assertOk()
            ->json('meta.launch');

        $this->assertSame('partial', $launch['outcome']);
        $this->assertSame(2, $launch['platforms_total']);
        $this->assertSame(1, $launch['platforms_live']);

        $byProvider = collect($launch['platforms'])->keyBy('provider');
        $this->assertTrue($byProvider['meta']['live']);
        $this->assertFalse($byProvider['google']['live']);
        // The reason travels with the row — «pending» is the thing the operator has to act on.
        $this->assertSame('pending', $byProvider['google']['status']);
    }

    public function test_every_platform_live_is_a_clean_launch(): void
    {
        $campaign = $this->campaign(['status' => 'paused']);
        $this->external($campaign, ['provider' => 'meta', 'status' => 'active']);
        $this->external($campaign, ['provider' => 'tiktok', 'status' => 'active']);

        $launch = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->id}/activate")
            ->assertOk()
            ->json('meta.launch');

        $this->assertSame('launched', $launch['outcome']);
        $this->assertSame(2, $launch['platforms_live']);
    }

    public function test_pausing_carries_no_launch_payload_to_celebrate(): void
    {
        $campaign = $this->campaign(['status' => 'active']);

        $meta = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->id}/pause")
            ->assertOk()
            ->json('meta');

        $this->assertArrayNotHasKey('launch', $meta);
    }

    /** @param array<string, mixed> $attributes */
    private function campaign(array $attributes = []): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Campaign '.uniqid(),
            'objective' => 'sales',
            'status' => 'draft',
            'budget_currency' => 'SAR',
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function external(UnifiedCampaign $campaign, array $attributes = []): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'unified_campaign_id' => $campaign->getKey(),
            'external_account_id' => $this->account->getKey(),
            'provider' => 'meta',
            'external_id' => 'ext-'.uniqid(),
            'name' => 'External '.uniqid(),
            'status' => 'active',
            ...$attributes,
        ]);
    }
}
