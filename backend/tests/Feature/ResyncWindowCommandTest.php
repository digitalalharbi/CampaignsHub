<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — a re-sync is one BOUND account over one window, queued through the
 * ordinary job, and nothing else.
 */
final class ResyncWindowCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $bound;

    private ExternalAccount $unbound;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        foreach (['bound', 'unbound'] as $name) {
            $this->{$name} = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'snapchat',
                'account_type' => 'ad_account', 'external_id' => 'act-'.$name, 'name' => $name, 'status' => 'active', 'discovered_at' => Carbon::now(),
            ]);
        }

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->bound->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        Queue::fake();
    }

    public function test_a_dry_run_prints_the_chunks_and_queues_nothing(): void
    {
        $code = Artisan::call('integrations:resync-window', ['--account' => $this->bound->id, '--from' => '2026-08-16', '--to' => '2026-09-06']);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('dry run, nothing will be queued', $output);
        $this->assertStringContainsString('4 job(s) of up to 7 day(s)', $output);
        $this->assertStringContainsString('2026-08-16 → 2026-08-22', $output);
        $this->assertStringContainsString('2026-09-06 → 2026-09-06', $output);
        Queue::assertNothingPushed();
    }

    public function test_apply_queues_exactly_the_chunks_for_the_bound_account_through_the_ordinary_job(): void
    {
        $code = Artisan::call('integrations:resync-window', ['--account' => $this->bound->id, '--from' => '2026-08-16', '--to' => '2026-09-06', '--apply' => true]);

        $this->assertSame(0, $code, Artisan::output());
        Queue::assertPushed(SyncAccountMetricsJob::class, 4);
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job): bool => $job->accountId === $this->bound->id && $job->from === '2026-08-16' && $job->to === '2026-08-22');
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job): bool => $job->from === '2026-09-06' && $job->to === '2026-09-06');
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job): bool => $job->accountId === $this->unbound->id);
    }

    public function test_an_account_that_is_not_selected_for_a_project_is_refused_even_with_apply(): void
    {
        $code = Artisan::call('integrations:resync-window', ['--account' => $this->unbound->id, '--from' => '2026-08-16', '--to' => '2026-09-06', '--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', Artisan::output());
        Queue::assertNothingPushed();
    }

    public function test_a_deselected_account_is_refused_too(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        $code = Artisan::call('integrations:resync-window', ['--account' => $this->bound->id, '--from' => '2026-08-16', '--to' => '2026-08-20', '--apply' => true]);

        $this->assertSame(1, $code);
        Queue::assertNothingPushed();
    }

    public function test_the_dry_run_counts_what_is_stored_in_the_window_and_writes_nothing(): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->bound->id, 'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'snapchat', 'metric_key' => 'spend', 'metric_date' => '2026-08-20', 'value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('daily_metrics')->count();

        Artisan::call('integrations:resync-window', ['--account' => $this->bound->id, '--from' => '2026-08-16', '--to' => '2026-09-06']);

        $this->assertMatchesRegularExpression('/daily_metrics\s+1 row\(s\)/', Artisan::output());
        $this->assertSame($before, DB::table('daily_metrics')->count());
    }
}
