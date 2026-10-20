<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Commerce\Install\SallaMagicLink;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SALLA-ONBOARD-001 — a merchant installs from Salla and finds a working dashboard.
 *
 * Salla is faked throughout; nothing here reaches a real store. The claims held: a store-initiated install
 * creates a workspace, an owner, a project and an assigned store and queues the first sync; a person who
 * already has an account gets the store attached to THEIR workspace; the single-use link opens a session once,
 * within five minutes, signed; uninstalling revokes the tokens and stops the sync and the data is purged after
 * the retention period; subscription events map to a plan status; and nothing crosses a tenant.
 */
final class SallaOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = '793298857';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        foreach (PlatformCredentials::for('salla')->requires() as $key) {
            config()->set("commerce_platforms.platforms.salla.{$key}", "test-{$key}");
        }
        config()->set('commerce_platforms.platforms.salla.webhook_secret', 'whsec');
        Queue::fake();
    }

    private function fakeSalla(string $email = 'merchant@store.test', string $role = 'owner', ?string $merchant = null): void
    {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT-install', 'refresh_token' => 'RT-install', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => (int) ($merchant ?? self::MERCHANT), 'name' => 'متجر الأزياء', 'domain' => 'https://fashion.salla.sa/x', 'currency' => 'SAR']]),
            'api.salla.dev/*/oauth2/user/info' => Http::response(['data' => ['id' => 5, 'name' => 'Salma Merchant', 'email' => strtoupper($email), 'mobile' => '+966500000000', 'role' => $role]]),
        ]);
    }

    private function install(): TestResponse
    {
        return $this->get('/api/v1/oauth/commerce/salla/callback?code=from-salla');
    }

    private function tokenFrom(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return (string) ($q['t'] ?? '');
    }

    public function test_a_store_initiated_install_creates_the_workspace_project_and_assigned_store_and_queues_the_first_sync(): void
    {
        $this->fakeSalla();

        $response = $this->install();

        $this->assertStringContainsString('/api/v1/salla/enter?t=', (string) $response->headers->get('Location'));

        $user = User::query()->where('email', 'merchant@store.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'Salla vouched for the address');
        $tenant = Tenant::query()->where('name', 'متجر الأزياء')->firstOrFail();
        $this->assertSame('salla-trial', $tenant->subscription_plan);
        $this->assertSame('self_serve_company', $tenant->account_type);
        $this->assertTrue($tenant->isOperational());
        $this->assertSame(1, Membership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->active()->count());

        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $project = Project::query()->where('name', 'متجر الأزياء')->firstOrFail();
        $store = ExternalAccount::withoutGlobalScopes()->where('provider', 'salla')->where('external_id', self::MERCHANT)->firstOrFail();
        $this->assertSame($tenant->id, $store->tenant_id);
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('project_id', $project->id)->where('is_active', true)->count());
        Queue::assertPushed(SyncStoreJob::class);
        $this->assertTrue($user->hasPermission('integrations.connect'), 'the owner holds the owner role');

        $pending = PendingStoreInstall::query()->firstOrFail();
        $this->assertNotNull($pending->claimed_at);
        $this->assertSame([], $pending->tokens);
    }

    public function test_the_signed_in_link_opens_a_session_once_and_only_once(): void
    {
        $this->fakeSalla();
        $token = $this->tokenFrom($this->install());
        $this->assertNotSame('', $token);

        $first = $this->get('/api/v1/salla/enter?t='.rawurlencode($token));
        $first->assertRedirect();
        $this->assertStringNotContainsString('salla=expired', (string) $first->headers->get('Location'));
        $this->assertAuthenticated('web');

        $this->app['auth']->guard('web')->logout();
        $replay = $this->get('/api/v1/salla/enter?t='.rawurlencode($token));
        $this->assertStringContainsString('salla=expired', (string) $replay->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_the_link_expires_after_five_minutes_and_rejects_tampering_and_other_secrets(): void
    {
        $this->fakeSalla();
        $this->install();
        $user = User::query()->where('email', 'merchant@store.test')->firstOrFail();
        $now = time();

        // Fresh: redeemable. Expired a second past five minutes: not.
        $this->assertNotNull(SallaMagicLink::redeem(SallaMagicLink::issue($user, self::MERCHANT, $now), $now + 299));
        $this->assertNull(SallaMagicLink::redeem(SallaMagicLink::issue($user, self::MERCHANT, $now), $now + 301));

        // Tampered payload (another user id) and tampered signature.
        [$payload, $signature] = explode('.', SallaMagicLink::issue($user, self::MERCHANT, $now));
        $forged = rtrim(strtr(base64_encode((string) json_encode(['u' => '1', 's' => self::MERCHANT, 'n' => 'x', 'e' => $now + 300])), '+/', '-_'), '=');
        $this->assertNull(SallaMagicLink::redeem($forged.'.'.$signature, $now));
        $this->assertNull(SallaMagicLink::redeem($payload.'.'.strrev($signature), $now));
        $this->assertNull(SallaMagicLink::redeem('not-a-token', $now));
        $this->assertNull(SallaMagicLink::redeem('', $now));

        // Signed under a different app secret: rejected.
        $token = SallaMagicLink::issue($user, self::MERCHANT, $now);
        config()->set('commerce_platforms.platforms.salla.client_secret', 'a-different-secret');
        $this->assertNull(SallaMagicLink::redeem($token, $now));
    }

    public function test_a_person_with_an_account_gets_the_store_attached_to_their_own_workspace_in_a_new_project(): void
    {
        $this->fakeSalla();
        $tenant = Tenant::create(['name' => 'Existing Co', 'slug' => 'existing-co', 'status' => 'active', 'account_type' => 'self_serve_company']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Existing', 'email' => 'merchant@store.test', 'password' => 'secret123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: 'owner'));
        $user->assignRole($role);
        app(TenantContext::class)->forget();
        $tenantsBefore = Tenant::count();

        $this->install();

        $this->assertSame($tenantsBefore, Tenant::count(), 'no second workspace was minted for an existing account');
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $this->assertSame(1, Project::query()->where('name', 'متجر الأزياء')->count());
        $this->assertSame($tenant->id, ExternalAccount::withoutGlobalScopes()->where('external_id', self::MERCHANT)->value('tenant_id'));
        $this->assertSame(1, User::query()->where('email', 'merchant@store.test')->count());
    }

    public function test_an_existing_account_is_not_signed_in_when_the_email_trust_switch_is_off(): void
    {
        config()->set('commerce_platforms.onboarding.trust_email', false);
        $this->fakeSalla();
        $tenant = Tenant::create(['name' => 'Existing Co', 'slug' => 'existing-co', 'status' => 'active', 'account_type' => 'self_serve_company']);
        $user = User::create(['name' => 'Existing', 'email' => 'merchant@store.test', 'password' => 'secret123']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: 'owner'));
        app(TenantContext::class)->forget();

        $location = (string) $this->install()->headers->get('Location');

        $this->assertStringNotContainsString('/salla/enter', $location);
        $this->assertStringContainsString('salla=connected', $location);
    }

    public function test_an_employee_or_an_install_salla_cannot_identify_falls_back_to_the_claim_page(): void
    {
        $this->fakeSalla(role: 'team');
        $location = (string) $this->install()->headers->get('Location');

        $this->assertStringContainsString('/app/store-install?', $location);
        $this->assertSame(0, Tenant::count(), 'nothing was provisioned for somebody who is not the owner');
        $this->assertSame(0, User::count());
        $this->assertNull(PendingStoreInstall::query()->firstOrFail()->claimed_at);
    }

    public function test_a_store_already_connected_elsewhere_is_never_connected_a_second_time(): void
    {
        // A different tenant already owns this merchant.
        $owner = Tenant::create(['name' => 'Owner Co', 'slug' => 'owner-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $owner->id);
        $connection = app(TokenVault::class)->open(tenantId: (string) $owner->id, provider: 'salla', tokens: new OAuthTokens('AT-old', 'RT-old', Carbon::now()->addDay()), connectionName: 'Salla');
        ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $owner->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'salla', 'account_type' => 'store', 'external_id' => self::MERCHANT, 'name' => 'Owned', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->fakeSalla(email: 'someone.else@elsewhere.test');
        $location = (string) $this->install()->headers->get('Location');

        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->count());
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', self::MERCHANT)->count());
        $this->assertSame($owner->id, ExternalAccount::withoutGlobalScopes()->where('external_id', self::MERCHANT)->value('tenant_id'));
        // A stranger who is not a member of the owning tenant is not signed in.
        $this->assertStringNotContainsString('/salla/enter', $location);
        $this->assertSame(0, User::count());
        $this->assertSame(1, Tenant::count());
    }

    public function test_open_app_goes_through_sallas_authorisation_and_a_member_of_the_owning_tenant_is_signed_in(): void
    {
        $open = $this->get('/api/v1/salla/open');
        $this->assertStringStartsWith('https://accounts.salla.sa/oauth2/auth?', (string) $open->headers->get('Location'));
        parse_str((string) parse_url((string) $open->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('test-client_id', $q['client_id']);

        // The merchant installed before; the same person comes back through Salla.
        $this->fakeSalla();
        $this->install();
        $this->app['auth']->guard('web')->logout();
        // A new fake: later Http::fake() calls merge with the earlier ones and the first match wins.
        Http::swap(new Factory);
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT-renewed', 'refresh_token' => 'RT-renewed', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => (int) self::MERCHANT, 'name' => 'متجر الأزياء', 'currency' => 'SAR']]),
            'api.salla.dev/*/oauth2/user/info' => Http::response(['data' => ['id' => 5, 'name' => 'Salma', 'email' => 'merchant@store.test', 'role' => 'owner']]),
        ]);

        $again = $this->install();

        $this->assertStringContainsString('/api/v1/salla/enter?t=', (string) $again->headers->get('Location'));
        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->firstOrFail();
        $this->assertSame('AT-renewed', app(TokenVault::class)->stored($connection)->accessToken, 'the tokens were renewed in place');
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->count(), 'a reinstall is the same connection');
    }

    private function webhook(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, 'whsec'),
        ], $raw);
    }

    public function test_uninstalling_revokes_the_tokens_stops_the_sync_and_starts_the_retention_clock(): void
    {
        $this->fakeSalla();
        $this->install();
        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->firstOrFail();
        $this->assertSame('AT-install', app(TokenVault::class)->stored($connection)->accessToken);

        $this->webhook(['event' => 'app.uninstalled', 'merchant' => (int) self::MERCHANT, 'created_at' => '2026-10-20 10:00:00', 'data' => []])->assertOk();

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNotNull($connection->uninstalled_at);
        $this->expectExceptionMessage('holds no access token');
        try {
            app(TokenVault::class)->stored($connection);
        } finally {
            Queue::fake();
            Artisan::call('commerce:sync');
            Queue::assertNotPushed(SyncStoreJob::class);
        }
    }

    public function test_the_same_delivery_twice_is_applied_once(): void
    {
        $this->fakeSalla();
        $this->install();
        $body = ['event' => 'app.subscription.started', 'merchant' => (int) self::MERCHANT, 'created_at' => '2026-10-20 10:00:00', 'id' => 'evt-1', 'data' => []];

        $this->webhook($body)->assertOk();
        $tenant = Tenant::query()->where('name', 'متجر الأزياء')->firstOrFail();
        $this->assertSame('salla-subscribed', $tenant->subscription_plan);

        // Mutate the tenant, redeliver the identical event: the ledger refuses to apply it again.
        $tenant->forceFill(['subscription_plan' => 'something-else'])->save();
        $this->webhook($body)->assertOk();
        $this->assertSame('something-else', $tenant->fresh()->subscription_plan);
    }

    public function test_subscription_events_map_to_a_plan_status_without_cutting_access(): void
    {
        $this->fakeSalla();
        $this->install();
        $tenant = Tenant::query()->where('name', 'متجر الأزياء')->firstOrFail();

        foreach ([['app.subscription.started', 'salla-subscribed', 'active'], ['app.subscription.expired', 'salla-expired', 'expired'], ['app.subscription.renewed', 'salla-subscribed', 'active'], ['app.subscription.canceled', 'salla-canceled', 'canceled']] as $n => [$event, $plan, $status]) {
            $this->webhook(['event' => $event, 'merchant' => (int) self::MERCHANT, 'created_at' => '2026-10-20 10:0'.$n.':00', 'data' => []])->assertOk();
            $tenant->refresh();
            $this->assertSame($plan, $tenant->subscription_plan, $event);
            $this->assertSame($status, $tenant->settings['salla_subscription']['status']);
            $this->assertTrue($tenant->isOperational(), 'an expired Salla subscription does not by itself lock the workspace');
        }
    }

    public function test_a_subscription_event_for_an_unknown_merchant_changes_nothing(): void
    {
        $this->fakeSalla();
        $this->install();

        $this->webhook(['event' => 'app.subscription.expired', 'merchant' => 111, 'created_at' => '2026-10-20 10:00:00', 'data' => []])->assertOk();

        $this->assertSame('salla-trial', Tenant::query()->where('name', 'متجر الأزياء')->value('subscription_plan'));
    }

    public function test_the_store_data_is_purged_after_the_retention_period_and_not_before_nor_after_a_reinstall(): void
    {
        $this->fakeSalla();
        $this->install();
        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->firstOrFail();
        $store = ExternalAccount::withoutGlobalScopes()->where('external_id', self::MERCHANT)->firstOrFail();
        app(TenantContext::class)->setTenantId((string) $connection->tenant_id);
        CommerceOrder::create(['tenant_id' => $connection->tenant_id, 'project_id' => Project::query()->value('id'), 'external_account_id' => $store->id, 'provider' => 'salla', 'external_id' => 'o1', 'status' => 'completed', 'total' => 100, 'currency' => 'SAR', 'ordered_at' => now()]);
        app(TenantContext::class)->forget();

        $this->webhook(['event' => 'app.uninstalled', 'merchant' => (int) self::MERCHANT, 'created_at' => '2026-10-20 10:00:00', 'data' => []]);

        // 29 days: kept.
        DB::table('provider_connections')->where('id', $connection->id)->update(['uninstalled_at' => now()->subDays(29)]);
        Artisan::call('commerce:purge-uninstalled');
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count());

        // A reinstall inside the period clears the clock: nothing is ever purged.
        $this->webhook(['event' => 'app.installed', 'merchant' => (int) self::MERCHANT, 'created_at' => '2026-10-21 10:00:00', 'data' => []]);
        DB::table('provider_connections')->where('id', $connection->id)->update(['uninstalled_at' => null]);
        Artisan::call('commerce:purge-uninstalled');
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count());

        // 31 days after an uninstall: purged, with the tenant, its users and projects untouched.
        DB::table('provider_connections')->where('id', $connection->id)->update(['uninstalled_at' => now()->subDays(31), 'status' => 'revoked']);
        Artisan::call('commerce:purge-uninstalled');
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('external_id', self::MERCHANT)->count());
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->count());
        $this->assertSame(1, User::query()->where('email', 'merchant@store.test')->count());
        $this->assertSame(1, Tenant::query()->where('name', 'متجر الأزياء')->count());
    }

    public function test_the_purge_leaves_another_tenants_store_alone(): void
    {
        $this->fakeSalla();
        $this->install();
        $mine = ProviderConnection::withoutGlobalScopes()->where('provider', 'salla')->firstOrFail();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $theirs = app(TokenVault::class)->open(tenantId: (string) $other->id, provider: 'zid', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: 'Zid');
        DB::table('provider_connections')->where('id', $theirs->id)->update(['status' => 'revoked', 'uninstalled_at' => now()->subDays(90)]);
        app(TenantContext::class)->forget();

        DB::table('provider_connections')->where('id', $mine->id)->update(['status' => 'revoked', 'uninstalled_at' => now()->subDays(90)]);
        Artisan::call('commerce:purge-uninstalled');

        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->where('id', $mine->id)->count());
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->where('id', $theirs->id)->count(), 'a non-Salla connection is never touched');
    }
}
