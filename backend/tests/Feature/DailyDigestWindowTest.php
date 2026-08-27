<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Services\DigestPresenter;
use Tests\TestCase;

/**
 * EMAIL-DAILY-WINDOW-001 — the daily email reports a week, and says so.
 *
 * The rhythm and the period are different things. This is sent daily; what it reports is the last
 * seven days against the seven before them. Two days is the noisiest comparison paid media offers —
 * a weekend, a payday, one campaign starting, one provider syncing late — and a reader who acts on
 * that is reacting to the calendar rather than to their account.
 *
 * The copy is the other half. «مقارنة بالأمس» under a seven-day figure is a false sentence, and a
 * digest that misnames its own comparison teaches the reader to distrust the number beside it.
 */
final class DailyDigestWindowTest extends TestCase
{
    public function test_a_seven_day_digest_says_it_compared_against_the_previous_week(): void
    {
        $this->assertSame('the previous week', (new DigestPresenter('en', 7))->comparedWith());
        $this->assertSame('بالأسبوع السابق', (new DigestPresenter('ar', 7))->comparedWith());
    }

    public function test_a_single_day_digest_still_says_yesterday(): void
    {
        // The presenter must not simply assume a week — a one-day window is still a real window.
        $this->assertSame('yesterday', (new DigestPresenter('en', 1))->comparedWith());
        $this->assertSame('بالأمس', (new DigestPresenter('ar', 1))->comparedWith());
    }

    public function test_a_monthly_digest_says_the_previous_month(): void
    {
        $this->assertSame('the previous month', (new DigestPresenter('en', 31))->comparedWith());
        $this->assertSame('بالشهر السابق', (new DigestPresenter('ar', 31))->comparedWith());
    }

    public function test_an_unusual_window_gets_an_honest_generic_phrase(): void
    {
        // A 90-day window is not «the previous month». Better vague than wrong.
        $this->assertSame('the previous period', (new DigestPresenter('en', 90))->comparedWith());
    }

    public function test_no_verdict_still_claims_yesterday(): void
    {
        /*
         * The specific regression. Three verdicts hardcoded «yesterday» regardless of window, so a
         * weekly and a monthly email were already saying it before the daily one widened.
         */
        $p = new DigestPresenter('en', 7);
        $block = [
            'totals' => ['spend' => 1000.0, 'conversions' => 10, 'cpa' => 100.0],
            'previous' => ['spend' => 900.0, 'conversions' => 9, 'cpa' => 100.0],
            'delta' => [],
            'reported' => ['spend' => true, 'conversions' => true],
            'path' => 'conversion',
            'platforms' => [],
            'pacing' => [],
        ];

        foreach ($p->verdict($block) as $line) {
            $this->assertStringNotContainsStringIgnoringCase(
                'yesterday',
                (string) $line,
                'A seven-day digest must not claim it compared against yesterday.'
            );
        }
    }
}
