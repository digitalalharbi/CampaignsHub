<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetricsAvailability;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-STATE-SEMANTICS-001 — four different reasons a card is empty, told apart.
 *
 * The Content Library said «لا توجد بيانات» under every creative without figures, which covered a
 * creative that did not run, a provider with no creative-level reporting, and a fetch that failed.
 * An operator acts differently on each: leave it alone, expect nothing ever, or go fix the pipeline.
 */
final class CreativeMetricsAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    public function test_a_successful_fetch_with_no_rows_means_the_creative_did_not_run(): void
    {
        $this->syncRun('snapchat', 'success', rows: 814);

        $state = $this->availability('snapchat');

        $this->assertSame('success', $state['status']);
        $this->assertSame(814, $state['rows'], 'The card needs to know the fetch worked to say «did not run».');
    }

    /** Never asked is a fact about the provider, not a missing number. */
    public function test_a_provider_without_creative_reporting_is_unsupported(): void
    {
        $this->syncRun('tiktok', 'unsupported', rows: 0);

        $this->assertSame('unsupported', $this->availability('tiktok', provider: 'tiktok')['status']);
    }

    /**
     * A failure must reach the reader with its reason.
     *
     * «No data» for a throttled request tells an operator their campaign is idle when the truth is
     * that the pipeline is broken — the two call for opposite actions.
     */
    public function test_a_failed_fetch_carries_its_reason(): void
    {
        $this->syncRun('snapchat', 'failed', rows: 0, error: 'Rate limited by the platform (429).');

        $state = $this->availability('snapchat');

        $this->assertSame('failed', $state['status']);
        $this->assertSame('Rate limited by the platform (429).', $state['error']);
    }

    /**
     * The latest run wins — a provider that failed yesterday and succeeded an hour ago is working.
     *
     * Reporting a resolved outage is how an operator learns to ignore the next one.
     */
    public function test_the_most_recent_outcome_is_the_answer(): void
    {
        $this->syncRun('snapchat', 'failed', rows: 0, error: 'Yesterday', at: Carbon::now()->subDay());
        $this->syncRun('snapchat', 'success', rows: 500, at: Carbon::now());

        $this->assertSame('success', $this->availability('snapchat')['status']);
    }

    /**
     * No recorded attempt is «unknown», not «unsupported».
     *
     * Runs written before this was recorded carry a null status. Calling that unsupported would
     * state a fact about the provider that has not been established.
     */
    public function test_no_recorded_attempt_is_unknown_rather_than_a_claim(): void
    {
        $this->assertSame('unknown', $this->availability('snapchat')['status']);
    }

    /** One library page shows several providers, and their answers are independent. */
    public function test_each_provider_answers_for_itself(): void
    {
        $this->syncRun('snapchat', 'success', rows: 814);
        $this->syncRun('tiktok', 'unsupported', rows: 0);

        $creatives = collect([
            $this->creative('snapchat'),
            $this->creative('tiktok'),
        ]);

        $out = app(CreativeMetricsAvailability::class)->forCreatives($creatives);

        $this->assertSame('success', $out['snapchat']['status']);
        $this->assertSame('unsupported', $out['tiktok']['status']);
    }

    /** @return array{status:string, rows:?int, error:?string, at:?string} */
    private function availability(string $runProvider, string $provider = 'snapchat'): array
    {
        return app(CreativeMetricsAvailability::class)
            ->forCreatives(collect([$this->creative($provider)]))[$provider];
    }

    private function creative(string $provider): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A creative',
            'format' => 'image',
        ]);
    }

    private function syncRun(string $provider, string $status, int $rows, ?string $error = null, ?Carbon $at = null): void
    {
        $model = new MetricSyncRun;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'status' => 'success',
            'window_start' => '2026-08-01',
            'window_end' => '2026-08-23',
            'metrics_upserted' => 10,
            'attempts' => 1,
            'started_at' => $at ?? Carbon::now(),
            'finished_at' => $at ?? Carbon::now(),
            'creative_status' => $status,
            'creative_rows' => $rows,
            'creative_error' => $error,
        ])->save();
    }
}
