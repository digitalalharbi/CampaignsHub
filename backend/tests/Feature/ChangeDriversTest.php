<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ChangeDrivers;
use App\Domains\Metrics\Services\ChangeTimeline;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-DIFFERENTIATION-001 — «why», and the refusals that keep it honest.
 *
 * The dashboard says spend rose 14%. That is a fact nobody can act on: the rise is the sum of every
 * platform underneath, some up, some down, and the ones that matter are usually not the biggest. So
 * Analytics decomposes the movement — and most of what this file asserts is when it DECLINES to,
 * because a diagnostic surface that always produces a finding teaches its reader to ignore it.
 */
final class ChangeDriversTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'cd-a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'cd-c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    private function campaign(string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $name, 'status' => 'active']);
    }

    private function metric(UnifiedCampaign $c, string $provider, string $key, ?float $value, string $date, array $over = []): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id,
            'provider' => $provider,
            'metric_key' => $key,
            'metric_date' => $date,
            'value' => $value,
            ...$over,
        ]);
    }

    private function drivers(string $metric = 'spend', string $by = 'provider'): array
    {
        return (new ChangeDrivers(app(MetricsAggregator::class)->forProjects([$this->project->id])))
            ->forMetric(
                $metric, $by,
                Carbon::parse('2026-07-08'), Carbon::parse('2026-07-14'),
                Carbon::parse('2026-07-01'), Carbon::parse('2026-07-07'),
            );
    }

    /** The whole point: the account moved, and this says which platform moved it. */
    public function test_it_names_the_platform_that_moved_the_account(): void
    {
        $meta = $this->campaign('M');
        $google = $this->campaign('G');

        // Meta doubles; Google slips a little. The net is up, and Meta is why.
        $this->metric($meta, 'meta', 'spend', 1000, '2026-07-03');
        $this->metric($meta, 'meta', 'spend', 3000, '2026-07-10');
        $this->metric($google, 'google', 'spend', 2000, '2026-07-03');
        $this->metric($google, 'google', 'spend', 1800, '2026-07-10');

        $out = $this->drivers();

        $this->assertTrue($out['decomposable']);
        $this->assertNull($out['reason']);
        $this->assertSame(1800.0, $out['change'], 'the account movement is the sum of its parts');

        $this->assertSame('meta', $out['drivers'][0]['key'], 'the biggest mover is not first');
        $this->assertSame(2000.0, $out['drivers'][0]['change']);
        $this->assertSame('up', $out['drivers'][0]['direction']);

        $this->assertSame('google', $out['drivers'][1]['key']);
        $this->assertSame(-200.0, $out['drivers'][1]['change']);
        $this->assertSame('down', $out['drivers'][1]['direction']);
    }

    /**
     * Share is of the GROSS movement, and the arithmetic is why.
     *
     * Dividing by the NET change is the obvious choice and it produces nonsense: with one platform up
     * 2,000 and another down 200, the net is 1,800 and Meta's «share» becomes 111%. The reader's
     * question is «how much of what happened was this one», and what happened is the distance
     * travelled — 2,200 — of which Meta is 91%.
     */
    public function test_a_share_is_of_the_distance_travelled_not_the_net(): void
    {
        $this->metric($this->campaign('M'), 'meta', 'spend', 1000, '2026-07-03');
        $this->metric($this->campaign('M2'), 'meta', 'spend', 3000, '2026-07-10');
        $this->metric($this->campaign('G'), 'google', 'spend', 2000, '2026-07-03');
        $this->metric($this->campaign('G2'), 'google', 'spend', 1800, '2026-07-10');

        $shares = array_column($this->drivers()['drivers'], 'share');

        foreach ($shares as $share) {
            $this->assertLessThanOrEqual(1.0, $share, 'a contribution exceeded the whole movement');
        }
        $this->assertEqualsWithDelta(0.909, $shares[0], 0.01);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.001);
    }

    /**
     * **A ratio has no parts that add to it.**
     *
     * One campaign's CPA and another's do not sum to the account's, so a «contribution to CPA» is a
     * number with no referent. It is the single most tempting wrong answer in this whole feature —
     * the arithmetic runs happily and produces something that looks like an insight — so it is
     * refused in one place rather than avoided at each call site.
     */
    public function test_it_refuses_to_decompose_a_ratio(): void
    {
        $this->metric($this->campaign('M'), 'meta', 'spend', 1000, '2026-07-10');

        foreach (['cpa', 'roas', 'ctr', 'cpc', 'conversion_rate'] as $ratio) {
            $out = $this->drivers($ratio);

            $this->assertFalse($out['decomposable'], "{$ratio} was decomposed");
            $this->assertSame('metric_is_not_additive', $out['reason']);
            $this->assertSame([], $out['drivers']);
        }
    }

    /** A first period has nothing to have moved from, and a driver against zero is just a ranking. */
    public function test_it_declines_without_a_previous_window(): void
    {
        $this->metric($this->campaign('M'), 'meta', 'spend', 1000, '2026-07-10');

        $out = (new ChangeDrivers(app(MetricsAggregator::class)->forProjects([$this->project->id])))
            ->forMetric('spend', 'provider', Carbon::parse('2026-07-08'), Carbon::parse('2026-07-14'), null, null);

        $this->assertTrue($out['decomposable'], 'spend is decomposable — the WINDOW is what is missing');
        $this->assertSame('no_previous_period', $out['reason']);
        $this->assertSame([], $out['drivers']);
    }

    /**
     * A withheld figure is unquantifiable, not zero — FX-001.
     *
     * A platform whose spend awaits an exchange rate did not contribute nothing to the account's
     * movement. Counting it as zero would hand its share to whichever platform happened to be
     * measurable, which is a false attribution rather than a missing one.
     */
    public function test_a_withheld_platform_is_named_rather_than_counted_as_zero(): void
    {
        $this->metric($this->campaign('M'), 'meta', 'spend', 1000, '2026-07-03');
        $this->metric($this->campaign('M2'), 'meta', 'spend', 3000, '2026-07-10');

        // Snapchat reported 500 USD and no rate exists to convert it — `value` null, original kept.
        $snap = $this->campaign('S');
        $this->metric($snap, 'snapchat', 'spend', null, '2026-07-03', ['original_amount' => 500, 'original_currency' => 'USD']);
        $this->metric($snap, 'snapchat', 'spend', null, '2026-07-10', ['original_amount' => 900, 'original_currency' => 'USD']);

        $out = $this->drivers();

        $this->assertContains('snapchat', $out['unquantifiable']);
        $this->assertNotContains('snapchat', array_column($out['drivers'], 'key'), 'a withheld platform was ranked');
        foreach ($out['drivers'] as $d) {
            $this->assertNotSame(0.0, $d['change'], 'a withheld figure was counted as no movement');
        }
    }

    /**
     * The dimension a platform split cannot answer — ANALYTICS-DIFFERENTIATION-001.
     *
     * «The account spent the same and returned less» is usually a MIX shift: money moving from one
     * objective to another. Every platform can look unchanged while the answer sits in what the money
     * was BOUGHT for, and no amount of per-platform detail shows it.
     */
    public function test_it_decomposes_by_what_the_money_was_bought_for(): void
    {
        $awareness = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Brand', 'status' => 'active', 'objective' => 'awareness',
        ]);
        $sales = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Sales', 'status' => 'active', 'objective' => 'sales',
        ]);

        // The account's spend is flat at 4,000 — and half of it moved from sales to awareness.
        $this->metric($sales, 'meta', 'spend', 3000, '2026-07-03');
        $this->metric($awareness, 'meta', 'spend', 1000, '2026-07-03');
        $this->metric($sales, 'meta', 'spend', 1000, '2026-07-10');
        $this->metric($awareness, 'meta', 'spend', 3000, '2026-07-10');

        $out = $this->drivers('spend', 'objective');

        $this->assertSame(0.0, $out['change'], 'the account total did not hold still — the fixture is wrong');

        $byKey = array_column($out['drivers'], null, 'key');

        $this->assertSame(2000.0, $byKey['awareness']['change']);
        $this->assertSame(-2000.0, $byKey['sales']['change']);

        /*
         * And the platform split has NO MOVEMENT to report, which is the entire point.
         *
         * Meta is still listed — it spent in both windows, and a platform that held steady while the
         * mix shifted underneath it is context worth having. What it does not have is a change: a
         * reader looking only at platforms would conclude nothing happened, and 2,000 SAR moved from
         * sales to awareness.
         */
        $byProvider = $this->drivers('spend', 'provider');

        foreach ($byProvider['drivers'] as $d) {
            $this->assertSame(0.0, $d['change'], 'the platform split invented a movement');
        }
    }

    /**
     * PLATFORM-DECISION-ANALYTICS-001 — account contribution, which the platform total hides.
     *
     * An agency running two Meta accounts for one client can have a collapse in one exactly offset by
     * a rise in the other. The platform reports no change, and the thing worth acting on is invisible
     * until the split goes one level down.
     */
    public function test_it_decomposes_by_ad_account(): void
    {
        $a = $this->campaign('A');
        $b = $this->campaign('B');
        $accountA = (string) Str::uuid();
        $accountB = (string) Str::uuid();

        foreach ([['2026-07-03', 3000, 1000], ['2026-07-10', 1000, 3000]] as [$date, $spendA, $spendB]) {
            $this->metric($a, 'meta', 'spend', (float) $spendA, $date, ['external_account_id' => $accountA]);
            $this->metric($b, 'meta', 'spend', (float) $spendB, $date, ['external_account_id' => $accountB]);
        }

        $byAccount = $this->drivers('spend', 'account');
        $changes = array_column($byAccount['drivers'], 'change', 'key');

        $this->assertSame(-2000.0, $changes[$accountA] ?? null);
        $this->assertSame(2000.0, $changes[$accountB] ?? null);

        // …and the platform above them reports no movement, which is the point.
        foreach ($this->drivers('spend', 'provider')['drivers'] as $d) {
            $this->assertSame(0.0, $d['change'], 'the platform split invented a movement');
        }
    }

    // ---- the change timeline ------------------------------------------------------------------

    /** @param list<float> $daily one value per day, starting 2026-07-01 */
    private function series(array $daily): array
    {
        $c = $this->campaign('T');
        foreach ($daily as $i => $v) {
            $this->metric($c, 'meta', 'spend', $v, Carbon::parse('2026-07-01')->addDays($i)->toDateString());
        }

        return (new ChangeTimeline(app(MetricsAggregator::class)->forProjects([$this->project->id])))
            ->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-01')->addDays(count($daily) - 1), ['spend']);
    }

    /**
     * The day worth asking about, named — and measured against what came BEFORE it.
     *
     * A trailing baseline is the only one a reader could have acted on. «This day was unusual given
     * the fortnight before it» is a sentence about their campaign; «unusual given the whole month,
     * including the days after» is hindsight dressed as a signal.
     */
    public function test_it_names_the_day_that_departed_from_its_own_baseline(): void
    {
        $out = $this->series([100, 102, 98, 101, 99, 100, 103, 900, 101, 99]);

        $this->assertNull($out['reason']);
        $this->assertCount(1, $out['points'], 'a steady series produced more than the one spike in it');
        $this->assertSame('2026-07-08', $out['points'][0]['date']);
        $this->assertSame(900.0, $out['points'][0]['value']);
        $this->assertSame('up', $out['points'][0]['direction']);
        $this->assertGreaterThan(3.5, abs($out['points'][0]['deviation']));
    }

    /**
     * **A flat series has no scale to be unusual against.**
     *
     * Every day identical means a MAD of zero, and dividing by it would make any departure infinite —
     * so an account that spent exactly the same every day would report its first different day as an
     * extreme anomaly. That is arithmetic, not a finding.
     */
    public function test_a_series_that_never_varied_reports_nothing(): void
    {
        $this->assertSame([], $this->series([100, 100, 100, 100, 100, 100, 100, 100])['points']);
    }

    /**
     * …and the day AFTER a flat run is not an anomaly for being one riyal different.
     *
     * This is the case that proves the guard rather than merely surviving it. With no scale to
     * measure against, any departure divides by zero — so a single riyal above a week of identical
     * days would be reported as an extreme finding. The series below is flat and then moves by 1%,
     * which is nothing; a threshold applied to a zero baseline would call it everything.
     */
    public function test_a_trivial_move_after_a_flat_run_is_not_an_anomaly(): void
    {
        $out = $this->series([100, 100, 100, 100, 100, 100, 101, 100]);

        $this->assertSame([], $out['points'], 'a 1% move was reported as a departure');
        $this->assertSame('no_day_departed_from_its_own_baseline', $out['reason']);
    }

    /** Too few days to have a baseline at all — said, rather than guessed at. */
    public function test_a_window_too_short_declines(): void
    {
        $out = $this->series([100, 120, 90]);

        $this->assertSame([], $out['points']);
        $this->assertSame('window_too_short_to_have_a_baseline', $out['reason']);
    }

    /** An ordinary series is not an anomaly, however much it moves around. */
    public function test_normal_variation_is_not_a_finding(): void
    {
        $out = $this->series([100, 130, 90, 115, 95, 120, 105, 110, 98, 125]);

        $this->assertSame([], $out['points'], 'ordinary variation was reported as notable');
    }

    /** Nothing reported the metric at all — an absence, said as one. */
    public function test_it_says_when_no_entity_reported_the_metric(): void
    {
        $this->metric($this->campaign('M'), 'meta', 'clicks', 30, '2026-07-10');

        $out = $this->drivers('purchases');

        $this->assertSame('no_entity_reported_this_metric', $out['reason']);
        $this->assertSame([], $out['drivers']);
    }

    /**
     * ANALYTICS-DIFFERENTIATION-001 — the ad-set grain, which a campaign total hides.
     *
     * A campaign whose spend held steady while one ad set doubled and another stopped looks, at the
     * campaign grain, like a week in which nothing happened. This is the level an operator can
     * actually act on, and the last dimension the decomposition was missing.
     */
    public function test_it_decomposes_a_change_across_ad_sets(): void
    {
        $rows = static fn (float $a, float $b): array => [
            ['entity_id' => 'as-1', 'name' => 'Riyadh — broad', 'spend' => $a, 'spend_withheld_rows' => 0, 'spend_original' => $a, 'money_original_currencies' => 1, 'money_original_currency' => 'SAR'],
            ['entity_id' => 'as-2', 'name' => 'Jeddah — lookalike', 'spend' => $b, 'spend_withheld_rows' => 0, 'spend_original' => $b, 'money_original_currencies' => 1, 'money_original_currency' => 'SAR'],
        ];

        $drivers = new ChangeDrivers(
            app(MetricsAggregator::class)->forProjects([$this->project->id]),
            static fn ($from, $to): array => $from->toDateString() === '2026-08-01' ? $rows(1000, 1000) : $rows(2000, 0),
        );

        $out = $drivers->forMetric(
            'spend', 'ad_set',
            Carbon::parse('2026-08-08'), Carbon::parse('2026-08-14'),
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-07'),
        );

        $this->assertNull($out['reason']);

        $byName = [];
        foreach ($out['drivers'] as $d) {
            $byName[$d['name']] = $d;
        }

        // The campaign total did not move at all; underneath it, two ad sets moved 1,000 each.
        $this->assertSame(0.0, round($out['change'], 6));
        $this->assertSame(1000.0, round($byName['Riyadh — broad']['change'], 6));
        $this->assertSame(-1000.0, round($byName['Jeddah — lookalike']['change'], 6));

        // Share is of GROSS movement, which is the only reading that survives a net of zero.
        $this->assertEqualsWithDelta(0.5, $byName['Riyadh — broad']['share'], 0.001);
    }

    /**
     * With no ad-set source the dimension REFUSES rather than answering about providers.
     *
     * A dimension that silently falls back answers a question nobody asked, and the reader cannot
     * tell — the figures are real, they are simply about something else.
     *
     * PROVIDER ROWS ARE SEEDED HERE ON PURPOSE. The first version of this test asserted an empty
     * result against an empty database, so a fallback produced the same empty answer and the test
     * passed while the defect was present — verified by injecting exactly that fallback. The rows
     * below are what a fallback would return, which is what makes the refusal observable.
     */
    public function test_the_ad_set_dimension_refuses_when_no_source_was_supplied(): void
    {
        $campaign = $this->campaign('Has provider rows');
        $this->metric($campaign, 'meta', 'spend', 1000, '2026-08-03');
        $this->metric($campaign, 'meta', 'spend', 4000, '2026-08-10');

        // The same aggregator DOES answer for providers — so an empty ad-set answer is a refusal.
        $bySomethingElse = (new ChangeDrivers(app(MetricsAggregator::class)->forProjects([$this->project->id])))->forMetric(
            'spend', 'provider',
            Carbon::parse('2026-08-08'), Carbon::parse('2026-08-14'),
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-07'),
        );
        $this->assertNotSame([], $bySomethingElse['drivers'], 'the fixture proves nothing: providers answered nothing either');

        $out = (new ChangeDrivers(app(MetricsAggregator::class)->forProjects([$this->project->id])))->forMetric(
            'spend', 'ad_set',
            Carbon::parse('2026-08-08'), Carbon::parse('2026-08-14'),
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-07'),
        );

        $this->assertSame([], $out['drivers'], 'the ad-set dimension answered with another dimension’s rows');
        $this->assertSame('no_entity_reported_this_metric', $out['reason']);
    }

    /** An ad set whose name has gone keeps its figures and loses its label — never a UUID. */
    public function test_an_ad_set_without_a_name_is_never_labelled_with_its_id(): void
    {
        $rows = static fn (float $v): array => [
            ['entity_id' => '7f3f1aa2-2736-5f14-9c1e-000000000001', 'name' => null, 'spend' => $v, 'spend_withheld_rows' => 0, 'spend_original' => $v, 'money_original_currencies' => 1, 'money_original_currency' => 'SAR'],
        ];

        $out = (new ChangeDrivers(
            app(MetricsAggregator::class)->forProjects([$this->project->id]),
            static fn ($from, $to): array => $from->toDateString() === '2026-08-01' ? $rows(500) : $rows(900),
        ))->forMetric(
            'spend', 'ad_set',
            Carbon::parse('2026-08-08'), Carbon::parse('2026-08-14'),
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-07'),
        );

        $this->assertNotSame([], $out['drivers']);
        foreach ($out['drivers'] as $d) {
            $this->assertNull($d['name'], 'an ad set was labelled with its own id');
            $this->assertStringNotContainsString('7f3f1aa2', (string) ($d['name'] ?? ''));
        }
    }
}
