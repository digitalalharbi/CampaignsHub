<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (case 9) — a partial page, a 429 or a 5xx in the MIDDLE of a sync never deletes or overwrites a
 * previous successful day with a partial one, and the run records what happened (not success).
 *
 * Sequence under test: a first sync stores 2026-08-01 (campaign 1200, ad set 1200, ad 1200) and is a success. A second sync of the
 * same window then meets the failure, with page 1 already answered with a SMALLER figure (5). Page 1's figure must not replace
 * the stored one when page 2 is refused. Neighbours credited and not repeated: `AdPlatformConnectorTest` (INTEG-RETRY-001: the
 * retry policy), `SyncRunTruthTest` (partial states), `EntityMetricsAreActuallySyncedTest` (ENTITY-GRAIN-FAILURE-001, grain level
 * only). Nothing before asserted the STORED figures after a mid-sync refusal.
 */
final class IntegrationBoundaryPartialSyncKeepsLastSuccessTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private ExternalAccount $account;

    private ProviderConnection $connection;

    private Project $project;

    /** @var array<string, mixed> */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Sleep::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'UTC'));

        $this->tenant = $this->bwTenant();
        $workspace = $this->bwWorkspace($this->tenant);
        $this->project = $this->bwProject($this->tenant, $workspace);
        $this->connection = $this->bwConnection($this->tenant, 'meta');
        $this->account = $this->bwAccount($this->connection, 'act_1');
        $this->bwBind($this->account, $this->project);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
        $adSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'adset-1', 'name' => 'Set', 'status' => 'active',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_ad_set_id' => $adSet->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'external_id' => 'ad-1', 'name' => 'Ad', 'status' => 'active',
        ]);

        // The previous successful sync.
        $this->script = ['mode' => 'ok', 'spend' => '1200'];
        $this->installFake();
        $first = $this->sync();
        $this->assertSame('success', $this->statusOf($first), (string) $first->error);
        $this->assertEqualsWithDelta(1200.0, $this->storedSpend(), 0.001);
        $this->assertSame(2, DB::table('entity_daily_metrics')->count(), 'the first sync stored the ad-set and ad grains');
        $this->account->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function mid_sync_refusals(): array
    {
        return [
            'page 2 answers HTTP 500 on every retry' => ['page2_500'],
            'page 2 answers HTTP 429 with a Retry-After' => ['page2_429'],
            'page 2 never connects (timeout)' => ['page2_timeout'],
            'the very first call answers HTTP 503' => ['first_503'],
        ];
    }

    #[DataProvider('mid_sync_refusals')]
    public function test_a_refusal_in_the_middle_of_the_campaign_read_keeps_the_previous_successful_day_and_does_not_read_as_success(string $mode): void
    {
        $lastSyncedAt = $this->account->last_synced_at;
        $connectionSuccessAt = $this->connection->refresh()->last_successful_sync_at;
        Carbon::setTestNow(Carbon::now()->addHour());

        $this->script = ['mode' => $mode, 'spend' => '5']; // page 1 would have said 5 for the same day
        $run = $this->sync();

        $this->assertNotSame('success', $this->statusOf($run), 'a sync cut short by the provider reads as success');
        $this->assertContains($this->statusOf($run), ['failed', 'partial_mapping'], 'the run does not record the refusal');
        $this->assertNotEmpty((string) $run->error, 'the run says nothing about what was refused');

        $this->assertEqualsWithDelta(1200.0, $this->storedSpend(), 0.001, 'page 1\'s partial figure replaced the previous successful day');
        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->count(), 'a day was deleted or duplicated');
        $this->assertSame(0, (int) $run->metrics_upserted, 'the run claims rows it did not store');

        // The checkpoint did not move: «last synced» still names the last SUCCESS, and the connection's last success is untouched.
        $this->assertEquals($lastSyncedAt?->toIso8601String(), $this->account->refresh()->last_synced_at?->toIso8601String(), 'a failed sync advanced the account\'s last-synced stamp');
        $this->assertEquals($connectionSuccessAt?->toIso8601String(), $this->connection->refresh()->last_successful_sync_at?->toIso8601String());
        $this->assertNotNull($this->account->last_sync_error_category, 'the account does not say the last attempt failed');

        // The ad-set / ad figures of the previous success are untouched as well.
        $this->assertEqualsWithDelta(1200.0, (float) DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->value('spend'), 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) DB::table('entity_daily_metrics')->where('entity_type', 'ad')->value('spend'), 0.001);
    }

    public function test_a_refusal_of_the_ad_set_grain_keeps_the_previous_entity_rows_and_the_run_is_partial_not_success(): void
    {
        $this->script = ['mode' => 'adset_500', 'spend' => '1300'];
        $run = $this->sync();

        // The campaign figure landed (it was answered in full) …
        $this->assertEqualsWithDelta(1300.0, $this->storedSpend(), 0.001);
        // … the grain that was refused kept what the last success wrote, and the run says it is incomplete.
        $this->assertEqualsWithDelta(1200.0, (float) DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->value('spend'), 0.001, 'a refused grain was overwritten or emptied');
        $this->assertSame('partial_mapping', $this->statusOf($run));
        $this->assertStringContainsString('ad-set/ad grain was not', (string) $run->error);
    }

    public function test_an_answer_that_covers_fewer_days_restates_those_days_and_deletes_none(): void
    {
        // A wider window had been synced: 2026-08-01 and 2026-08-02 are both stored.
        DB::table('daily_metrics')->where('metric_key', 'spend')->get()->each(function (object $row): void {
            $copy = (array) $row;
            $copy['id'] = (string) \Illuminate\Support\Str::uuid();
            $copy['metric_date'] = '2026-08-02';
            $copy['value'] = 700;
            DB::table('daily_metrics')->insert($copy);
        });
        $this->assertSame(2, DB::table('daily_metrics')->where('metric_key', 'spend')->count());

        // The provider now answers a 200 holding only ONE of the two days (a late-attribution window, an incomplete answer).
        $this->script = ['mode' => 'ok', 'spend' => '1250'];
        $this->sync();

        $byDate = DB::table('daily_metrics')->where('metric_key', 'spend')->pluck('value', 'metric_date')->map(fn ($v) => (float) $v)->all();
        $this->assertEqualsWithDelta(1250.0, $byDate['2026-08-01'] ?? -1, 0.001, 'the day the provider answered was not restated');
        $this->assertEqualsWithDelta(700.0, $byDate['2026-08-02'] ?? -1, 0.001, 'a day the provider did not mention was deleted or zeroed');
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function sync(): MetricSyncRun
    {
        return app(AccountMetricsSyncer::class)->sync($this->account->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));
    }

    private function storedSpend(): float
    {
        return (float) DB::table('daily_metrics')->where('metric_key', 'spend')->where('metric_date', '2026-08-01')->value('value');
    }

    private function statusOf(MetricSyncRun $run): string
    {
        return $run->status instanceof \BackedEnum ? (string) $run->status->value : (string) $run->status;
    }

    /** One stub that reads the script at request time (`Http::fake` merges stubs and the first match wins). */
    private function installFake(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            $mode = (string) $this->script['mode'];
            $spend = (string) $this->script['spend'];
            $row = ['campaign_id' => 'cmp-1', 'date_start' => '2026-08-01', 'spend' => $spend, 'impressions' => '40000', 'clicks' => '800'];

            if (! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, 'level=adset')) {
                return $mode === 'adset_500'
                    ? Http::response(['error' => ['message' => 'Service unavailable']], 503)
                    : Http::response(['data' => [[...$row, 'adset_id' => 'adset-1', 'spend' => '1200']]], 200);
            }
            if (str_contains($url, 'level=ad')) {
                return Http::response(['data' => [[...$row, 'ad_id' => 'ad-1', 'adset_id' => 'adset-1', 'spend' => '1200']]], 200);
            }

            // Campaign level, paged: page 1 carries `paging.next`, page 2 is where the failure is staged.
            if (str_contains($url, 'after=PAGE2')) {
                return match ($mode) {
                    'page2_500' => Http::response(['error' => ['message' => 'Internal error', 'code' => 2]], 500),
                    'page2_429' => Http::response(['error' => ['message' => 'Too many calls', 'code' => 4]], 429, ['Retry-After' => '1']),
                    'page2_timeout' => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'),
                    default => Http::response(['data' => []], 200),
                };
            }
            if ($mode === 'first_503') {
                return Http::response(['error' => ['message' => 'Service unavailable']], 503);
            }

            $page = ['data' => [$row]];
            if (in_array($mode, ['page2_500', 'page2_429', 'page2_timeout'], true)) {
                $page['paging'] = ['next' => 'https://graph.facebook.com/v21.0/act_1/insights?after=PAGE2&level=campaign'];
            }

            return Http::response($page, 200);
        });
    }
}
