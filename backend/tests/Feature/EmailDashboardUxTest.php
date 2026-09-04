<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\DailyDigestMail;
use Tests\TestCase;

/**
 * EMAIL-DASHBOARD-UX-001 — the digest is a dashboard, not a letter.
 *
 * The standard is a real product the owner supplied as calibration: a branded header, the period,
 * KPI cards each carrying its movement against the previous window, the strongest rise and the
 * sharpest fall as two coloured rows, and one call to action. No prose, no diagnostics.
 *
 * What was here instead: three figures with no comparison, no highlights, and «آخر مزامنة:
 * 2026-08-18 23:59» — our sync clock, in a mail that reaches a client's own management.
 *
 * Rendered rather than inspected: a template that stops rendering a block still passes every test
 * written against the payload that feeds it.
 */
final class EmailDashboardUxTest extends TestCase
{
    /** @param array<string,mixed> $over */
    private function digest(array $over = []): array
    {
        return array_merge([
            'sendable' => true,
            'date' => '2026-08-30',
            'to_date' => '2026-08-30',
            'days' => 1,
            'previous_date' => '2026-08-29',
            'totals' => [
                'projects' => 2,
                'spend' => 41923.0,
                'conversions' => 139767.0,
                'revenue' => 0.0,
                'previous' => ['spend' => 44270.0, 'conversions' => 120000.0, 'revenue' => 0.0],
                'change' => ['spend' => -0.053, 'conversions' => 0.1647, 'revenue' => null],
            ],
            'projects' => [
                [
                    'project_id' => 'a', 'project_name' => 'فريسبا',
                    'totals' => ['spend' => 20000.0, 'conversions' => 90000.0],
                    'previous' => ['spend' => 18000.0, 'conversions' => 40000.0],
                    'change' => ['spend' => 0.11, 'conversions' => 1.25],
                    'reported' => [], 'objective' => 'sales', 'metric_set' => [],
                    'kpis' => [], 'paths' => [], 'funnel' => [], 'observations' => [],
                    'freshness' => [], 'creatives' => null, 'recommendations' => [],
                ],
                [
                    'project_id' => 'b', 'project_name' => 'ديوان القهوة',
                    'totals' => ['spend' => 21923.0, 'conversions' => 49767.0],
                    'previous' => ['spend' => 26270.0, 'conversions' => 80000.0],
                    'change' => ['spend' => -0.165, 'conversions' => -0.378],
                    'reported' => [], 'objective' => 'sales', 'metric_set' => [],
                    'kpis' => [], 'paths' => [], 'funnel' => [], 'observations' => [],
                    'freshness' => [], 'creatives' => null, 'recommendations' => [],
                ],
            ],
        ], $over);
    }

    private function render(array $over = [], string $lang = 'ar'): string
    {
        return (new DailyDigestMail($this->digest($over), $lang, 'مدير', 'daily'))->render();
    }

    /** Every KPI carries its movement: a figure without one tells a reader nothing about whether it is normal. */
    public function test_each_kpi_card_carries_its_movement_against_the_previous_window(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('▼ 5.3%', $html, 'spend moved and the card did not say so');
        $this->assertStringContainsString('▲ 16.5%', $html, 'results moved and the card did not say so');
    }

    /**
     * A rise from a zero previous window has no percentage, and prints no pill.
     *
     * Every such rise is infinite, and «up ∞%» is not a movement anybody set a threshold on.
     */
    public function test_a_movement_nobody_could_measure_prints_no_pill(): void
    {
        $digest = $this->digest();
        $digest['totals'] = [
            'projects' => 1, 'spend' => 100.0, 'conversions' => 5.0, 'revenue' => 0.0,
            'previous' => ['spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0],
            'change' => ['spend' => null, 'conversions' => null, 'revenue' => null],
        ];
        // The project blocks carry their own arrows; this case is about the ACCOUNT cards, so the
        // fixture is reduced to one project with nothing to compare against.
        $digest['projects'] = [array_merge($digest['projects'][0], [
            'previous' => ['spend' => 0.0, 'conversions' => 0.0],
            'change' => ['spend' => null, 'conversions' => null],
        ])];

        $html = (new DailyDigestMail($digest, 'ar', 'مدير', 'daily'))->render();

        $this->assertStringNotContainsString('▲', $html);
        $this->assertStringNotContainsString('∞', $html);
    }

    /** A revenue card reading zero on a lead-generation account is a measurement of nothing. */
    public function test_a_revenue_card_nobody_reported_is_absent_rather_than_zero(): void
    {
        $this->assertStringNotContainsString('الإيراد', $this->render());
    }

    /**
     * The two ends, before the middle.
     *
     * A person reading on a phone wants what rose most and what fell most. A list of projects in
     * whatever order they came back is a list nobody reads to the bottom.
     */
    public function test_the_mail_leads_with_the_strongest_rise_and_the_sharpest_fall(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('أبرز التحرّكات', $html);
        $this->assertStringContainsString('فريسبا', $html);
        $this->assertStringContainsString('أفضل تحسّن', $html);
        $this->assertStringContainsString('ديوان القهوة', $html);
        $this->assertStringContainsString('أكبر تراجع', $html);
    }

    /**
     * «Best of one» is a ranking of nothing.
     *
     * Printing it as a highlight is how a reader learns that the highlights mean nothing.
     */
    public function test_one_project_produces_no_highlights(): void
    {
        $digest = $this->digest();
        $digest['projects'] = [$digest['projects'][0]];

        $html = (new DailyDigestMail($digest, 'ar', 'مدير', 'daily'))->render();

        $this->assertStringNotContainsString('أبرز التحرّكات', $html);
    }

    /**
     * Movement is measured on RESULTS, never on spend.
     *
     * Spending more is not an improvement, and a digest that celebrates it rewards the wrong
     * behaviour. Here the project that spent MORE is the one whose results fell.
     */
    public function test_the_biggest_spender_is_not_thereby_the_best_improvement(): void
    {
        $digest = $this->digest();
        $digest['projects'][1]['change'] = ['spend' => 3.0, 'conversions' => -0.5];

        $html = (new DailyDigestMail($digest, 'ar', 'مدير', 'daily'))->render();

        $best = strpos($html, 'أفضل تحسّن');
        $this->assertNotFalse($best);
        $this->assertStringContainsString('فريسبا', substr($html, max(0, $best - 400), 500));
    }

    /**
     * CLIENT-DIAGNOSTIC-SEPARATION-001 — the mail speaks no operator vocabulary.
     *
     * A digest reaches a client's own management. «آخر مزامنة» is a fact about our plumbing: they
     * cannot act on it and cannot tell from it whether the figures above are wrong.
     */
    public function test_the_mail_carries_no_sync_clock(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $html = $this->render([], $lang);

            foreach (['آخر مزامنة', 'Last sync', 'بيانات الاعتماد', 'connector', 'المنصات المرتبطة'] as $word) {
                $this->assertStringNotContainsString($word, $html, "the digest spoke «{$word}» to a reader who cannot act on it");
            }
        }
    }

    /**
     * EMAIL-DASHBOARD-UX-001 — and so does the first sentence.
     *
     * The intro was a two-way branch: daily said «yesterday», and everything else said «this week».
     * So the MONTHLY digest opened «Here is the week» under a header reading «Monthly digest ·
     * 2026-08-01 → 2026-08-31» — the first sentence contradicting the line directly above it.
     *
     * Found by rendering the THIRD rhythm. The first two were right by luck of the branch, which is
     * exactly why reading two of three missed it, and why this asserts all three.
     */
    public function test_the_first_sentence_names_the_window_the_mail_is_about(): void
    {
        $cases = [
            'monthly' => ['ar' => 'هذا الشهر', 'en' => 'the month', 'not_ar' => 'هذا الأسبوع', 'not_en' => 'the week'],
            'weekly' => ['ar' => 'هذا الأسبوع', 'en' => 'the week', 'not_ar' => 'هذا الشهر', 'not_en' => 'the month'],
            'daily' => ['ar' => 'أمس', 'en' => 'yesterday', 'not_ar' => 'هذا الأسبوع', 'not_en' => 'the week'],
        ];

        foreach ($cases as $kind => $expected) {
            foreach (['ar', 'en'] as $lang) {
                $html = (new DailyDigestMail($this->digest(), $lang, 'مدير', $kind))->render();
                $intro = $this->introOf($html);

                $this->assertStringContainsString($expected[$lang], $intro, "the {$kind} intro does not name its own window in {$lang}");
                $this->assertStringNotContainsString(
                    $expected[$lang === 'ar' ? 'not_ar' : 'not_en'],
                    $intro,
                    "the {$kind} intro names a window it is not about",
                );
            }
        }
    }

    /**
     * The intro sentence alone, because the rest of the mail legitimately names other windows.
     *
     * A comparison pill says «vs the previous week» wherever the rhythm is; asserting over the whole
     * document would make this test fail on a sentence that is correct.
     */
    private function introOf(string $html): string
    {
        $text = strip_tags($html);
        $start = mb_strpos($text, 'مدير');

        return $start === false ? $text : mb_substr($text, $start, 260);
    }

    /**
     * EMAIL-DASHBOARD-UX-001 — the footer names the rhythm the reader actually chose.
     *
     * One mailable serves daily, weekly and monthly, and the footer was written once, in the daily's
     * words: every weekly and monthly digest told its reader they had chosen the DAILY one. It is the
     * line that says why this arrived and how to stop it, so a reader who set up a weekly summary was
     * being contradicted about their own preference at the bottom of an email full of figures — and
     * pointed at a setting they do not hold.
     */
    public function test_the_footer_names_the_rhythm_the_reader_chose(): void
    {
        $cases = [
            'weekly' => ['ar' => 'الملخص الأسبوعي', 'en' => 'weekly digest', 'not_ar' => 'اخترت الملخص اليومي', 'not_en' => 'chose the daily digest'],
            'monthly' => ['ar' => 'الملخص الشهري', 'en' => 'monthly digest', 'not_ar' => 'اخترت الملخص اليومي', 'not_en' => 'chose the daily digest'],
            'daily' => ['ar' => 'الملخص اليومي', 'en' => 'daily digest', 'not_ar' => 'اخترت الملخص الأسبوعي', 'not_en' => 'chose the weekly digest'],
        ];

        foreach ($cases as $kind => $expected) {
            foreach (['ar', 'en'] as $lang) {
                $html = (new DailyDigestMail($this->digest(), $lang, 'مدير', $kind))->render();

                $this->assertStringContainsString($expected[$lang], $html, "the {$kind} digest never named itself in {$lang}");
                $this->assertStringNotContainsString(
                    $expected[$lang === 'ar' ? 'not_ar' : 'not_en'],
                    $html,
                    "the {$kind} digest told its reader they had chosen a different rhythm",
                );
            }
        }
    }
}
