<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Services\ProviderIdentity;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-PROVIDER-IDENTITY-001 — the hub names the PROVIDER login, not the workspace user who pressed connect.
 */
final class ProviderIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'Agency Admin', 'email' => 'agency@workspace.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_google_records_the_login_email_from_userinfo(): void
    {
        Http::fake(['openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => '1', 'email' => 'buyer@gmail.test', 'name' => 'Media Buyer'])]);
        $c = $this->connection('google');

        $this->assertSame('recorded', app(ProviderIdentity::class)->capture($c, new OAuthTokens('tok')));

        $c->refresh();
        $this->assertSame('buyer@gmail.test', $c->provider_identity_email);
        $this->assertSame('Media Buyer', $c->provider_identity_label);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_meta_without_the_email_permission_records_the_name_only(): void
    {
        Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => '9', 'name' => 'Sara Meta'])]);
        $c = $this->connection('meta');

        app(ProviderIdentity::class)->capture($c, new OAuthTokens('tok'));

        $c->refresh();
        $this->assertSame('Sara Meta', $c->provider_identity_label);
        $this->assertNull($c->provider_identity_email);
    }

    public function test_snapchat_records_display_name_and_email_from_me(): void
    {
        Http::fake(['adsapi.snapchat.com/v1/me' => Http::response(['request_status' => 'SUCCESS', 'me' => ['display_name' => 'Snap Person', 'email' => 'snap@person.test']])]);
        $c = $this->connection('snapchat');

        app(ProviderIdentity::class)->capture($c, new OAuthTokens('tok'));

        $this->assertSame('snap@person.test', $c->fresh()->provider_identity_email);
        $this->assertSame('Snap Person', $c->fresh()->provider_identity_label);
    }

    public function test_a_refusal_records_nothing_and_is_not_retried(): void
    {
        Http::fake(['openidconnect.googleapis.com/v1/userinfo' => Http::response(['error' => 'insufficient_scope'], 403)]);
        $c = $this->connection('google');

        $this->assertSame('refused', app(ProviderIdentity::class)->capture($c, new OAuthTokens('tok')));

        $this->assertNull($c->fresh()->provider_identity_email);
        Http::assertSentCount(1);
    }

    public function test_tiktok_and_x_have_no_documented_identity_call_and_make_none(): void
    {
        $c = $this->connection('tiktok');

        $this->assertSame('unsupported', app(ProviderIdentity::class)->capture($c, new OAuthTokens('tok')));
        Http::assertNothingSent();
    }

    public function test_an_api_key_connection_shows_the_key_s_account_name(): void
    {
        $c = $this->connection('openai_ads');
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $c->id, 'provider' => 'openai_ads',
            'external_id' => 'acc_1', 'name' => 'Acme Ads Account', 'account_type' => 'ad_account', 'status' => 'active',
        ]);

        app(ProviderIdentity::class)->capture($c, new OAuthTokens('key'));

        $this->assertSame('Acme Ads Account', $c->fresh()->provider_identity_label);
        Http::assertNothingSent();
    }

    public function test_the_hub_gives_the_provider_account_and_keeps_the_workspace_user_secondary(): void
    {
        $known = $this->connection('google', createdBy: $this->user->id);
        $known->forceFill(['provider_identity_email' => 'buyer@gmail.test', 'provider_identity_label' => 'Media Buyer'])->save();
        $unknown = $this->connection('snapchat', createdBy: $this->user->id);

        $rows = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations/hub')->assertOk()->json('data.connections'))->keyBy('id');

        $this->assertSame('buyer@gmail.test', $rows[(string) $known->id]['provider_account']['email']);
        $this->assertSame('agency@workspace.test', $rows[(string) $known->id]['authorised_by']['email']);
        $this->assertNull($rows[(string) $unknown->id]['provider_account'], 'unknown is null, never the workspace email');
    }

    public function test_the_refresh_command_is_a_dry_run_unless_applied(): void
    {
        Http::fake(['openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'x@gmail.test'])]);
        $c = $this->connection('google');

        $this->artisan('integrations:identity-refresh')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull($c->fresh()->provider_identity_email);

        $this->artisan('integrations:identity-refresh --apply --provider=google')->assertSuccessful();
        $this->assertSame('x@gmail.test', $c->fresh()->provider_identity_email);
    }

    private function connection(string $provider, ?int $createdBy = null): ProviderConnection
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->forceFill(['tenant_id' => $this->tenant->id]);
        $credential->setPayload(json_encode(['access_token' => 'tok', 'refresh_token' => null, 'expires_at' => null, 'scope' => null, 'raw' => []]));
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => ucfirst($provider), 'scope' => 'tenant_shared', 'status' => 'connected', 'scopes' => [], 'created_by' => $createdBy,
        ]);
    }
}
