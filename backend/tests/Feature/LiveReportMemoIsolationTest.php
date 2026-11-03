<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Support\AccountCampaignCeiling;
use App\Domains\Reports\Support\ReportReadMemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — batching and remembering reads must not widen what a link shows or carry an answer
 * from one tenant, project, request or build to another.
 *
 * The visibility rule is checked against stored rows, not against a payload from the same code: the link
 * is held to the sum of exactly the accounts it may show. `UNBOUND_ROWS_VISIBLE` is read from the class,
 * so this passes under `true` today and under `false` the day deny-by-default lands (verified by flipping
 * the constant locally; see the matrix row).
 */
final class LiveReportMemoIsolationTest extends TestCase
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

    /** @param list<string> $accountIds */
    private function storedSpend(array $accountIds): float
    {
        return (float) DB::table('daily_metrics')
            ->whereIn('external_account_id', $accountIds)
            ->where('metric_key', 'spend')
            ->whereBetween('metric_date', [self::WORLD_FROM, self::WORLD_TO])
            ->sum('value');
    }

    private function liveTotalsSpend(string $raw): float
    {
        return (float) $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.totals.spend');
    }

    public function test_a_link_shows_exactly_the_accounts_it_may_and_never_a_deselected_or_rebound_one(): void
    {
        $this->buildWorld(campaignsPerAccount: 4);
        $unbound = $this->seedUnboundAccount();

        $granted = [...$this->boundAccounts, $unbound];
        [, $raw] = $this->liveLink(accounts: $granted);

        $expected = $this->storedSpend(array_map(fn (ExternalAccount $a): string => (string) $a->id, $this->boundAccounts))
            + ($this->unboundRowsShow() ? $this->storedSpend([(string) $unbound->id]) : 0.0);

        $this->assertEqualsWithDelta($expected, $this->liveTotalsSpend($raw), 0.01, 'the link\'s spend is not the spend of the accounts it may show');

        // And no surface names a campaign of an account the link may not show, whichever way the account was excluded.
        $body = json_encode($this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ghost snapchat-act-deselected', $body, 'a deselected account\'s campaign is on the link');
        $this->assertStringNotContainsString('other meta-act-elsewhere', $body, 'an account bound to another project is on the link');
        $this->assertSame($this->unboundRowsShow(), str_contains((string) $body, 'unbound tiktok-act-unbound'), 'an unbound account\'s campaign shows or hides against the visibility constant');
    }

    public function test_a_link_over_some_accounts_shows_only_those_even_when_a_wider_link_was_built_first_in_the_same_process(): void
    {
        $this->buildWorld(campaignsPerAccount: 4);

        $meta = array_values(array_filter($this->boundAccounts, fn (ExternalAccount $a): bool => $a->provider === 'meta'));
        $snap = array_values(array_filter($this->boundAccounts, fn (ExternalAccount $a): bool => $a->provider === 'snapchat'));

        [, $everyone] = $this->liveLink();
        [, $metaOnly] = $this->liveLink(accounts: $meta);
        [, $snapOnly] = $this->liveLink(accounts: $snap);

        $ids = fn (array $accounts): array => array_map(fn (ExternalAccount $a): string => (string) $a->id, $accounts);

        // Each is built after the others in one process: a memo keyed too loosely would hand a later link an earlier link's campaigns.
        $this->assertEqualsWithDelta($this->storedSpend($ids($this->boundAccounts)), $this->liveTotalsSpend($everyone), 0.01);
        $this->assertEqualsWithDelta($this->storedSpend($ids($meta)), $this->liveTotalsSpend($metaOnly), 0.01);
        $this->assertEqualsWithDelta($this->storedSpend($ids($snap)), $this->liveTotalsSpend($snapOnly), 0.01);
        $this->assertEqualsWithDelta($this->storedSpend($ids($meta)), $this->liveTotalsSpend($metaOnly), 0.01, 'the second read of the same link changed');
    }

    public function test_nothing_is_remembered_between_two_builds_of_the_same_link(): void
    {
        $this->buildWorld(campaignsPerAccount: 4);
        [$share, $raw] = $this->liveLink();

        $before = $this->liveTotalsSpend($raw);

        // New spend lands under a bound account between the two requests: the second must see it.
        $this->growWorld(extra: 1, alreadyHave: 4);

        $after = $this->liveTotalsSpend($raw);

        $this->assertGreaterThan($before, $after, 'the second request answered from the first request\'s memo');
        $this->assertEqualsWithDelta(
            $this->storedSpend(array_map(fn (ExternalAccount $a): string => (string) $a->id, $this->boundAccounts)),
            $after,
            0.01,
        );
        $this->assertNotNull($share);
    }

    public function test_the_ceiling_resolves_afresh_outside_a_build(): void
    {
        $this->buildWorld(campaignsPerAccount: 2);
        $account = (string) $this->boundAccounts[0]->id;

        $first = AccountCampaignCeiling::campaigns([], [$account]);

        $this->growWorld(extra: 1, alreadyHave: 2);

        $this->assertGreaterThan(count($first), count(AccountCampaignCeiling::campaigns([], [$account])), 'a ceiling read outside any build was remembered');
    }

    public function test_the_memo_answers_once_inside_a_build_and_never_across_tenants_projects_or_builds(): void
    {
        $calls = 0;
        $ask = function () use (&$calls): int {
            return ReportReadMemo::remember('k', function () use (&$calls): int {
                return ++$calls;
            });
        };

        // Outside a build nothing is remembered.
        $this->assertSame(1, $ask());
        $this->assertSame(2, $ask());

        $calls = 0;
        ReportReadMemo::within('tenant-a', 'project-1', function () use ($ask, &$calls): void {
            $this->assertSame(1, $ask());
            $this->assertSame(1, $ask(), 'a repeated read inside one build was asked again');

            // Another project of the same tenant, and another tenant, each get a store of their own and cannot see this one.
            ReportReadMemo::within('tenant-a', 'project-2', function () use ($ask): void {
                $this->assertSame(2, $ask());
                $this->assertSame(2, $ask());
            });
            ReportReadMemo::within('tenant-b', 'project-1', function () use ($ask): void {
                $this->assertSame(3, $ask());
            });

            // Back in the outer build, its own answer is intact; a nested build for the SAME identity shares it.
            $this->assertSame(1, $ask());
            ReportReadMemo::within('tenant-a', 'project-1', fn () => $this->assertSame(1, $ask()));
        });

        // The next build starts empty.
        ReportReadMemo::within('tenant-a', 'project-1', fn () => $this->assertSame(4, $ask()));
    }

    public function test_an_exception_inside_a_build_leaves_nothing_behind(): void
    {
        $calls = 0;
        $ask = function () use (&$calls): int {
            return ReportReadMemo::remember('k', function () use (&$calls): int {
                return ++$calls;
            });
        };

        try {
            ReportReadMemo::within('t', 'p', function () use ($ask): void {
                $ask();

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(2, $ask(), 'a failed build left its answers for the next caller');
    }

    public function test_a_read_that_throws_is_not_remembered_as_an_answer(): void
    {
        ReportReadMemo::within('t', 'p', function (): void {
            try {
                ReportReadMemo::remember('k', fn () => throw new \RuntimeException('read failed'));
            } catch (\RuntimeException) {
            }

            $this->assertSame('ok', ReportReadMemo::remember('k', fn (): string => 'ok'));
        });
    }

    public function test_the_service_opens_the_memo_for_the_share_it_serves_and_closes_it(): void
    {
        $this->buildWorld(campaignsPerAccount: 2);
        [$share] = $this->liveLink();

        app(LiveReportService::class)->build($share, [], 'SAR');

        // After the build the memo is closed: a read outside it is asked every time.
        $calls = 0;
        ReportReadMemo::remember('after', function () use (&$calls): int {
            return ++$calls;
        });
        ReportReadMemo::remember('after', function () use (&$calls): int {
            return ++$calls;
        });
        $this->assertSame(2, $calls);
    }
}
