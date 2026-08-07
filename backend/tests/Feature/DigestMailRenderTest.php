<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Services\DigestPresenter;
use Tests\TestCase;

/**
 * MAIL-002 — what the email actually says, in both languages.
 *
 * These render the Mailable and read the HTML, because everything that can go wrong here goes wrong
 * in the output rather than in a return value: a zero standing in for an absence, a blended cost per
 * result appearing where the payload deliberately has none, an Arabic email laid out left to right.
 */
final class DigestMailRenderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function digest(array $over = []): array
    {
        return array_merge([
            'sendable' => true,
            'reason' => null,
            'date' => '2026-08-06',
            'previous_date' => '2026-08-05',
            'totals' => ['projects' => 2, 'spend' => 12400.0, 'conversions' => 84.0, 'revenue' => 51000.0],
            'projects' => [[
                'project_id' => 'p1',
                'project_name' => 'Nakheel — Sales',
                'totals' => [
                    'spend' => 9400.0, 'conversions' => 62.0, 'impressions' => 240000.0,
                    'reach' => 0.0, 'cpa' => 151.6, 'roas' => 4.2,
                ],
                // Reach was never sent; impressions were. The email must tell them apart.
                'reported' => ['spend' => true, 'conversions' => true, 'impressions' => true, 'reach' => false],
                'change' => ['spend' => 0.12, 'conversions' => -0.08, 'cpa' => 0.31, 'impressions' => null],
                'paths' => [
                    'awareness' => ['spend' => 4000.0, 'conversions' => 0.0, 'revenue' => 0.0, 'campaigns' => 2, 'cost_per_result' => null, 'roas' => null, 'headline_metrics' => []],
                    'traffic' => ['spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0, 'campaigns' => 0, 'cost_per_result' => null, 'roas' => null, 'headline_metrics' => []],
                    'conversion' => ['spend' => 5400.0, 'conversions' => 62.0, 'revenue' => 39000.0, 'campaigns' => 3, 'cost_per_result' => 87.1, 'roas' => 7.2, 'headline_metrics' => []],
                ],
                'best_platform' => ['label' => 'meta', 'spend' => 3900.0, 'conversions' => 40.0, 'cpa' => 97.5, 'roas' => 5.1],
                'worst_platform' => ['label' => 'x', 'spend' => 1200.0, 'conversions' => 3.0, 'cpa' => 400.0, 'roas' => 0.4],
                'best_campaign' => null,
                'worst_campaign' => null,
                'budget' => [],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-08-07T04:10:00+00:00', 'sync_failed' => false, 'failing' => []],
            ]],
        ], $over);
    }

    private function html(string $locale, array $over = []): string
    {
        return (new DailyDigestMail($this->digest($over), $locale, 'Mohammed'))->render();
    }

    /**
     * The rule, in the place it is most dangerous: an inbox.
     *
     * `reach` is `0.0` in the payload because the sums coalesce, and `reported.reach` is false
     * because no platform sent it. «Reach 0» over somebody's morning coffee is a false alarm they
     * cannot check without opening the product this email exists to save them from opening.
     */
    public function test_a_metric_no_platform_reported_says_so_instead_of_showing_zero(): void
    {
        $en = $this->html('en');
        $ar = $this->html('ar');

        $this->assertStringContainsString('240,000', $en, 'impressions were reported and must be shown');
        $this->assertStringNotContainsString('>0<', $en, 'no bare zero should be rendered as a figure');

        // The presenter is what decides this; assert its wording reaches the page in both languages.
        $this->assertStringContainsString('Not reported', (new DigestPresenter('en'))
            ->count(['reach' => 0.0], ['reach' => false], 'reach'));
        $this->assertStringContainsString('لم ترسله المنصة', (new DigestPresenter('ar'))
            ->count(['reach' => 0.0], ['reach' => false], 'reach'));

        $this->assertStringContainsString('Nakheel — Sales', $ar);
    }

    /**
     * The account line carries no blended cost per result, and says why.
     *
     * Across projects it would divide one client's money by another client's orders. The sentence is
     * in the email rather than only in the code, because a reader who sees three account-wide
     * figures will look for a fourth.
     */
    public function test_the_account_total_states_that_cost_and_return_are_not_blended(): void
    {
        $this->assertStringContainsString('not summed across projects', $this->html('en'));
        $this->assertStringContainsString('لا تُجمَع تكلفة النتيجة', $this->html('ar'));
    }

    /**
     * Awareness money appears with no cost per order — the path split, rendered.
     *
     * The awareness bucket in the fixture has spend and no conversions. It must print its spend and
     * its campaign count, and must NOT borrow the conversion path's denominator.
     */
    public function test_the_awareness_path_is_shown_without_a_cost_per_order(): void
    {
        $en = $this->html('en');

        $this->assertStringContainsString('By marketing path', $en);
        $this->assertStringContainsString('2 campaigns', $en, 'awareness reports what it ran, not a cost it never earned');
        $this->assertStringContainsString('cost/result 87.10 SAR', $en, 'the conversion path keeps its own cost');

        // A path with no spend at all is left out rather than printed as a zero row.
        $this->assertStringNotContainsString('0 campaigns', $en);
    }

    /** An Arabic email is laid out right to left — a translated email in the wrong direction is worse. */
    public function test_the_arabic_email_is_right_to_left_and_the_english_is_not(): void
    {
        $this->assertStringContainsString('dir="rtl"', $this->html('ar'));
        $this->assertStringContainsString('lang="ar"', $this->html('ar'));
        $this->assertStringContainsString('dir="ltr"', $this->html('en'));
    }

    /**
     * The subject carries the answer, so a lock screen is enough to decide whether to open it.
     *
     * «Your daily report» makes every day look identical, which is how a daily email stops being read.
     */
    public function test_the_subject_carries_the_days_figures(): void
    {
        $en = (new DailyDigestMail($this->digest(), 'en', 'Mohammed'))->envelope()->subject;
        $ar = (new DailyDigestMail($this->digest(), 'ar', 'Mohammed'))->envelope()->subject;

        $this->assertStringContainsString('12,400 SAR', $en);
        $this->assertStringContainsString('84 results', $en);
        $this->assertStringContainsString('12,400 SAR', $ar);
        $this->assertStringContainsString('نتيجة', $ar);
    }

    /**
     * A data failure outranks a performance verdict.
     *
     * Telling somebody their cost per result rose, when the truth is that a platform stopped
     * syncing, sends them to optimise a campaign that is fine.
     */
    public function test_a_failed_sync_is_the_verdict_rather_than_the_performance_change(): void
    {
        $digest = $this->digest();
        $digest['projects'][0]['freshness'] = [
            'state' => 'failed', 'last_sync_at' => null, 'sync_failed' => true,
            'failing' => [['name' => 'Meta Ads', 'provider' => 'meta']],
        ];

        $html = (new DailyDigestMail($digest, 'en', 'Mohammed'))->render();

        $this->assertStringContainsString('The Meta Ads sync failed', $html);
        $this->assertStringContainsString('incomplete', $html);
        // The CPA rise is still in the figures, but it is not the sentence at the top.
        $this->assertStringNotContainsString('rose by more than a quarter', $html);
    }

    /** No external images: a blocked logo is a grey box, and a blocked chart is nothing at all. */
    public function test_the_email_loads_no_external_images(): void
    {
        $this->assertStringNotContainsString('<img', $this->html('en'));
    }

    /** Every reader can reach their own preferences and the three policies from the footer. */
    public function test_the_footer_offers_preferences_and_the_portal_policies(): void
    {
        $html = $this->html('en');

        $this->assertStringContainsString('/account/notifications', $html);
        $this->assertStringContainsString('/privacy', $html);
        $this->assertStringContainsString('/terms', $html);
        $this->assertStringContainsString('/security', $html);
    }
}
