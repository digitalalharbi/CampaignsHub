<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\MetaCandidate\MetaAppRouting;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasModes;
use App\Domains\Integrations\MetaCandidate\MetaSaasTestWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * META-SAAS-APP-001 — Live / SaaS Test / SaaS Primary.
 *
 * Primary routes NEW connections to the SaaS app and moves nothing. It cannot be turned on until every precondition is met by
 * evidence (credentials, a real round trip, a real journey in the test workspace, Meta's approval, Live still configured) and the
 * Owner approves it by name; the preview of what it changes is shown first and recorded with it. Going back to Live touches new
 * connections only. And no change to either app's App ID may strand the connections it issued.
 */
final class MetaSaasModesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/settings/integrations/meta-saas';

    private const PRIMARY = ['default' => 'saas', 'confirm' => true, 'approve_primary' => true, 'acknowledge_existing_unchanged' => true];

    private User $admin;

    private Tenant $customer;

    private ProviderConnection $customerLive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Notification::fake();
        $this->admin = User::create(['name' => 'P', 'email' => 'p@modes.test', 'password' => 'secret123']);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        config()->set('ad_platforms.platforms.meta.client_id', 'live-app-111111');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAAAAAAAAAA');

        $this->customer = Tenant::create(['name' => 'Customer', 'slug' => 'customer-'.uniqid(), 'status' => 'active']);
        $this->customerLive = app(TokenVault::class)->open(
            tenantId: (string) $this->customer->id, provider: 'meta', tokens: new OAuthTokens('CUSTOMER-LIVE', null, Carbon::now()->addDays(50)), connectionName: 'Meta',
        );
    }

    private function admin(): static
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    private function configure(): void
    {
        $this->admin()->putJson(self::BASE, ['client_id' => 'saas-app-777', 'client_secret' => 'saas-secret-CCCC', 'config_id' => 'cfg-888'])->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
    }

    private function passRoundTrip(): void
    {
        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->admin->id, 'status' => 'succeeded',
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
    }

    /** A real journey's rows in the test workspace: a SaaS connection, a discovered and synced account, a metric and a report. */
    private function completeJourney(): ProviderConnection
    {
        $this->admin()->postJson(self::BASE.'/test-workspace', ['email' => 'tester@modes.test'])->assertCreated();
        $tenant = Tenant::query()->where('slug', MetaSaasTestWorkspace::SLUG)->firstOrFail();
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $tenant->id, provider: 'meta', tokens: new OAuthTokens('SAAS-TOKEN', null, Carbon::now()->addDays(50)),
            connectionName: 'Meta', appProfile: MetaCredentialProfile::Saas,
        );
        $account = ExternalAccount::create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Test account', 'status' => 'active',
        ]);
        $account->forceFill(['last_synced_at' => now()])->save();
        $project = Project::withoutGlobalScopes()->whereHas('clientWorkspace', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id))->firstOrFail();
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => (string) Str::uuid(), 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => now()->subDay()->toDateString(), 'value' => 0, 'project_currency' => 'SAR',
        ]);
        Report::create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'name' => 'Test report', 'type' => 'monthly', 'status' => 'completed',
            'currency' => 'SAR', 'audience' => 'client', 'period_start' => now()->subDays(7)->toDateString(), 'period_end' => now()->toDateString(),
        ]);
        app(TenantContext::class)->forget();

        return $connection;
    }

    private function recordMetaApproval(): void
    {
        $this->admin()->putJson(self::BASE.'/review', ['business_verified' => true, 'advanced_access_granted' => true])->assertOk();
    }

    /** @return array<string, bool> */
    private function preconditions(): array
    {
        return array_column($this->admin()->getJson(self::BASE)->assertOk()->json('data.modes.primary.preconditions'), 'met', 'key');
    }

    public function test_the_mode_is_live_then_saas_test_and_primary_stays_locked_until_every_precondition_is_met_by_evidence(): void
    {
        $this->admin()->getJson(self::BASE)->assertJsonPath('data.modes.current', 'live')->assertJsonPath('data.modes.primary.eligible', false);

        $this->configure();
        $this->passRoundTrip();
        $this->completeJourney();
        $this->admin()->getJson(self::BASE)->assertJsonPath('data.modes.current', 'saas_test');
        $this->assertSame(['meta_approval_recorded'], array_keys(array_filter($this->preconditions(), fn (bool $met) => ! $met)));

        $this->admin()->putJson(self::BASE.'/routing', self::PRIMARY)->assertStatus(409)
            ->assertJsonPath('errors.routing', ['meta_approval_recorded'])
            ->assertJsonPath('meta.preview.existing_connections_moved', 0);
        $this->assertSame(MetaCredentialProfile::Live, MetaAppRouting::default());

        $this->recordMetaApproval();
        $this->admin()->getJson(self::BASE)->assertJsonPath('data.modes.primary.eligible', true);
    }

    public function test_a_passed_test_and_meta_approval_are_not_enough_without_a_real_journey(): void
    {
        $this->configure();
        $this->passRoundTrip();
        $this->recordMetaApproval();

        $this->admin()->putJson(self::BASE.'/routing', self::PRIMARY)->assertStatus(409)->assertJsonPath('errors.routing', ['test_journey_complete']);
        $this->assertSame(MetaCredentialProfile::Live, MetaAppRouting::default());
    }

    public function test_primary_needs_the_owners_named_approval_and_moves_no_existing_connection(): void
    {
        $this->configure();
        $this->passRoundTrip();
        $saasConnection = $this->completeJourney();
        $this->recordMetaApproval();
        $before = [$this->customerLive->fresh()->toArray(), $saasConnection->fresh()->toArray()];

        $this->admin()->putJson(self::BASE.'/routing', ['default' => 'saas', 'confirm' => true])->assertStatus(422)
            ->assertJsonValidationErrors(['approve_primary', 'acknowledge_existing_unchanged']);

        $this->admin()->putJson(self::BASE.'/routing', self::PRIMARY)->assertOk()
            ->assertJsonPath('data.modes.current', 'saas_primary')
            ->assertJsonPath('data.modes.primary.preview.existing_live_connections', 1)
            ->assertJsonPath('data.modes.primary.preview.existing_connections_moved', 0);
        $this->assertSame(MetaCredentialProfile::Saas, MetaAppRouting::default());

        // Nothing existing changed app, token or anything else; the Live connection still refreshes with Live.
        $this->assertSame($before, [$this->customerLive->fresh()->toArray(), $saasConnection->fresh()->toArray()]);
        $this->assertSame('CUSTOMER-LIVE', app(TokenVault::class)->stored($this->customerLive->fresh())->accessToken);
        $this->assertSame('live-app-111111', PlatformCredentials::forConnection($this->customerLive->fresh())->get('client_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta_saas.primary_enabled']);

        // Going back affects new connections only: the SaaS connection made meanwhile stays with the SaaS app.
        $this->admin()->putJson(self::BASE.'/routing', ['default' => 'live', 'confirm' => true])->assertOk()->assertJsonPath('data.modes.current', 'saas_test');
        $this->assertSame(MetaCredentialProfile::Live, MetaAppRouting::default());
        $this->assertSame('saas', $saasConnection->fresh()->app_profile);
        $this->assertSame('saas-app-777', PlatformCredentials::forConnection($saasConnection->fresh())->get('client_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta_saas.primary_reverted']);
    }

    public function test_primary_is_refused_if_live_is_no_longer_configured_because_its_connections_would_stop_syncing(): void
    {
        $this->configure();
        $this->passRoundTrip();
        $this->completeJourney();
        $this->recordMetaApproval();
        config()->set('ad_platforms.platforms.meta.client_id', null);
        app(ProviderConfigurationService::class)->forgetCache();

        $this->admin()->putJson(self::BASE.'/routing', self::PRIMARY)->assertStatus(409)->assertJsonPath('errors.routing', ['live_still_configured']);
    }

    public function test_the_saas_app_id_cannot_change_under_connections_it_issued(): void
    {
        $this->configure();
        $this->passRoundTrip();
        $saasConnection = $this->completeJourney();
        $this->assertSame('saas-app-777', $saasConnection->fresh()->issuing_app_id);

        $this->admin()->putJson(self::BASE, ['client_id' => 'another-app-999'])->assertStatus(409)
            ->assertJsonPath('errors.client_id.0', 'connections_issued_by_current_app')
            ->assertJsonPath('meta.guard.affected', 1);
        $this->admin()->deleteJson(self::BASE.'/credentials/client_id')->assertStatus(409);
        app(MetaSaasCredentials::class)->forgetCache();
        $this->assertSame('saas-app-777', app(MetaSaasCredentials::class)->value('client_id'));

        // The secret of the same app may still rotate: it strands nothing.
        $this->admin()->putJson(self::BASE, ['client_secret' => 'rotated-secret-DDDD'])->assertOk();
    }

    public function test_the_live_app_id_cannot_be_changed_from_the_provider_console_under_its_connections(): void
    {
        $this->admin()->putJson('/api/v1/admin/settings/integrations/providers/meta', ['client_id' => 'another-app-999'])->assertStatus(409)
            ->assertJsonPath('errors.client_id.0', 'connections_issued_by_current_app')
            ->assertJsonPath('meta.guard.affected', 1);
        $this->admin()->postJson('/api/v1/admin/settings/integrations/providers/meta/rotate', ['key' => 'client_id', 'value' => 'another-app-999'])->assertStatus(409);
        app(ProviderConfigurationService::class)->forgetCache();
        $this->assertSame('live-app-111111', PlatformCredentials::forMeta(MetaCredentialProfile::Live)->get('client_id'));
        $this->assertSame('connected', $this->customerLive->fresh()->status);
    }

    public function test_the_modes_name_their_preconditions_in_a_fixed_order(): void
    {
        $this->assertSame(
            ['credentials_configured', 'round_trip_passed', 'test_journey_complete', 'meta_approval_recorded', 'live_still_configured'],
            array_keys($this->preconditions()),
        );
        $this->assertSame(MetaSaasModes::REQUIRED_JOURNEY, ['oauth', 'discovery', 'sync', 'metrics', 'reports']);
    }
}
