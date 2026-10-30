<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Integrations\Support\ProviderSessionInvalidation;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-SESSION-190-001 — a token Meta has invalidated is a connection that needs reconnecting, not a sync that «failed».
 *
 * Production, 2026-10-25 → 2026-10-30: two accounts bound to «Project 1» failed every half-hour sweep with
 *
 *     Error validating access token: The session has been invalidated because the user changed their password or Facebook has
 *     changed the session for security reasons. · code 190 · subcode 460
 *
 * while the connection went on saying «متصل». The only path that ever marked a connection `error` was a failed token REFRESH, and a
 * Meta long-lived token is never refreshed, so nobody was asked to reconnect for five days.
 *
 * Code 190 is Meta's «this token is no longer valid» (expired, password changed, app removed, security checkpoint): nothing but a new
 * consent fixes it. A rate limit (4, 17, 32, 613) or a transient error (1, 2) is fixed by waiting, and must never send anybody to
 * reconnect a working authorisation.
 */
final class MetaSessionInvalidatedTest extends TestCase
{
    use RefreshDatabase;

    private const INVALIDATED = ['error' => [
        'message' => 'Error validating access token: The session has been invalidated because the user changed their password or Facebook has changed the session for security reasons.',
        'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460, 'fbtrace_id' => 'AbC',
    ]];

    private const RATE_LIMITED = ['error' => [
        'message' => 'User request limit reached', 'type' => 'OAuthException', 'code' => 17, 'error_subcode' => 2446079, 'fbtrace_id' => 'XyZ',
    ]];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        config()->set('ad_platforms.platforms.meta.client_id', 'live-id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret');
    }

    public function test_only_metas_code_190_is_an_invalidated_session(): void
    {
        $this->assertTrue(ProviderSessionInvalidation::invalidatedBy('meta', 'Error validating access token: Session has expired · code 190 · subcode 463 · trace A'));
        $this->assertTrue(ProviderSessionInvalidation::invalidatedBy('meta', '(#190) Error validating access token'));

        foreach ([
            'User request limit reached · code 17 · subcode 2446079',
            'Application request limit reached · code 4',
            'Calls to this api have exceeded the rate limit · code 613',
            'An unknown error has occurred · code 1',
            'Service temporarily unavailable · code 2',
            '(#200) Ad account owner has NOT grant ads_management or ads_read permission · code 200',
            'Something · code 1900',
            'Something · subcode 190',
            null,
        ] as $message) {
            $this->assertFalse(ProviderSessionInvalidation::invalidatedBy('meta', $message), (string) $message);
        }

        // Another platform's «190» means nothing here.
        $this->assertFalse(ProviderSessionInvalidation::invalidatedBy('snapchat', 'Bad token · code 190'));
    }

    public function test_a_190_on_the_metrics_sync_marks_the_connection_for_reconnecting_and_keeps_token_and_data(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');
        $payloadBefore = $this->credentialPayload($connection);
        $metricsBefore = DB::table('daily_metrics')->count();

        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame('failed', $run->status);
        $connection->refresh();
        $this->assertSame('error', $connection->status);
        $this->assertStringContainsString('code 190', (string) $connection->last_error);
        $this->assertSame('token_invalidated', $account->refresh()->last_sync_error_category);

        // Every surface reads it as «reconnect», not «sync failed».
        $this->assertSame(AccountHealth::REVOKED, app(AccountHealth::class)->for($account));
        $this->assertSame(ConnectionWizardState::USER_REAUTH_REQUIRED, app(ConnectionWizardState::class)->for($connection)['user_state']);

        // Nothing was deleted, and the stored token and credential are exactly what they were.
        $this->assertSame($payloadBefore, $this->credentialPayload($connection));
        $this->assertSame($metricsBefore, DB::table('daily_metrics')->count());
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->whereKey($account->id)->count());
    }

    public function test_a_190_on_the_structure_sync_marks_the_connection_too(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');

        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('error', $connection->refresh()->status);
    }

    public function test_a_rate_limit_leaves_the_connection_connected(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');

        Http::fake(['graph.facebook.com/*' => Http::response(self::RATE_LIMITED, 400)]);
        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(7), Carbon::now());
        app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('connected', $connection->refresh()->status);
        $this->assertNull($connection->last_error);
        $this->assertSame('provider_error', $account->refresh()->last_sync_error_category);
    }

    public function test_only_the_refused_connection_is_marked_and_no_other_customer_is_touched(): void
    {
        [$broken, $brokenAccount] = $this->boundAccount('act_1');

        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $foreign = app(TokenVault::class)->open(tenantId: (string) $other->id, provider: 'meta', tokens: new OAuthTokens('OTHER', null, Carbon::now()->addDays(50)), connectionName: 'Other');
        $sibling = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('SIB', null, Carbon::now()->addDays(50)), connectionName: 'Workspace', clientWorkspaceId: $this->workspace()->id);

        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        app(AccountMetricsSyncer::class)->sync($brokenAccount, Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame('error', $broken->refresh()->status);
        $this->assertSame('connected', $foreign->refresh()->status);
        $this->assertSame('connected', $sibling->refresh()->status);
    }

    public function test_reconnecting_brings_the_connection_back_on_the_same_row(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');
        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(7), Carbon::now());
        $this->assertSame('error', $connection->refresh()->status);

        $after = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('NEW-TOKEN', 'ads_read', Carbon::now()->addDays(60)), connectionName: 'Meta',
        );

        $this->assertSame((string) $connection->id, (string) $after->id);
        $this->assertSame('connected', $after->refresh()->status);
        $this->assertNull($after->last_error);
        $this->assertNotSame(ConnectionWizardState::USER_REAUTH_REQUIRED, app(ConnectionWizardState::class)->for($after)['user_state']);
    }

    public function test_a_deliberately_disconnected_connection_is_not_reopened_by_a_sync_error(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');
        $connection->forceFill(['status' => 'disconnected'])->save();

        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame('disconnected', $connection->refresh()->status);
    }

    public function test_the_token_alert_names_an_invalidated_connection_and_recovers_after_reconnecting(): void
    {
        [$connection, $account] = $this->boundAccount('act_1');
        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'token_expiry', 'name' => 'Tokens',
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true,
        ]);
        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(0, $evaluator->evaluateRule($rule), 'a healthy connection raises nothing');

        Http::fake(['graph.facebook.com/*' => Http::response(self::INVALIDATED, 400)]);
        app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::query()->where('rule_id', $rule->id)->firstOrFail();
        $this->assertStringContainsString('reconnect', strtolower((string) ($event->context['message'] ?? '')));

        app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('NEW', 'ads_read', Carbon::now()->addDays(60)), connectionName: 'Meta');
        $evaluator->evaluateRule($rule);
        $this->assertNotSame('open', $event->refresh()->status);
    }

    /** @return array{0: ProviderConnection, 1: ExternalAccount} */
    private function boundAccount(string $externalId): array
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('LIVE-TOKEN', 'ads_read', Carbon::now()->addDays(50)), connectionName: 'Meta',
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'external_id' => $externalId, 'name' => $externalId, 'account_type' => 'ad_account', 'status' => 'active', 'currency' => 'USD',
            'last_synced_at' => Carbon::now()->subHour(),
        ]);
        $workspace = $this->workspace();
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'Project 1', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return [$connection, $account];
    }

    private function workspace(): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
    }

    private function credentialPayload(ProviderConnection $connection): string
    {
        return (string) IntegrationCredential::withoutGlobalScopes()->findOrFail($connection->credential_id)->revealPayload();
    }
}
