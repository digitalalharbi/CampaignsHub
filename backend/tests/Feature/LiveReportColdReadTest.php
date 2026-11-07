<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Coverage\ContributorCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — the two reads Production's plans showed walking far more rows than they answer for, and the JIT setting.
 *
 * Each rewrite here is held to the statement it replaced, on a world holding the rows that must stay invisible. A faster read that
 * picked a different run, or certified an account from a deselected account's rows, would be a defect no clock would catch.
 */
final class LiveReportColdReadTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    private function seedRun(string $projectId, string $accountId, string $provider, string $status, string $windowEnd, string $createdAt): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $projectId, 'external_account_id' => $accountId,
            'provider' => $provider, 'status' => $status, 'window_start' => '2026-10-01', 'window_end' => $windowEnd,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /** @return array<string, object> */
    private function latestRuns(array $providers, ?array $projectIds): array
    {
        $method = new ReflectionMethod(ContributorCoverage::class, 'latestRuns');

        return $method->invoke(app(ContributorCoverage::class), (string) $this->tenant->id, $providers, $projectIds);
    }

    /** The statement this replaced, verbatim in its filters and its order. */
    private function theDistinctOnItReplaced(array $providers, ?array $projectIds): array
    {
        $rows = DB::table('metric_sync_runs')
            ->selectRaw('distinct on ("metric_sync_runs"."provider") "metric_sync_runs"."provider", "metric_sync_runs"."status", "metric_sync_runs"."window_end", "metric_sync_runs"."error"')
            ->where('tenant_id', $this->tenant->id)
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'metric_sync_runs'))
            ->whereIn('provider', $providers)
            ->orderBy('provider')->orderByDesc('window_end')->orderByDesc('created_at')
            ->get();

        return $rows->keyBy('provider')->map(static fn ($r): array => (array) $r)->all();
    }

    public function test_the_newest_visible_run_per_provider_is_the_one_the_old_statement_chose(): void
    {
        $this->buildWorld(campaignsPerAccount: 1);
        $project = (string) $this->project->id;
        $other = (string) $this->otherProject->id;
        [$meta, $snap, $tiktok] = [$this->boundAccounts[0], $this->boundAccounts[2], $this->boundAccounts[4]];

        // The later window wins over the later creation, and creation breaks a tie on the window.
        $this->seedRun($project, $meta->id, 'meta', 'failed', '2026-10-20', '2026-10-20 01:00:00');
        $this->seedRun($project, $meta->id, 'meta', 'success', '2026-10-19', '2026-10-21 01:00:00');
        $this->seedRun($project, $snap->id, 'snapchat', 'success', '2026-10-20', '2026-10-20 01:00:00');
        $this->seedRun($project, $snap->id, 'snapchat', 'partial_mapping', '2026-10-20', '2026-10-20 02:00:00');
        $this->seedRun($project, $tiktok->id, 'tiktok', 'success', '2026-10-18', '2026-10-18 01:00:00');

        // Newer than all of the above, and none of them may answer: a deselected account, another project's account, another project's run.
        $this->seedRun($project, $this->deselectedAccount->id, 'snapchat', 'failed', '2026-10-25', '2026-10-25 01:00:00');
        $this->seedRun($project, $this->elsewhereAccount->id, 'meta', 'failed', '2026-10-25', '2026-10-25 01:00:00');
        $this->seedRun($other, $this->elsewhereAccount->id, 'tiktok', 'failed', '2026-10-25', '2026-10-25 01:00:00');

        foreach ([[$project], [$project, $other], null] as $scope) {
            $providers = ['meta', 'snapchat', 'tiktok', 'linkedin'];
            $now = array_map(static fn ($r): array => (array) $r, $this->latestRuns($providers, $scope));

            $this->assertEquals($this->theDistinctOnItReplaced($providers, $scope), $now);
        }

        $mine = $this->latestRuns(['meta', 'snapchat', 'tiktok', 'linkedin'], [$project]);
        $this->assertSame('failed', $mine['meta']->status);
        $this->assertSame('partial_mapping', $mine['snapchat']->status);
        $this->assertSame('success', $mine['tiktok']->status);
        $this->assertArrayNotHasKey('linkedin', $mine);

        // Another project's reader gets its own answer, not this one's.
        $this->assertSame('failed', $this->latestRuns(['tiktok'], [$other])['tiktok']->status);
        $this->assertSame([], $this->latestRuns(['meta', 'snapchat'], [$other]));
    }

    public function test_a_provider_named_twice_or_none_at_all_is_answered_once(): void
    {
        $this->buildWorld(campaignsPerAccount: 1);
        $this->seedRun((string) $this->project->id, $this->boundAccounts[0]->id, 'meta', 'success', '2026-10-20', '2026-10-20 01:00:00');

        $this->assertCount(1, $this->latestRuns(['meta', 'meta'], [(string) $this->project->id]));
        $this->assertSame([], $this->latestRuns([], [(string) $this->project->id]));
    }

    public function test_the_joined_campaign_predicate_admits_exactly_what_the_correlated_one_did(): void
    {
        $this->buildWorld(campaignsPerAccount: 2);

        $accounts = array_map(static fn ($a): string => (string) $a->id, [...$this->boundAccounts, $this->deselectedAccount, $this->elsewhereAccount]);
        $base = fn () => DB::table('creative_daily_metrics')
            ->join('external_creatives', 'external_creatives.id', '=', 'creative_daily_metrics.creative_id')
            ->join('external_campaigns', 'external_campaigns.id', '=', 'external_creatives.external_campaign_id')
            ->whereIn('external_campaigns.external_account_id', $accounts)
            ->orderBy('creative_daily_metrics.id');

        $all = $base()->pluck('creative_daily_metrics.id')->all();
        $old = BoundAccountVisibility::applyThroughCampaign($base(), 'external_creatives.external_campaign_id', 'creative_daily_metrics.project_id')->pluck('creative_daily_metrics.id')->all();
        $new = BoundAccountVisibility::applyThroughJoinedCampaign($base(), 'external_campaigns', 'creative_daily_metrics.project_id')->pluck('creative_daily_metrics.id')->all();

        $this->assertNotSame([], $new);
        $this->assertLessThan(count($all), count($new), 'the world holds rows of a deselected account and of a re-bound one: the predicate must drop them');
        $this->assertSame($old, $new);

        $shown = BoundAccountVisibility::applyThroughJoinedCampaign($base(), 'external_campaigns', 'creative_daily_metrics.project_id')
            ->reorder()->distinct()->pluck('external_campaigns.external_account_id')->map(static fn ($id): string => (string) $id)->all();

        $this->assertNotContains((string) $this->deselectedAccount->id, $shown);
        $this->assertNotContains((string) $this->elsewhereAccount->id, $shown);
    }

    public function test_the_web_tier_connects_with_jit_off_through_libpq(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3).'/deploy/docker-compose.production.yml');

        $this->assertStringContainsString('jit=off', (string) ($compose['services']['backend']['environment']['PGOPTIONS'] ?? ''));
    }

    public function test_the_snapshot_names_the_heaviest_scheduler_minute(): void
    {
        foreach ([['alerts:evaluate', 40000, '2026-10-21 08:00:05'], ['notifications:send-digests', 9000, '2026-10-21 08:00:50'], ['ops:perf-check', 500, '2026-10-21 08:05:00']] as [$command, $ms, $at]) {
            DB::table('scheduled_runs')->insert([
                'id' => (string) Str::uuid(), 'command' => $command, 'started_at' => $at, 'finished_at' => $at, 'duration_ms' => $ms,
                'outcome' => 'completed', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
        }

        $this->assertSame(0, Artisan::call('ops:perf-snapshot', ['--hours' => 2]));
        $output = Artisan::output();

        $this->assertStringContainsString('SCHEDULED COMMANDS', $output);
        $this->assertMatchesRegularExpression('/10-21 08:00\s+49\s*s\s+alerts:evaluate 40\.0s, notifications:send-digests 9\.0s/', $output);
    }
}
