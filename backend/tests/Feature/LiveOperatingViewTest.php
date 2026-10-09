<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LIVE-OPERATING-VIEW-001 — one screen per source, built on the freshness engine the product already has.
 *
 * What the Owner asked to see on one row: the latest SUCCESSFUL sync (apart from the latest attempt),
 * the latest source timestamp, the next sync, and a state — plus how the figures move. And what it
 * must never say: «real-time».
 */
final class LiveOperatingViewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@l.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    private function connection(string $provider, string $status): ProviderConnection
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => $status,
        ]);
    }

    private function boundAccount(string $provider, ProviderConnection $connection, ?Carbon $nextSyncAt): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act-'.uniqid(), 'name' => 'A', 'status' => 'active',
            'discovered_at' => now(), 'next_sync_at' => $nextSyncAt,
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'reporting', 'is_active' => true,
        ]);

        return $account;
    }

    private function metricsAndRuns(string $provider, string $accountId, Carbon $succeededAt, ?Carbon $failedAt = null): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => $accountId,
            'external_campaign_id' => (string) Str::uuid(), 'provider' => $provider, 'metric_key' => 'spend',
            'metric_date' => now()->toDateString(), 'value' => 10, 'project_currency' => 'SAR',
            'data_freshness_at' => $succeededAt,
        ]);
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $accountId,
            'provider' => $provider, 'status' => 'success', 'window_start' => now()->subDays(6)->toDateString(),
            'window_end' => now()->toDateString(), 'started_at' => $succeededAt->copy()->subMinute(), 'finished_at' => $succeededAt,
        ]);
        if ($failedAt !== null) {
            MetricSyncRun::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $accountId,
                'provider' => $provider, 'status' => 'failed', 'window_start' => now()->subDays(6)->toDateString(),
                'window_end' => now()->toDateString(), 'started_at' => $failedAt->copy()->subMinute(), 'finished_at' => $failedAt, 'error' => 'token expired',
            ]);
        }
    }

    private function liveView(): array
    {
        return (array) $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/live-view")
            ->assertOk()
            ->json('data');
    }

    public function test_each_source_states_its_latest_success_apart_from_its_latest_attempt_and_its_next_sync(): void
    {
        $meta = $this->boundAccount('meta', $this->connection('meta', 'connected'), now()->addMinutes(12));
        $succeeded = now()->subHours(2);
        $failed = now()->subMinutes(20);
        $this->metricsAndRuns('meta', (string) $meta->id, $succeeded, $failed);

        $data = $this->liveView();

        $this->assertFalse($data['realtime']);
        $this->assertStringContainsString('لا لحظية', $data['realtime_statement']['ar']);
        $this->assertStringContainsString('not real-time', $data['realtime_statement']['en']);
        $this->assertSame(7, $data['window_days']);

        $row = collect($data['sources'])->firstWhere('provider', 'meta');
        $this->assertNotNull($row, 'the bound Meta source is on the view');
        $this->assertSame('ad_platform', $row['kind']);
        $this->assertSame('failed', $row['state'], 'the latest attempt failed, and the state says so');
        $this->assertSame($succeeded->toIso8601String(), Carbon::parse($row['latest_successful_sync_at'])->toIso8601String(), 'the latest SUCCESS is the older run');
        $this->assertSame($failed->toIso8601String(), Carbon::parse($row['latest_attempt_at'])->toIso8601String(), 'the latest ATTEMPT is the failed run');
        $this->assertSame($succeeded->toIso8601String(), Carbon::parse($row['latest_source_timestamp'])->toIso8601String());
        $this->assertTrue($row['connected']);
        $this->assertSame($meta->next_sync_at->toIso8601String(), Carbon::parse($row['next_sync_at'])->toIso8601String(), 'the account\'s own next_sync_at wins');
        $this->assertNull($row['next_sync_reason']);
        $this->assertFalse($row['realtime']);

        $this->assertSame('integrations:sync', $row['mechanisms']['scheduled']['command']);
        $this->assertSame(['window_days' => 7], $row['mechanisms']['incremental']);
        $this->assertTrue($row['mechanisms']['manual']);
        $this->assertSame('supported', $row['mechanisms']['webhooks'], 'Meta publishes webhooks; the catalogue says so');

        $this->assertSame('sync_failed', $data['verdict']['state']);
        $this->assertContains('integrations:sync', array_column($data['scheduler'], 'command'));
    }

    public function test_a_revoked_connection_has_no_next_sync_and_says_why(): void
    {
        $snap = $this->boundAccount('snapchat', $this->connection('snapchat', 'revoked'), now()->addMinutes(5));
        $this->metricsAndRuns('snapchat', (string) $snap->id, now()->subHours(50));

        $row = collect($this->liveView()['sources'])->firstWhere('provider', 'snapchat');

        $this->assertNotNull($row);
        $this->assertFalse($row['connected']);
        $this->assertNull($row['next_sync_at'], '«next sync in 5 minutes» over a revoked token is refused');
        $this->assertSame('not_connected', $row['next_sync_reason']);
        $this->assertSame('stale', $row['state']);
        $this->assertSame('polling_only', $row['mechanisms']['webhooks'], 'Snapchat publishes no change webhook');
    }

    public function test_a_connected_account_without_a_written_next_sync_is_on_the_half_hour_schedule(): void
    {
        $tiktok = $this->boundAccount('tiktok', $this->connection('tiktok', 'connected'), null);
        $this->metricsAndRuns('tiktok', (string) $tiktok->id, now()->subHour());

        $row = collect($this->liveView()['sources'])->firstWhere('provider', 'tiktok');

        $this->assertNotNull($row['next_sync_at']);
        $next = Carbon::parse($row['next_sync_at']);
        $this->assertTrue($next->greaterThan(now()));
        $this->assertSame(0, (int) $next->format('i') % 30, 'the next half-hour boundary');
    }

    public function test_the_view_needs_the_campaigns_view_ability(): void
    {
        $nobody = User::create(['name' => 'N', 'email' => 'n-'.uniqid().'@l.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($nobody, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all');
        $nobody->assignRole($role);

        $this->actingAs($nobody, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/live-view")
            ->assertForbidden();
    }
}
