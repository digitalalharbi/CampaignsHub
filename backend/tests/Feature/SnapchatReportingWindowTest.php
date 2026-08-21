<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Reporting\ReportingWindow;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * SNAP-WINDOW-001 / SNAP-PAGING-001 — the live «validation error», and why it was one.
 *
 * ## The production evidence
 *
 * The first real Snapchat metrics sync, on a live connection with a real assigned account, returned
 * **0 metrics** and:
 *
 * > Request cannot be processed due to validation error
 *
 * The range was a string literal — `$from.'T00:00:00.000-00:00'` — **UTC midnight for every account
 * on the platform**. Snapchat's measurement reference states the rule outright:
 *
 * > time must be of day boundary, start_time and end_time must be both specified, or neither
 *
 * and its own DAY responses come back on the ad account's offset
 * (`"start_time": "2016-08-05T22:00:00.000-07:00"`). For an account in `Asia/Riyadh` (UTC+3), UTC
 * midnight is 03:00 local. Not a day boundary. Refused before a figure was read — which is exactly
 * why structure synced and metrics did not: structure never calls `/stats`.
 *
 * ## And the first page was taken for the whole answer
 *
 * The same reference gives the pagination contract — `limit` up to 200, `paging.next_link` for the
 * rest — and the connector read one response and returned. An account with 201 campaigns reported
 * 200 and lost the rest in silence. Identical in shape to `LINKEDIN-PAGE-001`, found there first
 * only because LinkedIn's default page size is 10 and bites on a small account.
 */
final class SnapchatReportingWindowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->configure('snapchat');
    }

    // ── The window ────────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** The range is the ACCOUNT's day boundary, carrying its own offset.
     *
     * Before the fix both bounds were `T00:00:00.000-00:00` whatever the account's timezone was.
     */
    public function test_the_request_uses_the_accounts_own_day_boundary(): void
    {
        $this->account('act-1', 'Asia/Riyadh');
        $this->fakeStats();

        $this->connector()->syncInsights('act-1', '2026-08-01', '2026-08-03');

        $query = $this->sentQuery();

        $this->assertSame(
            '2026-08-01T00:00:00.000+03:00',
            $query['start_time'],
            'SNAP-WINDOW-001: UTC midnight is 03:00 in Riyadh — not a day boundary, and Snapchat '
                .'refuses the request outright.',
        );
        $this->assertSame('2026-08-04T00:00:00.000+03:00', $query['end_time'], 'the end is EXCLUSIVE');
    }

    /** A different account, a different offset — nothing is defaulted or assumed. */
    public function test_a_different_timezone_produces_a_different_boundary(): void
    {
        $this->account('act-2', 'America/Los_Angeles');
        $this->fakeStats();

        $this->connector()->syncInsights('act-2', '2026-08-01', '2026-08-01');

        $query = $this->sentQuery();

        $this->assertStringEndsWith('-07:00', $query['start_time']);
        $this->assertSame('2026-08-01T00:00:00.000-07:00', $query['start_time']);
        // One day asked for is one whole day requested — never a zero-length range.
        $this->assertSame('2026-08-02T00:00:00.000-07:00', $query['end_time']);
    }

    /** An account whose timezone we never captured fails honestly rather than guessing one. */
    public function test_an_account_with_no_timezone_is_not_guessed_at(): void
    {
        $this->account('act-3', null);
        $this->fakeStats();

        $result = $this->connector()->syncInsights('act-3', '2026-08-01', '2026-08-03');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('timezone', mb_strtolower((string) $result->message));
        Http::assertNothingSent();
    }

    /** The window is asked for as whole days, and `to` is inclusive of that day. */
    public function test_a_single_day_covers_that_whole_day(): void
    {
        $window = ReportingWindow::localDays('Asia/Riyadh', '2026-08-16', '2026-08-16');

        $this->assertSame(1, $window->days());
        $this->assertSame('2026-08-16T00:00:00.000+03:00', $window->startIso());
        $this->assertSame('2026-08-17T00:00:00.000+03:00', $window->endIso());
    }

    /** A long backfill is split into provider-sized pieces rather than sent as one range. */
    public function test_a_long_window_can_be_chunked(): void
    {
        $chunks = ReportingWindow::localDays('Asia/Riyadh', '2026-06-01', '2026-06-30')->chunked(7);

        $this->assertCount(5, $chunks, '30 days in sevens is four full weeks and a remainder');
        $this->assertSame('2026-06-01T00:00:00.000+03:00', $chunks[0]->startIso());
        $this->assertSame('2026-07-01T00:00:00.000+03:00', end($chunks)->endIso());

        // Contiguous and non-overlapping, or the pieces do not reassemble into the window.
        for ($i = 1; $i < count($chunks); $i++) {
            $this->assertSame($chunks[$i - 1]->endIso(), $chunks[$i]->startIso());
        }
    }

    /** A timezone the server does not recognise is a data problem, said out loud. */
    public function test_an_unrecognised_timezone_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ReportingWindow::localDays('Mars/Olympus_Mons', '2026-08-01', '2026-08-02');
    }

    // ── The pages ─────────────────────────────────────────────────────────────────────────────

    /** **The second defect, pinned.** Every page is read, not only the first. */
    public function test_every_page_of_stats_is_read(): void
    {
        $this->account('act-1', 'Asia/Riyadh');

        Http::fake([
            'adsapi.snapchat.com/*stats*next=1*' => Http::response([
                'timeseries_stats' => [$this->series('cmp-2', 200.0)],
            ]),
            'adsapi.snapchat.com/*stats*' => Http::response([
                'timeseries_stats' => [$this->series('cmp-1', 100.0)],
                'paging' => ['next_link' => 'https://adsapi.snapchat.com/v1/adaccounts/act-1/stats?next=1'],
            ]),
        ]);

        $result = $this->connector()->syncInsights('act-1', '2026-08-01', '2026-08-02');

        $campaigns = collect($result->records)->pluck('campaign_id')->unique()->values()->all();

        $this->assertSame(
            ['cmp-1', 'cmp-2'],
            $campaigns,
            'SNAP-PAGING-001: the campaign on page two was dropped, silently — the account simply '
                .'reported less than it spent.',
        );
    }

    /** The documented maximum page size is asked for rather than left to the default. */
    public function test_the_documented_page_size_is_requested(): void
    {
        $this->account('act-1', 'Asia/Riyadh');
        $this->fakeStats();

        $this->connector()->syncInsights('act-1', '2026-08-01', '2026-08-02');

        $this->assertSame('200', (string) $this->sentQuery()['limit']);
    }

    /** The date on a row comes from the point's own boundary — the account's day, not ours. */
    public function test_a_row_is_dated_by_the_accounts_day(): void
    {
        $this->account('act-1', 'Asia/Riyadh');
        $this->fakeStats();

        $result = $this->connector()->syncInsights('act-1', '2026-08-01', '2026-08-02');

        $this->assertSame('2026-08-01', $result->records[0]['date']);
    }

    /**
     * A month-long backfill goes out as several provider-valid requests, not one long range.
     *
     * SNAP-WINDOW-001 §8. Snapchat's measurement reference states no hard cap for DAY granularity —
     * so an assumption either way would be a guess, and the conservative direction is the one where
     * a cap we have not been told about cannot break a customer's very first sync.
     */
    public function test_a_month_long_backfill_is_split_into_several_requests(): void
    {
        $this->account('act-1', 'Asia/Riyadh');
        $this->fakeStats();
        config()->set('integrations.chunking.max_days_per_request', 7);

        $this->connector()->syncInsights('act-1', '2026-06-01', '2026-06-30');

        $this->assertCount(5, Http::recorded(), '30 days in sevens is four full weeks and a remainder');
    }

    /** And the chunks tile the window exactly — no gap, no overlap, no lost day. */
    public function test_the_chunks_cover_the_whole_window_without_a_gap(): void
    {
        $this->account('act-1', 'Asia/Riyadh');
        $this->fakeStats();
        config()->set('integrations.chunking.max_days_per_request', 7);

        $this->connector()->syncInsights('act-1', '2026-06-01', '2026-06-30');

        $bounds = collect(Http::recorded())->map(function (array $pair): array {
            parse_str((string) parse_url((string) $pair[0]->url(), PHP_URL_QUERY), $q);

            return ['start' => (string) $q['start_time'], 'end' => (string) $q['end_time']];
        })->values()->all();

        $this->assertSame('2026-06-01T00:00:00.000+03:00', $bounds[0]['start']);
        $this->assertSame('2026-07-01T00:00:00.000+03:00', $bounds[count($bounds) - 1]['end']);

        for ($i = 1; $i < count($bounds); $i++) {
            $this->assertSame($bounds[$i - 1]['end'], $bounds[$i]['start'], 'a day fell between two chunks');
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    private function sentQuery(): array
    {
        $sent = Http::recorded();
        $this->assertNotEmpty($sent, 'no request was made at all');

        parse_str((string) parse_url((string) $sent[0][0]->url(), PHP_URL_QUERY), $query);

        /** @var array<string,string> $query */
        return $query;
    }

    /** @return array<string,mixed> */
    /**
     * One ad-account series carrying its per-campaign breakdown — SNAPCHAT'S OWN SHAPE.
     *
     * ## The fixture that hid a live defect for a month
     *
     * This used to return `['timeseries_stat' => ['id' => $campaignId, 'type' => 'CAMPAIGN',
     * 'timeseries' => [...]]]` — an invented shape, and the connector was written to match it. Every
     * test passed. On the live account it returned **zero rows for seven days**, because with
     * `breakdown=campaign` Snapchat answers with the AD ACCOUNT as the series and nests the campaigns
     * underneath:
     *
     * ```json
     * {"timeseries_stats":[{"timeseries_stat":{
     *    "id":"3072e77d-…","type":"AD_ACCOUNT",
     *    "breakdown_stats":{"campaign":[
     *      {"id":"20c79671-…","type":"CAMPAIGN","granularity":"DAY","timeseries":[{"stats":{…}}]}
     *    ]}}}]}
     * ```
     *
     * So `timeseries_stat.timeseries` does not exist at all, and `timeseries_stat.id` is the ad
     * account — not a campaign. The parser read an absent key, produced nothing, and the run was
     * recorded as «the provider returned no insight rows». It had returned 100.17 USD of spend,
     * 44,396 impressions and two purchases.
     *
     * The body below is that production response, reduced. A mock that is not the platform's shape
     * tests the mock.
     */
    // ── SNAP-CREATIVE-METRICS-001 ─────────────────────────────────────────────────────────────

    /**
     * The stats call, asked at the CREATIVE level.
     *
     * Every metric this product held was a campaign total, because `breakdown` was the literal
     * string `campaign`. The content library listed 1,451 real creatives with «لا توجد بيانات»
     * under each, and that was accurate — no creative-level row existed anywhere.
     */
    public function test_creative_level_stats_are_requested_and_returned_per_creative(): void
    {
        $this->account('act-1', 'Asia/Riyadh');

        Http::fake([
            '*/adaccounts/act-1/stats*' => Http::response(['timeseries_stats' => [
                $this->creativeSeries('cr-1', 12.5),
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $rows = $this->connector()->syncCreativeInsights('act-1', '2026-08-01', '2026-08-01')->records;

        $this->assertCount(1, $rows, 'The creative breakdown produced no rows.');
        $this->assertSame('cr-1', $rows[0]['campaign_id'], "The row must carry the provider's CREATIVE id.");
        $this->assertSame('2026-08-01', $rows[0]['date']);
        $this->assertEqualsWithDelta(12.5, (float) $rows[0]['spend'], 0.01, 'Micro-units must be divided.');

        // The request actually asked Snapchat for the creative level — not campaign with a new name.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'breakdown=creative'));
    }

    /**
     * The campaign path is untouched: it feeds `daily_metrics`, whose natural key has no room for a
     * creative. A change that quietly moved campaign totals to a different level would be far worse
     * than the missing creative rows.
     */
    public function test_the_campaign_level_still_asks_for_campaign(): void
    {
        $this->account('act-1', 'Asia/Riyadh');

        Http::fake([
            '*/adaccounts/act-1/stats*' => Http::response(['timeseries_stats' => [
                $this->series('cmp-1', 40.0),
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $rows = $this->connector()->syncInsights('act-1', '2026-08-01', '2026-08-01')->records;

        $this->assertSame('cmp-1', $rows[0]['campaign_id']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'breakdown=campaign'));
    }

    /** The account's own shape, with the creative breakdown Snapchat returns for `breakdown=creative`. */
    private function creativeSeries(string $creativeId, float $spend): array
    {
        return [
            'timeseries_stat' => [
                'id' => 'act-1',
                'type' => 'AD_ACCOUNT',
                'paging' => ['next_link' => ''],
                'start_time' => '2026-08-01T00:00:00.000+03:00',
                'end_time' => '2026-08-02T00:00:00.000+03:00',
                'breakdown_stats' => [
                    'creative' => [[
                        'id' => $creativeId,
                        'type' => 'CREATIVE',
                        'granularity' => 'DAY',
                        'timeseries' => [[
                            'start_time' => '2026-08-01T00:00:00.000+03:00',
                            'end_time' => '2026-08-02T00:00:00.000+03:00',
                            'stats' => ['spend' => $spend * 1_000_000, 'impressions' => 300],
                        ]],
                    ]],
                ],
            ],
        ];
    }

    private function series(string $campaignId, float $spend): array
    {
        return [
            'timeseries_stat' => [
                'id' => 'act-1',
                'type' => 'AD_ACCOUNT',
                'paging' => ['next_link' => ''],
                'start_time' => '2026-08-01T00:00:00.000+03:00',
                'end_time' => '2026-08-02T00:00:00.000+03:00',
                'breakdown_stats' => [
                    'campaign' => [[
                        'id' => $campaignId,
                        'type' => 'CAMPAIGN',
                        'granularity' => 'DAY',
                        'timeseries' => [[
                            // Snapchat returns the account's own offset; the row's date must follow it.
                            'start_time' => '2026-08-01T00:00:00.000+03:00',
                            'end_time' => '2026-08-02T00:00:00.000+03:00',
                            'stats' => ['spend' => $spend * 1_000_000, 'impressions' => 10],
                        ]],
                    ]],
                ],
            ],
        ];
    }

    private function fakeStats(): void
    {
        Http::fake([
            'adsapi.snapchat.com/*' => Http::response([
                'timeseries_stats' => [$this->series('cmp-1', 100.0)],
            ]),
        ]);
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function account(string $externalId, ?string $timezone): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connectionId(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'timezone' => $timezone,
            'discovered_at' => Carbon::now(),
        ]);
    }

    private ?string $connectionId = null;

    private function connectionId(): string
    {
        return $this->connectionId ??= (string) app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'snapchat',
        )->getKey();
    }

    private function connector(): ApiAdvertisingConnector
    {
        $connection = ProviderConnection::withoutGlobalScopes()
            ->findOrFail($this->connectionId());

        /** @var ApiAdvertisingConnector $connector */
        $connector = app(AdvertisingConnectorRegistry::class)->get('snapchat')->withConnection($connection);

        return $connector;
    }
}
