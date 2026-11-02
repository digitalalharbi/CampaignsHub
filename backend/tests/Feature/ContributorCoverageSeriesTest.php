<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Coverage\ContributorCoverage;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PERF — `ContributorCoverage::forDays()` must answer exactly what one `forWindow($d, $d)` call per
 * day answers, from a fixed number of queries instead of three per day.
 */
final class ContributorCoverageSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 'ser-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'active']);
    }

    private function today(): Carbon
    {
        return Carbon::today();
    }

    /** Two providers with data (one with gaps), one campaign ending mid-window, one starting mid-window, plus a leak-bait project. */
    private function seedWorld(int $days): void
    {
        $t = $this->today();
        $first = $t->copy()->subDays($days);

        $snap = $this->account('snapchat', 'snap');
        $tik = $this->account('tiktok', 'tik');
        $goog = $this->account('google', 'goog');
        $meta = $this->account('meta', 'meta');

        $this->campaign($this->project, $snap, 'snap-c', null, null);
        $this->campaign($this->project, $tik, 'tik-c', null, $first->copy()->addDays((int) ($days / 2))->toDateTimeString());
        $this->campaign($this->project, $goog, 'goog-c', $first->copy()->addDays((int) ($days / 3))->toDateTimeString(), null);
        // Another project with a platform THIS project does not have: must never be expected here.
        $this->campaign($this->other, $meta, 'meta-c', null, null);

        for ($i = 0; $i < $days; $i++) {
            $date = $first->copy()->addDays($i)->toDateString();
            $this->metric($this->project, $snap, 'snapchat', $date);
            if ($i % 4 !== 1) {
                $this->metric($this->project, $tik, 'tiktok', $date);
            }
            $this->metric($this->other, $meta, 'meta', $date);
        }

        $this->syncRun('snapchat', $first->toDateString(), $t->copy()->subDay()->toDateString(), 'success');
        $this->syncRun('tiktok', $first->toDateString(), $first->copy()->addDays((int) ($days / 2))->toDateString(), 'success');
        $this->syncRun('tiktok', $first->toDateString(), $first->copy()->addDays(3)->toDateString(), 'failed', 'older');
        $this->syncRun('google', $first->toDateString(), $first->copy()->addDays(5)->toDateString(), 'failed', 'boom');
        $this->syncRun('meta', $first->toDateString(), $t->toDateString(), 'success');
    }

    public function test_series_equals_the_per_day_answer_for_every_day(): void
    {
        $this->seedWorld(30);
        $service = new ContributorCoverage;
        $from = $this->today()->copy()->subDays(35);
        $to = $this->today()->copy()->addDays(3); // runs past today: exercises the yesterday clamp

        foreach ([null, ['snapchat', 'tiktok'], ['google']] as $providers) {
            foreach ([(string) $this->project->id, (string) $this->other->id, null] as $projectId) {
                $series = $service->forDays((string) $this->tenant->id, $projectId, $from, $to, $providers);

                $this->assertCount(39, $series);
                $states = [];
                for ($d = $from->copy(); ! $d->gt($to); $d->addDay()) {
                    $day = $d->copy();
                    $expected = $service->forWindow((string) $this->tenant->id, $projectId, $day, $day, $providers)->toArray();
                    $this->assertSame($expected, $series[$day->toDateString()]->toArray(), 'diverged on '.$day->toDateString());
                    $states[$expected['state']] = true;
                }
                if ($projectId === (string) $this->project->id && $providers === null) {
                    // The world is not vacuous: more than one distinct outcome occurs.
                    $this->assertGreaterThan(1, count($states));
                }
            }
        }
    }

    public function test_other_projects_platforms_do_not_leak(): void
    {
        $this->seedWorld(10);
        $series = (new ContributorCoverage)->forDays((string) $this->tenant->id, (string) $this->project->id, $this->today()->copy()->subDays(8), $this->today()->copy()->subDays(2));

        foreach ($series as $coverage) {
            $this->assertNotContains('meta', $coverage->toArray()['expected_contributors']);
        }
    }

    public function test_timeseries_coverage_queries_do_not_grow_with_the_window(): void
    {
        $this->seedWorld(60);

        $count = function (int $days): int {
            app(ProjectContext::class)->setProjectId($this->project->id);
            $n = 0;
            DB::listen(function ($e) use (&$n): void {
                if (str_contains($e->sql, 'external_campaigns') || str_contains($e->sql, 'metric_sync_runs')
                    || str_contains($e->sql, 'distinct "provider"') || str_contains($e->sql, 'group by "metric_date", "provider"')) {
                    $n++;
                }
            });
            $out = app(MetricsAggregator::class)->timeseries($this->today()->copy()->subDays($days), $this->today()->copy()->subDay());
            app(ProjectContext::class)->forget();
            $this->assertGreaterThanOrEqual($days - 1, count($out));

            return $n;
        };

        $short = $count(20);
        $long = $count(60);

        $this->assertGreaterThan(0, $short);
        $this->assertSame($short, $long, 'coverage lookups grew with the window');
        $this->assertLessThanOrEqual(3, $long);
    }

    private function account(string $provider, string $label): ExternalAccount
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $label, 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act-'.$label, 'name' => $label,
            'status' => 'active', 'currency' => 'USD',
        ])->save();

        return $account;
    }

    private function campaign(Project $project, ExternalAccount $account, string $label, ?string $startsAt, ?string $endsAt): void
    {
        (new ExternalCampaign)->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider,
            'external_id' => $label, 'name' => $label, 'status' => 'active',
            'starts_at' => $startsAt, 'ends_at' => $endsAt,
        ])->save();
    }

    private function metric(Project $project, ExternalAccount $account, string $provider, string $date): void
    {
        $campaign = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $account->id)->first();
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => $campaign->id,
            'provider' => $provider, 'metric_key' => 'spend', 'metric_date' => $date, 'value' => 10,
            'original_amount' => 10, 'original_currency' => 'USD', 'project_currency' => 'USD', 'exchange_rate' => 1,
        ]);
    }

    private function syncRun(string $provider, string $from, string $to, string $status, ?string $error = null): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider' => $provider,
            'status' => $status, 'window_start' => $from, 'window_end' => $to,
            'started_at' => Carbon::parse($from)->toDateTimeString(), 'finished_at' => Carbon::parse($to)->toDateTimeString(),
            'error' => $error, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
