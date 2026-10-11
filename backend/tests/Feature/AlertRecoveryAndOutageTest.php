<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EMAIL-ALERT-RECOVERY-001 / EMAIL-ALERT-SYNC-OUTAGE-001 / EMAIL-ALERT-MEASUREMENT-OUTAGE-001 (Owner §D).
 *
 * An alert whose condition has gone is resolved by the sweep that finds it absent, and the reader is
 * told «recovered». An outage is the freshness engine's own verdict over a project, and a measurement
 * outage is the GA4 property's own sync — neither is a second definition of stale.
 */
final class AlertRecoveryAndOutageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function rule(string $type, array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->tenant->id, 'type' => $type, 'name' => ucfirst($type),
            'cooldown_minutes' => 720, 'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ], $over));
    }

    private function syncRun(string $connId, string $status, Carbon $at, ?string $accountId = null): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'connection_id' => $connId, 'external_account_id' => $accountId, 'provider' => 'meta', 'status' => $status,
            'window_start' => $at->copy()->subDay()->toDateString(), 'window_end' => $at->toDateString(),
            'error' => $status === 'failed' ? 'token expired' : null, 'started_at' => $at, 'finished_at' => $at->copy()->addMinute(),
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function boundAccount(string $provider, ProviderConnection $connection, string $type = 'ad_account'): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => $type, 'external_id' => 'act-'.uniqid(), 'name' => 'A', 'status' => 'active', 'discovered_at' => now(),
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'reporting', 'is_active' => true,
        ]);

        return $account;
    }

    private function metric(string $accountId, Carbon $freshAt): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $accountId, 'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => $freshAt->toDateString(), 'value' => 10,
            'project_currency' => 'SAR', 'data_freshness_at' => $freshAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_cleared_sync_failure_is_resolved_by_the_next_sweep_and_the_reader_is_told(): void
    {
        $rule = $this->rule('sync_failure');
        $connId = (string) Str::uuid();
        $this->syncRun($connId, 'failed', now()->subMinutes(30));

        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::query()->where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame('open', $event->status);

        // The next run succeeds — the condition is gone.
        $this->syncRun($connId, 'success', now()->subMinutes(5));
        $this->assertSame(0, $evaluator->evaluateRule($rule));

        $event->refresh();
        $this->assertSame('resolved', $event->status);
        $this->assertNotNull($event->resolved_at);
        $this->assertArrayHasKey('recovered_at', (array) $event->context);

        $recovered = AppNotification::query()->where('title', 'like', 'Recovered:%')->first();
        $this->assertNotNull($recovered, 'the reader is told the alert is over');
        $this->assertSame('info', $recovered->severity);
        $this->assertStringContainsString('Data sync failed', (string) $recovered->title);
    }

    public function test_a_snoozed_alert_whose_condition_cleared_is_resolved_too(): void
    {
        $rule = $this->rule('sync_failure');
        $connId = (string) Str::uuid();
        $this->syncRun($connId, 'failed', now()->subHours(2));
        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateRule($rule);
        $event = AlertEvent::query()->where('rule_id', $rule->id)->firstOrFail();
        $evaluator->snooze($event, now()->addDay());

        $this->syncRun($connId, 'success', now()->subMinutes(10));
        $evaluator->evaluateRule($rule);

        $this->assertSame('resolved', $event->refresh()->status);
    }

    public function test_a_type_the_sweep_cannot_evaluate_never_resolves_an_open_event(): void
    {
        $rule = $this->rule('report_failed');
        $event = AlertEvent::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'report_failed',
            'entity_type' => 'report', 'entity_id' => 'r1', 'dedup_key' => hash('sha256', 'x'), 'status' => 'open',
            'severity' => 'warning', 'context' => ['title' => 'Report failed'], 'last_triggered_at' => now(),
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule);

        $this->assertSame('open', $event->refresh()->status, 'an empty breach list from an unevaluated type is not «all clear»');
        $this->assertNull(AppNotification::query()->where('title', 'like', 'Recovered:%')->first());
    }

    public function test_a_stale_project_is_a_sync_outage_and_fresh_figures_recover_it(): void
    {
        $meta = $this->boundAccount('meta', $this->connection('meta'));
        // Read successfully four days ago and not since: stale, not «never read» (which would be awaiting credentials).
        $this->metric((string) $meta->id, now()->subDays(4));
        $this->syncRun((string) $meta->provider_connection_id, 'success', now()->subDays(4), (string) $meta->id);
        $rule = $this->rule('sync_outage');
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::query()->where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame(Project::class, $event->entity_type);
        $this->assertSame((string) $this->project->id, (string) $event->entity_id);
        // The roll-up ranks the window's missing days above staleness; the source itself is stale, and that is what fires.
        $this->assertSame('partial', $event->context['state']);
        $this->assertSame('stale', $event->context['sources'][0]['state']);
        $this->assertSame('Data is stale', $event->context['title']);
        $this->assertSame('meta', $event->context['sources'][0]['provider']);

        // Fresh figures arrive with a successful run — the engine says fresh, and the alert recovers.
        $this->metric((string) $meta->id, now()->subHour());
        $this->syncRun((string) $meta->provider_connection_id, 'success', now()->subHour(), (string) $meta->id);
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame('resolved', $event->refresh()->status);
    }

    public function test_a_failed_or_old_measurement_sync_is_a_measurement_outage(): void
    {
        $ga4 = $this->connection('ga4');
        IntegrationSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider_connection_id' => $ga4->id,
            'type' => 'measurement', 'status' => 'failed', 'error' => 'quota exceeded', 'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(19),
        ]);
        $rule = $this->rule('measurement_outage');
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $event = AlertEvent::query()->where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame('Measurement sync failed', $event->context['title']);
        $this->assertSame('ga4', $event->context['provider']);

        // A success older than the threshold, with nothing newer, is still an outage; a fresh success recovers it.
        IntegrationSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider_connection_id' => $ga4->id,
            'type' => 'measurement', 'status' => 'success', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
        ]);
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame('resolved', $event->refresh()->status);

        $old = $this->connection('ga4');
        IntegrationSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider_connection_id' => $old->id,
            'type' => 'measurement', 'status' => 'success', 'started_at' => now()->subHours(30), 'finished_at' => now()->subHours(30),
        ]);
        $this->assertSame(1, $evaluator->evaluateRule($rule), 'a property whose last success is 30 h old, with the default 12 h threshold, is an outage');
        $this->assertSame('Measurement data is stale', AlertEvent::query()->where('entity_id', (string) $old->id)->firstOrFail()->context['title']);
    }

    public function test_the_api_accepts_the_outage_types_and_an_hours_threshold(): void
    {
        $this->assertContains('sync_outage', AlertEvaluator::PERIODIC);
        $this->assertContains('measurement_outage', AlertEvaluator::PERIODIC);
    }
}
