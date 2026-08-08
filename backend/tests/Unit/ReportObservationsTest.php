<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportObservations;
use PHPUnit\Framework\TestCase;

/**
 * §14.7 — every note is derived from this report's own figures, or it is not written.
 *
 * A unit test rather than a feature one, deliberately: the detectors take a snapshot array and
 * return notes, so the interesting cases are shapes of data — a missing denominator, a single
 * platform, a movement too small to mean anything — and a database would only make them slower to
 * express and easier to get subtly wrong.
 *
 * The negative assertions carry most of the weight here. What separates analysis from decoration is
 * what the engine DECLINES to say.
 */
final class ReportObservationsTest extends TestCase
{
    private function build(array $data, string $objective = 'sales'): array
    {
        return (new ReportObservations)->build(new ReportObjectiveLens($objective), $data + [
            'currency' => 'SAR',
            'kpis' => [],
            'delta' => [],
            'previous' => [],
            'reported' => [],
            'metric_set' => [],
            'platforms' => [],
            'budget' => [],
        ]);
    }

    private function kinds(array $notes): array
    {
        return array_column($notes, 'kind');
    }

    /** A quiet period produces nothing — silence is the honest output, not filler advice. */
    public function test_a_period_with_nothing_notable_produces_no_notes(): void
    {
        $notes = $this->build([
            'kpis' => ['roas' => 4.0, 'ctr' => 0.02, 'cpa' => 30.0],
            'delta' => ['roas' => 0.03, 'ctr' => -0.01, 'cpa' => 0.02],
        ]);

        $this->assertSame([], $notes);
    }

    /** A movement below the noise floor is not a finding. */
    public function test_a_small_movement_is_not_reported_as_a_change(): void
    {
        $notes = $this->build([
            'kpis' => ['roas' => 4.0],
            'delta' => ['roas' => 0.12],
        ]);

        $this->assertNotContains('period_comparison', $this->kinds($notes));
    }

    /** …and a real one is, with the figure that made it true. */
    public function test_a_material_movement_is_reported_with_its_numbers(): void
    {
        $notes = $this->build([
            'kpis' => ['roas' => 4.0],
            'delta' => ['roas' => 0.4],
        ]);

        $note = $notes[0];
        $this->assertSame('period_comparison', $note['kind']);
        $this->assertSame('positive', $note['severity'], 'a rising return is not a warning');
        $this->assertStringContainsString('4.00×', $note['detail']);
        $this->assertStringContainsString('40.0%', $note['detail']);
    }

    /** The same movement on a cost is the other direction of good. */
    public function test_a_rising_cost_per_result_is_a_warning_on_a_leads_report(): void
    {
        $notes = $this->build([
            'kpis' => ['cpa' => 60.0],
            'delta' => ['cpa' => 0.5],
        ], 'leads');

        $this->assertSame('period_comparison', $notes[0]['kind']);
        $this->assertSame('warning', $notes[0]['severity']);
    }

    /**
     * A cost per RESULT rising on a brand report is not a finding, because it was never a target.
     *
     * This is the §14.6 rule reaching the analysis: an awareness campaign's CPA is an accident of
     * arithmetic, and alerting on it fills a brand report with alarms about it working as intended.
     */
    public function test_a_brand_report_never_warns_about_a_cost_per_order(): void
    {
        $notes = $this->build([
            'kpis' => ['cpa' => 900.0, 'cpm' => 20.0],
            // The cost per order tripled; the cost of reaching people also moved, so the engine has
            // something true to say and the absence of the CPA note is a choice rather than silence.
            'delta' => ['cpa' => 3.0, 'cpm' => 0.4],
        ], 'awareness');

        $this->assertNotSame([], $notes, 'the engine said nothing at all, so this proves nothing');
        $this->assertSame('cpm', $notes[0]['metric']);
        foreach ($notes as $note) {
            $this->assertNotSame('cpa', $note['metric']);
        }
    }

    /**
     * Frequency needs reach to have been reported.
     *
     * It is impressions ÷ reach, so on a platform that publishes no reach the ratio is not low — it
     * is absent, and a saturation warning derived from it would be invented.
     */
    public function test_frequency_saturation_needs_reach_to_have_been_reported(): void
    {
        $unreported = $this->build([
            'kpis' => ['frequency' => 6.0],
            'reported' => ['reach' => false],
        ]);
        $this->assertNotContains('frequency_saturation', $this->kinds($unreported));

        $reported = $this->build([
            'kpis' => ['frequency' => 6.0],
            'reported' => ['reach' => true],
        ]);
        $this->assertContains('frequency_saturation', $this->kinds($reported));
        $this->assertStringContainsString('6.0 مرة', $reported[0]['detail']);
    }

    /** A campaign with no budget has no plan to deviate from. */
    public function test_a_campaign_without_a_budget_is_never_accused_of_overspending(): void
    {
        $notes = $this->build([
            'budget' => [
                ['campaign_id' => 'a', 'campaign_name' => 'بلا ميزانية', 'budget' => 0, 'spent' => 9000, 'pace' => 4.0],
            ],
        ]);

        $this->assertSame([], $notes);
    }

    /** Real over-pacing is critical, and names the money. */
    public function test_overspending_against_a_real_budget_is_critical(): void
    {
        $notes = $this->build([
            'budget' => [
                ['campaign_id' => 'a', 'campaign_name' => 'حملة الصيف', 'budget' => 10000.0, 'spent' => 8000.0, 'pace' => 1.6],
            ],
        ]);

        $this->assertSame('budget_pace', $notes[0]['kind']);
        $this->assertSame('critical', $notes[0]['severity']);
        $this->assertStringContainsString('حملة الصيف', $notes[0]['title']);
        $this->assertStringContainsString('8,000.00 SAR', $notes[0]['detail']);
    }

    /**
     * «Move money to your best platform» needs somewhere to move it FROM.
     *
     * With one platform the best and the worst are the same row, and the advice is a sentence with
     * no content dressed as an insight.
     */
    public function test_reallocation_needs_two_platforms_and_a_real_gap(): void
    {
        $single = $this->build([
            'platforms' => [['provider' => 'meta', 'spend' => 5000, 'roas' => 6.0]],
        ]);
        $this->assertNotContains('reallocation', $this->kinds($single));

        $close = $this->build([
            'platforms' => [
                ['provider' => 'meta', 'spend' => 5000, 'roas' => 6.0],
                ['provider' => 'tiktok', 'spend' => 5000, 'roas' => 5.0],
            ],
        ]);
        $this->assertNotContains('reallocation', $this->kinds($close), 'a 17% gap is not worth moving budget over');

        $wide = $this->build([
            'platforms' => [
                ['provider' => 'meta', 'spend' => 5000, 'roas' => 8.0],
                ['provider' => 'tiktok', 'spend' => 5000, 'roas' => 2.0],
            ],
        ]);
        $note = $wide[array_search('reallocation', $this->kinds($wide), true)];
        $this->assertStringContainsString('meta', $note['title']);
        $this->assertStringContainsString('75%', $note['detail']);
    }

    /** A platform with no spend is not a candidate to move money to. */
    public function test_a_platform_that_spent_nothing_is_not_a_reallocation_target(): void
    {
        $notes = $this->build([
            'platforms' => [
                ['provider' => 'meta', 'spend' => 5000, 'roas' => 2.0],
                ['provider' => 'linkedin', 'spend' => 0, 'roas' => 40.0],
            ],
        ]);

        $this->assertNotContains('reallocation', $this->kinds($notes));
    }

    /** The missing-metric note explains a card that says «لم ترسله المنصة», in the reader's words. */
    public function test_metrics_no_platform_sends_are_explained_rather_than_left_blank(): void
    {
        $notes = $this->build([
            'metric_set' => ['impressions', 'reach', 'video_views'],
            'reported' => ['impressions' => true, 'reach' => false, 'video_views' => false],
        ], 'awareness');

        $note = $notes[array_search('data_gap', $this->kinds($notes), true)];
        $this->assertStringContainsString('الوصول', $note['detail']);
        $this->assertStringContainsString('مشاهدات الفيديو', $note['detail']);
        $this->assertStringContainsString('بدلًا من صفر', $note['detail']);
    }

    /** A failed sync outranks everything: the figures above it may not be what they claim. */
    public function test_a_failed_sync_is_critical_and_names_the_source(): void
    {
        $notes = $this->build([
            'freshness' => ['state' => 'failed', 'failing' => [['name' => 'Snapchat Ads', 'provider' => 'snapchat']]],
        ]);

        $this->assertSame('stale_data', $notes[0]['kind']);
        $this->assertSame('critical', $notes[0]['severity']);
        $this->assertStringContainsString('Snapchat Ads', $notes[0]['detail']);
    }

    /** Fresh data says nothing — a report is not required to congratulate itself. */
    public function test_fresh_data_produces_no_note(): void
    {
        $notes = $this->build(['freshness' => ['state' => 'fresh', 'failing' => []]]);

        $this->assertSame([], $notes);
    }

    /** Most serious first, so a reader who stops after two has read the two that mattered. */
    public function test_the_most_serious_note_is_first(): void
    {
        $notes = $this->build([
            'kpis' => ['roas' => 4.0, 'ctr' => 0.02],
            'delta' => ['roas' => 0.4, 'ctr' => -0.3],
            'budget' => [['campaign_id' => 'a', 'campaign_name' => 'ح', 'budget' => 1000.0, 'spent' => 900.0, 'pace' => 1.9]],
        ]);

        $rank = ['critical' => 0, 'warning' => 1, 'positive' => 2, 'info' => 3];
        $order = array_map(fn ($n) => $rank[$n['severity']], $notes);

        $this->assertSame('critical', $notes[0]['severity']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'a warning was printed above a critical note');
    }

    /**
     * Every note declares the money it puts on the page, so a link that hides that money can drop it.
     *
     * This is the half that column redaction cannot reach: a sentence reading «صُرف 27,745.88 SAR»
     * publishes the spend as surely as the column does, and nulling table cells leaves it standing.
     */
    public function test_every_note_declares_what_it_reveals(): void
    {
        $notes = $this->build([
            'kpis' => ['roas' => 4.0, 'ctr' => 0.02, 'frequency' => 6.0],
            'delta' => ['roas' => 0.4, 'ctr' => -0.3],
            'reported' => ['reach' => true],
            'budget' => [['campaign_id' => 'a', 'campaign_name' => 'ح', 'budget' => 1000.0, 'spent' => 900.0, 'pace' => 1.9]],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 5000, 'roas' => 8.0],
                ['provider' => 'tiktok', 'spend' => 5000, 'roas' => 2.0],
            ],
            'freshness' => ['state' => 'failed', 'failing' => []],
        ]);

        $this->assertNotSame([], $notes);
        foreach ($notes as $note) {
            $this->assertArrayHasKey('reveals', $note, "«{$note['title']}» does not say what it publishes");
            $this->assertIsArray($note['reveals']);
        }

        $byKind = array_column($notes, 'reveals', 'kind');
        // The budget sentence names the money spent and the budget it came from.
        $this->assertSame(['spend'], $byKind['budget_pace']);
        // A ROAS headline needs both halves of the division to be showable.
        $this->assertSame(['spend', 'revenue'], $byKind['period_comparison']);
        // A rate, a frequency and a sync failure carry no money at all.
        $this->assertSame([], $byKind['falling_rate']);
        $this->assertSame([], $byKind['frequency_saturation']);
        $this->assertSame([], $byKind['stale_data']);
    }
}
