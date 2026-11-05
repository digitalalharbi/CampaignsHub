<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Metrics\Models\CurrencyRate;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — what three half-hourly sweeps store is what they stored before a sweep learned to
 * leave an unchanged row alone.
 *
 * The baseline `tests/Fixtures/sync_sweep_characterisation_baseline.json` was written by the code as it
 * stood BEFORE that change (`origin/main` at bd7d04263), over the world of {@see BuildsLiveReportWorld}
 * with a provider answering through `Http::fake`:
 *
 *   - sweep A: every figure in the window differs from the stored one, and today's rows are new;
 *   - sweep B: yesterday and before come back identical, today's figures moved, one metric and one ad
 *     report for the first time, one metric the provider used to send is absent, and a SAR rate has
 *     arrived, so the money sweep A withheld is converted;
 *   - sweep C: the provider repeats sweep B word for word.
 *
 * The accounts cover every way a sweep ends: two Snapchat accounts that answer (one of them naming a
 * campaign nobody discovered), a deselected account and an unbound one the job refuses, Meta failing
 * at the provider, TikTok with no credentials.
 *
 * Held to the file after every sweep: every row of `daily_metrics`, `entity_daily_metrics` and
 * `creative_daily_metrics` (figures, attribution window, both currencies, the rate, project, account),
 * and every `metric_sync_runs`, account checkpoint, connection, stored reach and retained body.
 *
 * Two columns are NOT in the held rows, because they are the change: the three tables' `updated_at`
 * and `entity_daily_metrics.sync_run_id` now say when a row's figures last CHANGED and which run
 * changed them. {@see self::test_a_row_the_provider_repeated_is_not_written_again()} holds that.
 *
 * Re-write the baseline only for an intended change of what a sweep stores:
 * `WRITE_SYNC_SWEEP_BASELINE=1 … --filter=SyncSweepCharacterisationTest`.
 */
final class SyncSweepCharacterisationTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private const BASELINE = __DIR__.'/../Fixtures/sync_sweep_characterisation_baseline.json';

    private const TODAY = '2026-10-21';

    /** Tables held row by row in the file; the metric tables are held by count and digest. */
    private const SMALL = ['metric_sync_runs', 'external_accounts', 'provider_connections', 'period_reach', 'integration_raw_payloads'];

    private const METRIC = [
        'daily_metrics' => ['external_account_id', 'external_campaign_id', 'metric_key', 'metric_date', 'attribution_window', 'project_id'],
        'entity_daily_metrics' => ['entity_type', 'entity_id', 'metric_date', 'attribution_window', 'project_id'],
        'creative_daily_metrics' => ['creative_id', 'metric_date'],
    ];

    /** The columns that now carry «when it last changed» rather than «when it was last sent». */
    private const STAMPS = [
        'daily_metrics' => ['updated_at'],
        'entity_daily_metrics' => ['updated_at', 'sync_run_id'],
        'creative_daily_metrics' => ['updated_at'],
    ];

    private string $phase = 'a';

    /** The world seeds its own bindings, and the account with none must stay unbound. */
    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        // Without the definitions no metric is money, and the currency path would not be held at all.
        $this->seed(MetricDefinitionSeeder::class);
        $this->freezeWorld();
        $this->buildWorld(2);
        $this->seedUnboundAccount();
        DB::table('external_accounts')->update(['timezone' => 'Asia/Riyadh']);

        foreach (['snapchat', 'meta'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
        foreach (PlatformCredentials::for('tiktok')->requires() as $key) {
            config()->set("ad_platforms.platforms.tiktok.{$key}", null);
        }

        Http::preventStrayRequests();
        Http::fake(fn ($request) => $this->provider($request->url()));
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    public function test_three_sweeps_store_what_they_stored_before(): void
    {
        $now = $this->threeSweeps();

        foreach ($now as $sweep) {
            // The world has to reach every branch, or identity proves nothing.
            $this->assertGreaterThan(0, $sweep['daily_metrics']['rows']);
            $this->assertGreaterThan(0, $sweep['entity_daily_metrics']['rows']);
            $this->assertGreaterThan(0, $sweep['creative_daily_metrics']['rows']);
            $statuses = array_count_values(array_column($sweep['metric_sync_runs'], 'status'));
            $this->assertGreaterThan(0, $statuses['success'] ?? 0, 'no account answered');
            $this->assertGreaterThan(0, $statuses['partial_mapping'] ?? 0, 'no run named an undiscovered campaign');
            $this->assertGreaterThan(0, $statuses['failed'] ?? 0, 'no provider failed');
        }
        // Six bound accounts and the one bound elsewhere are swept; the deselected and the unbound are refused.
        $this->assertCount(7, $now['a']['metric_sync_runs']);
        $this->assertCount(21, $now['c']['metric_sync_runs']);

        if (getenv('WRITE_SYNC_SWEEP_BASELINE') === '1') {
            file_put_contents(self::BASELINE, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('Baseline written.');
        }

        $stored = json_decode((string) file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR);

        foreach ($now as $sweep => $tables) {
            foreach ($tables as $table => $held) {
                $this->assertSame($stored[$sweep][$table], $held, "after sweep «{$sweep}», `{$table}` is not what the baseline holds");
            }
        }
        $this->assertSame(
            json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($now, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_a_row_the_provider_repeated_is_not_written_again(): void
    {
        $at = ['a' => '2026-10-21 09:00:00', 'b' => '2026-10-21 09:30:00', 'c' => '2026-10-21 10:00:00'];
        $figures = [];
        $stamps = [];

        foreach ($at as $sweep => $time) {
            $this->sweep($sweep, $time);
            foreach (array_keys(self::METRIC) as $table) {
                [$figures[$sweep][$table], $stamps[$sweep][$table]] = $this->metricRows($table);
            }
        }

        $runOf = [];
        foreach (DB::table('metric_sync_runs')->get(['id', 'external_account_id', 'started_at']) as $run) {
            $runOf[(string) $run->external_account_id][Carbon::parse((string) $run->started_at)->format('Y-m-d H:i:s')] = (string) $run->id;
        }

        foreach (array_keys(self::METRIC) as $table) {
            $unchangedInB = 0;
            $changedInB = 0;
            $newInB = 0;

            foreach ($figures['c'][$table] as $key => $row) {
                // Sweep C repeats sweep B: nothing is new, nothing moved, nothing is stamped again.
                $this->assertSame($figures['b'][$table][$key], $row, "{$table} {$key}: sweep C changed a figure the provider repeated");
                $this->assertSame($stamps['b'][$table][$key], $stamps['c'][$table][$key], "{$table} {$key}: a repeated row was written again by sweep C");

                $before = $figures['a'][$table][$key] ?? null;
                if (! in_array(substr((string) $row['metric_date'], 0, 10), ['2026-10-18', '2026-10-19', '2026-10-20', self::TODAY], true)) {
                    continue; // outside the window: never asked for
                }

                $last = match (true) {
                    $before === null => 'b',
                    $before !== $row => 'b',
                    default => 'a',
                };
                $before === null ? $newInB++ : ($last === 'b' ? $changedInB++ : $unchangedInB++);

                // Only rows the sweep itself wrote: the world's own rows of accounts that are not swept keep the seed's stamp.
                $account = $this->accountOf($table, $row);
                if (! isset($runOf[$account][$at['a']]) || ($figures['a'][$table][$key] ?? null) === ($this->seedFigures[$table][$key] ?? false)) {
                    continue;
                }

                $this->assertSame($at[$last], Carbon::parse((string) $stamps['c'][$table][$key]['updated_at'])->format('Y-m-d H:i:s'), "{$table} {$key}: `updated_at` is not the sweep that last changed the row");
                if ($table === 'entity_daily_metrics') {
                    $this->assertSame($runOf[$account][$at[$last]], $stamps['c'][$table][$key]['sync_run_id'], "{$table} {$key}: `sync_run_id` is not the run that last changed the row");
                }
            }

            $this->assertGreaterThan(0, $unchangedInB, "{$table}: the world has no row sweep B repeated");
            $this->assertGreaterThan(0, $changedInB, "{$table}: the world has no row sweep B changed");
            $this->assertGreaterThan(0, $newInB, "{$table}: the world has no row sweep B added");
        }

        // A sweep that changed nothing still says it ran, what it received, and that it succeeded.
        $last = DB::table('metric_sync_runs')->where('started_at', $at['c'])->where('provider', 'snapchat')->orderBy('id')->get();
        $this->assertCount(2, $last);
        foreach ($last as $run) {
            $this->assertContains($run->status, ['success', 'partial_mapping']);
            $this->assertGreaterThan(0, (int) $run->metrics_upserted);
            $this->assertGreaterThan(0, (int) $run->creative_rows);
            $this->assertNotNull($run->finished_at);
        }
        foreach (DB::table('external_accounts')->where('provider', 'snapchat')->whereNotNull('last_sync_attempt_at')->get() as $account) {
            $this->assertSame($at['c'], Carbon::parse((string) $account->last_synced_at)->format('Y-m-d H:i:s'));
        }
    }

    /** @var array<string, array<string, array<string, mixed>>> the figures as the world seeded them, per table */
    private array $seedFigures = [];

    /** @return array<string, array<string, mixed>> sweep => table => what is held */
    private function threeSweeps(): array
    {
        $out = [];
        foreach (['a' => '2026-10-21 09:00:00', 'b' => '2026-10-21 09:30:00', 'c' => '2026-10-21 10:00:00'] as $sweep => $time) {
            $this->sweep($sweep, $time);
            $out[$sweep] = $this->held();
        }

        return $out;
    }

    private function sweep(string $phase, string $time): void
    {
        if ($this->seedFigures === []) {
            foreach (array_keys(self::METRIC) as $table) {
                [$this->seedFigures[$table]] = $this->metricRows($table);
            }
        }

        $this->phase = $phase;
        Carbon::setTestNow(Carbon::parse($time, 'UTC'));

        // A rate arrives between A and B: money the first sweep had to withhold converts on the second (FX-001).
        if ($phase === 'b') {
            CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-10-01']);
        }
        $this->artisan('integrations:sync')->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function held(): array
    {
        $out = [];

        foreach (array_keys(self::METRIC) as $table) {
            [$rows] = $this->metricRows($table);
            $out[$table] = [
                'rows' => count($rows),
                'sha256' => hash('sha256', (string) json_encode($rows)),
                // Readable beside the digest: where a difference is, before anybody diffs two dumps.
                'by_date' => $this->byDate($table),
            ];
        }

        foreach (self::SMALL as $table) {
            $rows = DB::table($table)->orderBy($table === 'integration_raw_payloads' ? 'fetched_at' : 'created_at')->orderBy('id')->get()
                ->map(fn ($r): array => (array) $r)->all();
            if ($table === 'integration_raw_payloads') {
                // The body is held by digest: it is the fake's own answer, and long.
                $rows = array_map(static fn (array $r): array => [...$r, 'payload' => hash('sha256', (string) $r['payload'])], $rows);
            }
            $out[$table] = $rows;
        }

        $out['external_creatives.last_active_at'] = DB::table('external_creatives')->orderBy('id')->pluck('last_active_at', 'id')->all();

        return $out;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>} natural key => held columns, natural key => stamp columns
     */
    private function metricRows(string $table): array
    {
        $rows = [];
        $stamps = [];

        foreach (DB::table($table)->orderBy('id')->get() as $r) {
            $row = (array) $r;
            $key = implode('|', array_map(static fn (string $c): string => (string) $row[$c], self::METRIC[$table]));
            $stamps[$key] = array_intersect_key($row, array_flip(self::STAMPS[$table]));
            $rows[$key] = array_diff_key($row, array_flip(self::STAMPS[$table]));
        }
        ksort($rows);

        return [$rows, $stamps];
    }

    /** @return list<array<string, mixed>> */
    private function byDate(string $table): array
    {
        $sums = match ($table) {
            'daily_metrics' => 'COUNT(*) AS n, SUM(value) AS value, SUM(original_amount) AS original_amount, SUM(converted_amount) AS converted_amount',
            'entity_daily_metrics' => 'COUNT(*) AS n, SUM(spend) AS spend, SUM(spend_original) AS spend_original, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(reach) AS reach',
            default => 'COUNT(*) AS n, SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks',
        };

        return DB::table($table)->where('metric_date', '>=', '2026-10-17')->selectRaw("metric_date::text AS day, {$sums}")
            ->groupBy('metric_date')->orderBy('metric_date')->get()->map(fn ($r): array => (array) $r)->all();
    }

    /** @param array<string, mixed> $row */
    private function accountOf(string $table, array $row): string
    {
        if ($table !== 'creative_daily_metrics') {
            return (string) $row['external_account_id'];
        }

        return (string) DB::table('external_creatives as k')->join('external_campaigns as c', 'c.id', '=', 'k.external_campaign_id')
            ->where('k.id', $row['creative_id'])->value('c.external_account_id');
    }

    // ── The provider ──────────────────────────────────────────────────────────────────────────

    private function provider(string $url): mixed
    {
        if (str_contains($url, 'graph.facebook.com')) {
            // 400, not 500: the transport retries a 5xx with real waits, and this is about what is recorded.
            return Http::response(['error' => ['message' => 'An unknown error occurred', 'type' => 'OAuthException', 'code' => 1]], 400);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (! str_contains($path, '/stats')) {
            return Http::response([], 200);
        }

        $from = substr((string) ($query['start_time'] ?? ''), 0, 10);
        $to = substr((string) ($query['end_time'] ?? ''), 0, 10);
        $breakdown = (string) ($query['breakdown'] ?? '');

        if (preg_match('#adaccounts/([^/]+)/stats#', $path, $m) === 1) {
            $account = $m[1];
            $ids = match ($breakdown) {
                'campaign' => DB::table('external_campaigns as c')->join('external_accounts as a', 'a.id', '=', 'c.external_account_id')
                    ->where('a.external_id', $account)->orderBy('c.external_id')->pluck('c.external_id')->all(),
                'creative' => DB::table('external_creatives as k')->join('external_campaigns as c', 'c.id', '=', 'k.external_campaign_id')
                    ->join('external_accounts as a', 'a.id', '=', 'c.external_account_id')
                    ->where('a.external_id', $account)->orderBy('k.external_creative_id')->pluck('k.external_creative_id')->all(),
                default => null,
            };
            if ($ids === null) {
                return Http::response([], 200);
            }
            // The second account's answer names a campaign nobody discovered.
            if ($breakdown === 'campaign' && $account === 'snapchat-act-2') {
                $ids[] = 'ext-never-discovered';
            }

            return $this->answer($breakdown, $ids, $from, $to, $breakdown === 'campaign'
                ? ['spend' => 90_000, 'impressions' => 50_000, 'swipes' => 900, 'uniques' => 30_000, 'conversion_purchases' => 40, 'conversion_purchases_value' => 300_000, 'conversion_add_cart' => 120, 'video_views' => 9000]
                : ['spend' => 30_000, 'impressions' => 20_000, 'swipes' => 400, 'video_views' => 3000]);
        }

        if (preg_match('#campaigns/([^/]+)/stats#', $path, $m) === 1 && in_array($breakdown, ['adsquad', 'ad'], true)) {
            $ids = DB::table($breakdown === 'ad' ? 'external_ads as e' : 'external_ad_sets as e')
                ->join('external_campaigns as c', 'c.id', '=', 'e.external_campaign_id')
                ->where('c.external_id', $m[1])->orderBy('e.external_id')->pluck('e.external_id')->all();

            return $this->answer($breakdown, $ids, $from, $to, ['spend' => 40_000, 'impressions' => 25_000, 'swipes' => 500, 'uniques' => 15_000]);
        }

        return Http::response([], 200);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, int>  $fields  field => range of the deterministic figure
     */
    private function answer(string $level, array $ids, string $from, string $to, array $fields): mixed
    {
        $entries = [];

        foreach ($ids as $n => $id) {
            $points = [];

            for ($d = Carbon::parse($from); $d->toDateString() <= min($to, self::TODAY); $d = $d->copy()->addDay()) {
                $date = $d->toDateString();
                // Sweep A is one answer; B and C are another for TODAY only, and repeat A for the days before.
                $salt = $this->phase !== 'a' && $date === self::TODAY ? 'b' : 'a';
                $stats = [];

                foreach ($fields as $field => $range) {
                    // First reported in sweep B: a metric nobody sent before (new rows on every day of the window).
                    if ($field === 'video_views' && $this->phase === 'a') {
                        continue;
                    }
                    // Sent in sweep A and absent afterwards for the first entity: the stored figure stays.
                    if ($field === 'conversion_add_cart' && $this->phase !== 'a' && $n === 0) {
                        continue;
                    }
                    // The last entity of an answer starts reporting in sweep B.
                    if ($this->phase === 'a' && $n === count($ids) - 1 && $level !== 'campaign') {
                        continue 2;
                    }

                    $figure = crc32("{$id}|{$date}|{$field}|{$salt}") % $range;
                    $stats[$field] = in_array($field, ['spend', 'conversion_purchases_value'], true) ? $figure * 10_000 : $figure;
                }

                $points[] = ['start_time' => $date.'T00:00:00.000+03:00', 'stats' => $stats];
            }

            if ($points !== []) {
                $entries[] = ['id' => $id, 'timeseries' => $points];
            }
        }

        return Http::response(['request_status' => 'SUCCESS', 'timeseries_stats' => [['timeseries_stat' => ['type' => 'AD_ACCOUNT', 'breakdown_stats' => [$level => $entries]]]]], 200);
    }
}
