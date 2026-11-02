<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\MetaCandidate\MetaAppRouting;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * META-MULTI-APP-001 — per-connection issuing app, the read-only audit, the atomic reconnect and the app registry.
 * Every Meta call is faked; every credential below is a fixture.
 */
final class MetaMultiAppTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_ID = 'live-app-111111';

    private const LIVE_SECRET = 'live-secret-AAAAAAAAAAAA';

    // Public identifiers of the Owner's new app, used as fixtures only; the secret is fake.
    private const SAAS_ID = '2604968763258530';

    private const SAAS_CONFIG = '1138211818567426';

    private const SAAS_SECRET = 'saas-secret-CCCCCCCCCCCC';

    /** Every table a reconnect or an audit must leave alone, bar the one connection row it is allowed to change. */
    private const WATCHED = ['provider_connections', 'integration_credentials', 'external_accounts', 'project_integration_bindings', 'daily_metrics'];

    private User $owner;

    private User $platformOwner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'multi-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@multi.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $this->platformOwner = User::create(['name' => 'P', 'email' => 'p@multi.test', 'password' => 'secret123']);
        $this->platformOwner->forceFill(['is_platform_admin' => true])->save();

        config()->set('ad_platforms.platforms.meta.client_id', self::LIVE_ID);
        config()->set('ad_platforms.platforms.meta.client_secret', self::LIVE_SECRET);
    }

    // ── fixtures ───────────────────────────────────────────────────────────────────────────────

    private function configureSaas(): void
    {
        app(MetaSaasCredentials::class)->save(['client_id' => self::SAAS_ID, 'client_secret' => self::SAAS_SECRET, 'config_id' => self::SAAS_CONFIG]);
        app(MetaSaasCredentials::class)->forgetCache();
    }

    /** The SaaS app configured, tested, approved and chosen for new connections — what «set primary» leaves behind. */
    private function makeSaasPrimary(): void
    {
        $this->configureSaas();
        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->platformOwner->id, 'status' => 'succeeded',
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
        ProviderConfiguration::query()->where('provider', 'meta.saas')->update(['enabled' => true, 'review_confirmed_at' => now()]);
        app(MetaSaasCredentials::class)->forgetCache();
        $this->assertSame(MetaCredentialProfile::Saas, MetaAppRouting::default());
    }

    private function legacy(string $token = 'LEGACY-TOKEN', ?ClientWorkspace $workspace = null, ?Carbon $expires = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens($token, null, $expires ?? Carbon::now()->addDays(50)),
            connectionName: 'Meta Marketing API', clientWorkspaceId: $workspace?->id,
        );
    }

    private function workspace(): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
    }

    private function bind(ProviderConnection $connection, string $externalId): ExternalAccount
    {
        $workspace = $this->workspace();
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'currency' => 'USD', 'status' => 'active',
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => true,
        ]);

        return $account;
    }

    /** @return array<string, string> every watched table, whole, as JSON — a byte-for-byte comparison. */
    private function snapshot(): array
    {
        $out = [];
        foreach (self::WATCHED as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    /** @param list<string> $permissions @param list<string> $accounts */
    private function fakeMeta(array $permissions = ['ads_read', 'ads_management'], array $accounts = ['1'], string $debugAppId = self::SAAS_ID, bool $valid = true, int $exchangeStatus = 200): void
    {
        Http::fake([
            '*/oauth/access_token*' => $exchangeStatus === 200
                ? Http::response(['access_token' => 'NEW-TOKEN', 'expires_in' => 5184000])
                : Http::response(['error' => ['code' => 100, 'message' => 'bad code']], $exchangeStatus),
            '*/me/permissions*' => Http::response(['data' => array_map(static fn (string $p) => ['permission' => $p, 'status' => 'granted'], $permissions)]),
            '*/me/adaccounts*' => Http::response(['data' => array_map(static fn (string $a) => ['account_id' => $a, 'id' => 'act_'.$a], $accounts)]),
            '*/debug_token*' => Http::response(['data' => ['app_id' => $debugAppId, 'is_valid' => $valid]]),
            '*' => Http::response([], 404),
        ]);
    }

    /** @return array{0: array<string,mixed>, 1: string} the start response data and the state */
    private function startReconnect(ProviderConnection $connection): array
    {
        $data = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/connections/'.$connection->id.'/reconnect')
            ->assertOk()->json('data');
        parse_str((string) parse_url((string) $data['authorization_url'], PHP_URL_QUERY), $q);

        return [$data, (string) $q['state']];
    }

    private function returnWith(string $state, string $code = 'abc'): string
    {
        return (string) $this->get('/api/v1/oauth/ads/meta/callback?'.http_build_query(['state' => $state, 'code' => $code]))->assertRedirect()->headers->get('Location');
    }

    /** @return array<string,string> */
    private function outcome(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return $q;
    }

    // ── C: atomic reconnect ────────────────────────────────────────────────────────────────────

    public function test_starting_a_reconnect_writes_nothing_and_uses_the_primary_app(): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy();
        $this->bind($legacy, 'act_1');
        $before = $this->snapshot();

        [$data, $state] = $this->startReconnect($legacy);

        $this->assertSame($before, $this->snapshot(), 'start touched no row');
        $this->assertSame('saas', $data['app_profile']);
        $this->assertFalse($data['keeps_own_app']);
        parse_str((string) parse_url((string) $data['authorization_url'], PHP_URL_QUERY), $q);
        $this->assertSame(self::SAAS_ID, $q['client_id']);
        $this->assertSame(self::SAAS_CONFIG, $q['config_id']);
        $this->assertNotSame('', $state);
    }

    public function test_while_the_saas_app_is_not_primary_a_reconnect_keeps_the_connections_own_app_and_says_why(): void
    {
        $this->configureSaas();
        $legacy = $this->legacy();

        [$data] = $this->startReconnect($legacy);

        $this->assertSame('live', $data['app_profile']);
        $this->assertTrue($data['keeps_own_app']);
        $this->assertSame('no_successful_test', $data['reason']);
        parse_str((string) parse_url((string) $data['authorization_url'], PHP_URL_QUERY), $q);
        $this->assertSame(self::LIVE_ID, $q['client_id']);
    }

    public function test_a_successful_reconnect_replaces_the_token_and_app_on_the_same_row_and_touches_nothing_else(): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy();
        $this->bind($legacy, 'act_1');
        [, $state] = $this->startReconnect($legacy);
        $before = $this->snapshot();
        $this->fakeMeta(accounts: ['1', '2']);

        $result = $this->outcome($this->returnWith($state));

        $this->assertSame('reconnected', $result['outcome']);
        $row = ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id);
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->count(), 'no second connection row');
        $this->assertSame('saas', $row->app_profile);
        $this->assertSame(self::SAAS_ID, $row->issuing_app_id);
        $this->assertNotNull($row->issuing_app_verified_at);
        $this->assertSame('connected', $row->status);
        $this->assertSame('NEW-TOKEN', app(TokenVault::class)->stored($row)->accessToken);
        $after = $this->snapshot();
        foreach (['external_accounts', 'project_integration_bindings', 'daily_metrics'] as $table) {
            $this->assertSame($before[$table], $after[$table], "$table untouched");
        }
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'oauth/access_token') && str_contains($r->url().$r->body(), self::SAAS_SECRET) && ! str_contains($r->url().$r->body(), self::LIVE_SECRET));
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'debug_token'));
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() !== 'GET' && ! str_contains($r->url(), 'oauth/access_token'));
    }

    /** @return array<string, array{0: array<string,mixed>, 1: string}> */
    public static function verificationFailures(): array
    {
        return [
            'ads_read not granted' => [['permissions' => ['public_profile']], 'permissions_missing'],
            'a bound account is not listed' => [['accounts' => ['2']], 'accounts_missing'],
            'meta says another app issued it' => [['debugAppId' => self::LIVE_ID], 'app_mismatch'],
            'token not valid' => [['valid' => false], 'app_mismatch'],
            'code refused' => [['exchangeStatus' => 400], 'token_exchange_failed'],
        ];
    }

    /** @param array<string,mixed> $fake */
    #[\PHPUnit\Framework\Attributes\DataProvider('verificationFailures')]
    public function test_each_verification_failure_leaves_every_row_byte_identical_and_the_old_token_working(array $fake, string $code): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy();
        $this->bind($legacy, 'act_1');
        $this->bind($legacy, 'act_2');
        [, $state] = $this->startReconnect($legacy);
        $before = $this->snapshot();
        $this->fakeMeta(...$fake);
        if (isset($fake['accounts'])) {
            // act_1 AND act_2 are bound; the new token lists only act_2.
            $this->assertSame(['2'], $fake['accounts']);
        }

        $result = $this->outcome($this->returnWith($state));

        $this->assertSame('reconnect_failed', $result['outcome']);
        $this->assertSame($code, $result['reason']);
        $this->assertSame($before, $this->snapshot(), 'no row changed');
        $row = ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id);
        $this->assertNull($row->app_profile);
        $this->assertSame('LEGACY-TOKEN', app(TokenVault::class)->stored($row)->accessToken);
        $this->assertSame(self::LIVE_ID, PlatformCredentials::forConnection($row)->get('client_id'), 'it still syncs with its own, legacy app');
    }

    public function test_an_exception_after_the_token_was_written_rolls_everything_back(): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy();
        $this->bind($legacy, 'act_1');
        [, $state] = $this->startReconnect($legacy);
        $before = $this->snapshot();
        $this->fakeMeta();
        // The first connection save sets the app, the credential is then written, the SECOND connection save blows up.
        $saves = 0;
        ProviderConnection::saving(function () use (&$saves): void {
            if (++$saves === 2) {
                throw new RuntimeException('simulated failure mid-way');
            }
        });

        $result = $this->outcome($this->returnWith($state));

        $this->assertSame(2, $saves, 'the failure really happened after the credential write');
        $this->assertSame('reconnect_failed', $result['outcome']);
        $this->assertSame('reconnect_failed', $result['reason']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('LEGACY-TOKEN', app(TokenVault::class)->stored(ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id))->accessToken);
    }

    public function test_after_a_failed_reconnect_the_legacy_connection_still_refreshes_with_its_legacy_credentials(): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy('LEGACY-TOKEN', null, Carbon::now()->subMinute());
        [, $state] = $this->startReconnect($legacy);
        $this->fakeMeta(debugAppId: self::LIVE_ID);
        $this->returnWith($state);

        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'REFRESHED', 'expires_in' => 5184000])]);
        $fresh = app(TokenVault::class)->fresh(ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id));

        $this->assertSame('REFRESHED', $fresh->accessToken);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url().$r->body(), self::LIVE_SECRET) && ! str_contains($r->url().$r->body(), self::SAAS_SECRET));
    }

    public function test_reconnect_is_idempotent_and_a_replayed_or_tampered_state_changes_nothing(): void
    {
        $this->makeSaasPrimary();
        $legacy = $this->legacy();
        $this->bind($legacy, 'act_1');
        [, $state] = $this->startReconnect($legacy);
        $this->fakeMeta();
        $this->assertSame('reconnected', $this->outcome($this->returnWith($state))['outcome']);
        $once = $this->snapshot();

        // The same callback again: the state was single-use.
        $this->assertSame('invalid_state', $this->outcome($this->returnWith($state))['outcome']);
        $this->assertSame($once, $this->snapshot());

        // A new reconnect of the already-migrated connection ends in the same place, on the same row.
        [$data, $second] = $this->startReconnect(ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id));
        $this->assertSame('saas', $data['app_profile']);
        $this->assertSame('reconnected', $this->outcome($this->returnWith($second))['outcome']);
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->count());
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertSame(self::SAAS_ID, ProviderConnection::withoutGlobalScopes()->sole()->issuing_app_id);

        // A state whose target connection was swapped breaks its signature and is refused before anything is exchanged.
        [, $third] = $this->startReconnect(ProviderConnection::withoutGlobalScopes()->findOrFail($legacy->id));
        $key = 'ads-oauth-state:'.$third;
        $record = Cache::get($key);
        $record['reconnect_connection_id'] = (string) \Illuminate\Support\Str::uuid();
        Cache::put($key, $record, 600);
        $before = $this->snapshot();
        $this->assertSame('invalid_state', $this->outcome($this->returnWith($third))['outcome']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_reconnect_cannot_be_started_for_another_tenants_or_a_revoked_connection(): void
    {
        $this->makeSaasPrimary();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $foreign = app(TokenVault::class)->open(tenantId: (string) $other->id, provider: 'meta', tokens: new OAuthTokens('F', null, Carbon::now()->addDays(5)), connectionName: 'x');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/connections/'.$foreign->id.'/reconnect')->assertNotFound();

        $revoked = $this->legacy();
        $revoked->forceFill(['status' => 'revoked'])->save();
        $this->postJson('/api/v1/integrations/meta/connections/'.$revoked->id.'/reconnect')->assertStatus(422)->assertJsonPath('meta.status', 'connection_not_reconnectable');
    }

    // ── B: the audit ───────────────────────────────────────────────────────────────────────────

    /** @return array{0: ProviderConnection, 1: ProviderConnection, 2: ProviderConnection, 3: ProviderConnection} */
    private function auditFleet(): array
    {
        $this->configureSaas();
        $a = $this->legacy('TOKEN-MATCHES', $this->workspace());
        $b = $this->legacy('TOKEN-IS-SAAS', $this->workspace());
        $c = $this->legacy('TOKEN-EXPIRED', $this->workspace());
        $d = $this->legacy('TOKEN-UNREACHABLE', $this->workspace());
        Sleep::fake();
        Http::fake(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $token = $q['input_token'] ?? '';

            return match (true) {
                $token === 'TOKEN-MATCHES' => Http::response(['data' => ['app_id' => self::LIVE_ID, 'is_valid' => true]]),
                $token === 'TOKEN-IS-SAAS' => Http::response(['data' => ['app_id' => self::SAAS_ID, 'is_valid' => true]]),
                $token === 'TOKEN-EXPIRED' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 400),
                default => throw new ConnectionException('cURL error 28 for https://graph.facebook.com/debug_token?access_token='.self::LIVE_ID.'|'.self::LIVE_SECRET),
            };
        });

        return [$a, $b, $c, $d];
    }

    public function test_the_audit_default_is_strictly_read_only_and_makes_only_get_calls(): void
    {
        [$a, $b] = $this->auditFleet();
        $before = $this->snapshot();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate|grant|revoke)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        foreach ([[], ['--dry-run' => true]] as $options) {
            $code = Artisan::call('integrations:meta-app-audit', $options);
            $this->assertSame(0, $code);
        }

        $this->assertSame([], $writes, 'not one INSERT, UPDATE, DELETE or DDL statement');
        $this->assertSame($before, $this->snapshot(), 'byte-for-byte unchanged');
        $this->assertNotEmpty(Http::recorded());
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertStringNotContainsString('oauth/access_token', $request->url(), 'no refresh or extension');
        }
        $this->assertNull(ProviderConnection::withoutGlobalScopes()->find($a->id)->issuing_app_verified_at);
    }

    public function test_the_audit_reports_each_connection_with_a_verdict_and_never_prints_a_token_or_secret(): void
    {
        $this->auditFleet();

        Artisan::call('integrations:meta-app-audit');
        $output = Artisan::output();

        $this->assertStringContainsString('verified-matches-recorded', $output);
        $this->assertStringContainsString('verified-differs', $output);
        $this->assertSame(2, substr_count($output, 'unresolved') - 1 + 0, 'two unresolved rows (the summary line names the word once)');
        $this->assertStringContainsString('token_invalid_or_expired', $output);
        $this->assertStringContainsString('meta_unreachable', $output);
        $this->assertStringContainsString('matches: 1, differs: 1, unresolved: 2', $output);
        foreach (['TOKEN-MATCHES', 'TOKEN-IS-SAAS', 'TOKEN-EXPIRED', self::LIVE_SECRET, self::SAAS_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_apply_and_dry_run_together_is_an_error_that_does_nothing(): void
    {
        $this->auditFleet();
        $before = $this->snapshot();

        $this->assertSame(2, Artisan::call('integrations:meta-app-audit', ['--apply' => true, '--dry-run' => true]));

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(0, Http::recorded());
    }

    public function test_apply_records_only_verified_rows_one_at_a_time_and_leaves_unresolved_rows_and_tokens_alone(): void
    {
        [$a, $b, $c, $d] = $this->auditFleet();
        $before = $this->snapshot();
        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            if (preg_match('/^\s*update\b/i', $query->sql) === 1) {
                $updates[] = $query->sql;
            }
        });

        $this->assertSame(0, Artisan::call('integrations:meta-app-audit', ['--apply' => true]));

        $this->assertCount(2, $updates, 'one UPDATE per verified connection');
        foreach ($updates as $sql) {
            $this->assertStringContainsString('"provider_connections"', $sql);
            $this->assertStringContainsString('"issuing_app_id"', $sql);
            $this->assertStringContainsString('"issuing_app_verified_at"', $sql);
            $this->assertStringNotContainsString('"app_profile"', $sql);
        }
        $rows = ProviderConnection::withoutGlobalScopes()->get()->keyBy('id');
        $this->assertSame(self::LIVE_ID, $rows[$a->id]->issuing_app_id);
        $this->assertSame(self::SAAS_ID, $rows[$b->id]->issuing_app_id);
        $this->assertNotNull($rows[$a->id]->issuing_app_verified_at);
        foreach ([$c, $d] as $untouched) {
            $this->assertNull($rows[$untouched->id]->issuing_app_verified_at);
        }
        // Profiles and tokens are exactly as they were; only the two columns on the two verified rows differ.
        $after = $this->snapshot();
        $this->assertSame($before['integration_credentials'], $after['integration_credentials']);
        $this->assertNull($rows[$b->id]->app_profile);
        $this->assertNotSame($before['provider_connections'], $after['provider_connections']);

        // The verified issuer is now the source of truth for that connection's Graph calls.
        $this->assertSame(self::SAAS_ID, PlatformCredentials::forConnection($rows[$b->id])->get('client_id'));
        $this->assertSame(self::LIVE_ID, PlatformCredentials::forConnection($rows[$a->id])->get('client_id'));
        $this->assertSame(self::LIVE_ID, PlatformCredentials::forConnection($rows[$c->id])->get('client_id'));
    }

    public function test_the_audit_can_be_limited_to_one_connection(): void
    {
        [$a, $b] = $this->auditFleet();

        Artisan::call('integrations:meta-app-audit', ['--apply' => true, '--connection' => $b->id]);

        $this->assertNotNull(ProviderConnection::withoutGlobalScopes()->find($b->id)->issuing_app_verified_at);
        $this->assertNull(ProviderConnection::withoutGlobalScopes()->find($a->id)->issuing_app_verified_at);
    }

    // ── A: credentials follow the issuing app ──────────────────────────────────────────────────

    public function test_no_code_path_uses_a_global_meta_credential_for_a_stored_connection(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // `for('meta')` is the Live profile by accident of spelling; every Meta use names its app (`forMeta`) or its connection.
            if (preg_match('/PlatformCredentials::for\(\s*[\'"]meta[\'"]\s*\)/', $source) === 1 || str_contains($source, "ad_platforms.platforms.meta.client_secret")) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        // PlatformCredentials itself maps `forMeta(Live)` onto `for('meta')`.
        $this->assertSame(['app/Domains/Integrations/OAuth/PlatformCredentials.php'], $offenders);
    }

    public function test_a_saas_connection_syncs_and_refreshes_with_the_saas_app_and_a_legacy_one_with_legacy(): void
    {
        $this->configureSaas();
        $live = $this->legacy('L', null, Carbon::now()->subMinute());
        $saas = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('S', null, Carbon::now()->subMinute()),
            connectionName: 'SaaS', appProfile: MetaCredentialProfile::Saas,
        );
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'N', 'expires_in' => 5184000])]);

        app(TokenVault::class)->fresh($live);
        app(TokenVault::class)->fresh($saas);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url().$r->body(), self::LIVE_SECRET) && ! str_contains($r->url().$r->body(), self::SAAS_SECRET));
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url().$r->body(), self::SAAS_SECRET) && ! str_contains($r->url().$r->body(), self::LIVE_SECRET));
    }

    // ── D: registry and removal ────────────────────────────────────────────────────────────────

    public function test_the_registry_lists_every_app_with_role_public_id_and_verified_and_unverified_counts_and_no_secret(): void
    {
        $this->makeSaasPrimary();
        $unverified = $this->legacy('A', $this->workspace());
        $verified = $this->legacy('B', $this->workspace());
        $verified->forceFill(['issuing_app_id' => self::SAAS_ID, 'issuing_app_verified_at' => now()])->save();

        $response = $this->actingAs($this->platformOwner, 'sanctum')->getJson('/api/v1/admin/settings/integrations/meta-saas')->assertOk();
        $registry = $response->json('data.registry');
        $apps = collect($registry['apps'])->keyBy('profile');

        $this->assertSame('primary', $apps['saas']['role']);
        $this->assertSame(self::SAAS_ID, $apps['saas']['app_id']);
        $this->assertTrue($apps['saas']['configured']);
        $this->assertSame(1, $apps['saas']['connections']);
        $this->assertSame(1, $apps['saas']['verified_connections']);
        $this->assertSame(0, $apps['saas']['unverified_connections']);
        $this->assertSame('legacy_sync_only', $apps['live']['role']);
        $this->assertSame(1, $apps['live']['connections']);
        $this->assertSame(1, $apps['live']['unverified_connections'], 'the unverified row is counted, not hidden');
        $this->assertSame('test_only', $apps['candidate']['role']);
        $this->assertStringNotContainsString(self::SAAS_SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::LIVE_SECRET, $response->getContent());
        $this->assertNotNull($unverified);
    }

    public function test_an_app_with_connections_cannot_be_forgotten_and_one_without_can(): void
    {
        $this->configureSaas();
        $admin = $this->actingAs($this->platformOwner, 'sanctum');
        $saas = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('S', null, Carbon::now()->addDays(5)),
            connectionName: 'SaaS', appProfile: MetaCredentialProfile::Saas,
        );

        $admin->deleteJson('/api/v1/admin/settings/integrations/meta-saas')->assertStatus(409)->assertJsonPath('errors.app.0', 'connections_belong_to_app')->assertJsonPath('meta.guard.connections', 1);
        $admin->deleteJson('/api/v1/admin/settings/integrations/meta-saas/credentials/client_secret')->assertStatus(409);
        $admin->deleteJson('/api/v1/admin/settings/integrations/meta-saas/credentials/client_id')->assertStatus(409);
        $this->assertTrue(app(MetaSaasCredentials::class)->isConfigured(), 'nothing was cleared');

        $saas->forceFill(['status' => 'revoked'])->save();
        $admin->deleteJson('/api/v1/admin/settings/integrations/meta-saas')->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
        $this->assertFalse(app(MetaSaasCredentials::class)->isConfigured());
    }

    public function test_the_live_secret_cannot_be_cleared_while_live_connections_exist(): void
    {
        $this->legacy();

        $this->actingAs($this->platformOwner, 'sanctum')->deleteJson('/api/v1/admin/settings/integrations/providers/meta/credentials/client_secret')
            ->assertStatus(409)->assertJsonPath('errors.key.0', 'connections_belong_to_app');
    }

    // ── E / F ──────────────────────────────────────────────────────────────────────────────────

    public function test_with_saas_primary_a_new_connection_dialog_names_the_saas_app_and_with_live_default_it_is_unchanged(): void
    {
        $this->configureSaas();
        $live = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start')->assertOk();
        parse_str((string) parse_url((string) $live->json('data.authorization_url'), PHP_URL_QUERY), $liveQuery);
        $this->assertSame('live', $live->json('data.app_profile'));
        $this->assertSame(self::LIVE_ID, $liveQuery['client_id']);
        $this->assertArrayNotHasKey('config_id', $liveQuery, 'the Live dialog is exactly as it always was');

        $this->makeSaasPrimary();
        $saas = $this->postJson('/api/v1/integrations/meta/oauth/start')->assertOk();
        parse_str((string) parse_url((string) $saas->json('data.authorization_url'), PHP_URL_QUERY), $saasQuery);
        $this->assertSame('saas', $saas->json('data.app_profile'));
        $this->assertSame(self::SAAS_ID, $saasQuery['client_id']);
        $this->assertSame(self::SAAS_CONFIG, $saasQuery['config_id']);
    }

    public function test_the_new_code_never_deletes_revokes_or_mass_updates_a_connection(): void
    {
        foreach (['MetaAppAudit', 'MetaAtomicReconnect', 'MetaAppRegistry', 'MetaGraphProbe'] as $class) {
            $source = (string) file_get_contents(app_path('Domains/Integrations/MetaCandidate/'.$class.'.php'));
            $this->assertDoesNotMatchRegularExpression('/->delete\(|forceDelete|truncate\(/i', $source, $class);
        }
        // The audit's only writer targets one primary key.
        $source = (string) file_get_contents(app_path('Domains/Integrations/MetaCandidate/MetaAppAudit.php'));
        $this->assertSame(1, substr_count($source, '->update('));
        $this->assertStringContainsString('->whereKey($connection->getKey())->toBase()->update(', $source);
        $this->assertNotNull(AuthorizationState::class);
    }
}
