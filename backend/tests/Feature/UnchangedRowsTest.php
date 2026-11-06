<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\GroupedUpsert;
use App\Support\UnchangedRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — which rows of an upsert are «already there», decided by the database.
 *
 * The danger of this filter is one-sided: calling a row unchanged when it is not would keep an old
 * figure, so every case here that differs in any way must come back to be written.
 */
final class UnchangedRowsTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private const KEY = ['external_account_id', 'external_campaign_id', 'metric_key', 'metric_date', 'attribution_window', 'project_id'];

    private const COMPARE = ['value', 'original_currency', 'original_amount', 'rate_date', 'raw'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
        $this->buildWorld(1);
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return array<string, mixed> a stored spend row, as an upsert would offer it again */
    private function stored(): array
    {
        $row = (array) DB::table('daily_metrics')->where('metric_key', 'spend')->orderBy('id')->first();

        return [
            ...array_intersect_key($row, array_flip(self::KEY)),
            'value' => (float) $row['value'], 'original_currency' => $row['original_currency'],
            'original_amount' => (float) $row['original_amount'], 'rate_date' => null, 'raw' => null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function kept(array $rows): array
    {
        return UnchangedRows::drop('daily_metrics', $rows, self::KEY, self::COMPARE);
    }

    public function test_a_row_offered_exactly_as_stored_is_dropped_and_any_difference_is_kept(): void
    {
        $same = $this->stored();

        $this->assertSame([], $this->kept([$same]), 'the stored row offered again must not be written');

        $cases = [
            'a figure that moved' => [...$same, 'value' => $same['value'] + 1],
            'a figure that moved in the last stored decimal' => [...$same, 'value' => $same['value'] + 0.000001],
            'a figure that became null' => [...$same, 'value' => null],
            'a currency that changed' => [...$same, 'original_currency' => 'USD'],
            'a rate date that arrived' => [...$same, 'rate_date' => '2026-10-01'],
            'a row nobody stored' => [...$same, 'metric_key' => 'never_stored'],
            'another attribution window' => [...$same, 'attribution_window' => '7d_click'],
            'a row carrying a body' => [...$same, 'raw' => '{"a":1}'],
        ];

        foreach ($cases as $name => $row) {
            $this->assertSame([$row], $this->kept([$row]), "«{$name}» was dropped as unchanged");
        }

        // Past the six decimals the column keeps, a difference is not a difference: the upsert would store the same number.
        $this->assertSame([], $this->kept([[...$same, 'value' => $same['value'] + 0.0000004]]));
    }

    public function test_the_survivors_keep_their_order_and_a_stored_null_equals_an_offered_null(): void
    {
        $same = $this->stored();
        DB::table('daily_metrics')->where(array_intersect_key($same, array_flip(self::KEY)))->update(['value' => null]);

        $new = [...$same, 'metric_key' => 'first'];
        $moved = [...$same, 'value' => 9.5];
        $last = [...$same, 'metric_key' => 'last'];

        $this->assertSame([$new, $moved, $last], $this->kept([$new, [...$same, 'value' => null], $moved, $last]));
    }

    public function test_the_switch_writes_every_row_again(): void
    {
        config(['integrations.sync.skip_unchanged_rows' => false]);

        $same = $this->stored();

        $this->assertSame([$same], $this->kept([$same]));
    }

    public function test_a_grouped_upsert_leaves_an_unchanged_row_its_run_and_its_moment(): void
    {
        $rows = DB::table('entity_daily_metrics')->orderBy('id')->limit(2)->get()->map(fn ($r): array => (array) $r)->all();
        $offer = static fn (array $row, array $with): array => [
            ...array_intersect_key($row, array_flip(['id', 'tenant_id', 'project_id', 'provider', 'entity_type', 'entity_id', 'external_entity_id', 'metric_date', 'attribution_window'])),
            'impressions' => (float) $row['impressions'], 'clicks' => (float) $row['clicks'],
            'sync_run_id' => null, 'updated_at' => Carbon::parse('2026-10-22 00:00:00'),
            ...$with,
        ];

        GroupedUpsert::run(
            'entity_daily_metrics',
            [$offer($rows[0], []), $offer($rows[1], ['clicks' => (float) $rows[1]['clicks'] + 3])],
            ['entity_type', 'entity_id', 'metric_date', 'attribution_window', 'project_id'],
            ['impressions', 'clicks', 'sync_run_id', 'updated_at'],
            stamps: ['sync_run_id', 'updated_at'],
        );

        $after = DB::table('entity_daily_metrics')->whereIn('id', [$rows[0]['id'], $rows[1]['id']])->orderBy('id')->get();

        $this->assertSame($rows[0]['updated_at'], $after[0]->updated_at, 'the unchanged row was written again');
        $this->assertSame('2026-10-22 00:00:00', $after[1]->updated_at, 'the changed row was not written');
        $this->assertEqualsWithDelta((float) $rows[1]['clicks'] + 3, (float) $after[1]->clicks, 0.000001);
        // A column the offer did not carry is left as it was, as before.
        $this->assertSame($rows[1]['spend'], $after[1]->spend);
    }
}
