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

    /** Renders a digest built in full, rather than one assembled from overrides. */
    private function renderOf(array $digest, string $locale = 'ar'): string
    {
        return (new DailyDigestMail($digest, $locale, 'Mohammed'))->render();
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

    /**
     * The mini dashboard — MAIL-005.
     *
     * The email carries the funnel, the content and the notes, and it carries them as figures rather
     * than as headings with «—» under them. Each assertion below is a section that used to be
     * missing entirely: a reader had numbers and no way to tell what to do about them.
     */
    public function test_the_email_carries_a_funnel_content_and_the_notes(): void
    {
        $html = $this->renderOf($this->rich());

        $this->assertStringContainsString('المسار', $html);
        $this->assertStringContainsString('الظهور', $html);
        // Arabic labels, not the engine's English ones: a half-translated funnel reads worse than
        // either language alone.
        $this->assertStringNotContainsString('Landing Page View', $html);
        $this->assertStringContainsString('زيارات الصفحة', $html);

        $this->assertStringContainsString('المحتوى', $html);
        $this->assertStringContainsString('Bundle Carousel', $html);

        $this->assertStringContainsString('ما يستحق الانتباه', $html);
        $this->assertStringContainsString('تستهلك الميزانية أسرع من الخطة', $html);
    }

    /** A stage nobody reported is absent — a bar of length nothing reads as «everybody left here». */
    public function test_an_unreported_funnel_stage_is_left_out_rather_than_drawn_at_zero(): void
    {
        $digest = $this->rich();
        $digest['projects'][0]['funnel'] = [
            ['stage' => 'impressions', 'label' => 'Impressions', 'count' => 400000, 'step_rate' => null],
            ['stage' => 'clicks', 'label' => 'Clicks', 'count' => 9000, 'step_rate' => 0.0225],
            // Never sent by any connected platform.
            ['stage' => 'add_to_cart', 'label' => 'Add to Cart', 'count' => null, 'step_rate' => null],
        ];

        $html = $this->renderOf($digest);

        $this->assertStringContainsString('النقرات', $html);
        $this->assertStringNotContainsString('الإضافة للسلة', $html);
    }

    /**
     * The KPI cards follow the objective — §14.6 in an inbox.
     *
     * A brand project must not be handed a cost per result: it is spend divided by whatever events
     * happened to be reported, printed in bold, where nobody can click through to check it.
     */
    public function test_a_brand_project_is_not_given_a_cost_per_result_card(): void
    {
        $digest = $this->rich();
        $digest['projects'][0]['metric_set'] = ['impressions', 'reach', 'frequency', 'cpm'];

        $html = $this->renderOf($digest);

        $this->assertStringContainsString('تكلفة الألف ظهور', $html);
        $this->assertStringNotContainsString('>تكلفة النتيجة<', $html);
    }

    /**
     * EMAIL-SETTINGS-DEPTH-001 — the section appears only when there is something in it.
     *
     * The digest carries `recommendations: []` both when the reader switched them off and when there
     * are none, and the mail must render nothing either way. An empty heading would read as «nobody
     * has any advice for you», which is a claim the product has not made — and a heading rendered for
     * somebody who opted out would be the switch not working.
     */
    public function test_approved_recommendations_are_rendered_only_when_there_are_some(): void
    {
        $with = $this->rich();
        $with['projects'][0]['recommendations'] = [
            ['id' => 'r1', 'title' => 'ارفع ميزانية المجموعة الأعلى', 'body' => 'هي الوحيدة تحت التكلفة المستهدفة.', 'priority' => 'high', 'campaign_id' => 'c1', 'due_date' => null],
        ];

        $html = $this->renderOf($with);
        $this->assertStringContainsString('التوصيات المعتمدة', $html);
        $this->assertStringContainsString('ارفع ميزانية المجموعة الأعلى', $html);

        $without = $this->rich();
        $without['projects'][0]['recommendations'] = [];

        $this->assertStringNotContainsString('التوصيات المعتمدة', $this->renderOf($without));
    }

    /**
     * A digest with the new sections, built by hand.
     *
     * @return array<string,mixed>
     */
    private function rich(): array
    {
        $base = $this->digest();
        $base['projects'][0]['metric_set'] = ['spend', 'conversions', 'cpa', 'impressions'];
        $base['projects'][0]['funnel'] = [
            ['stage' => 'impressions', 'label' => 'Impressions', 'count' => 400000, 'step_rate' => null],
            ['stage' => 'landing_page_views', 'label' => 'Landing Page View', 'count' => 7400, 'step_rate' => 0.82],
            ['stage' => 'purchases', 'label' => 'Purchase', 'count' => 210, 'step_rate' => 0.028],
        ];
        $base['projects'][0]['creatives'] = [
            'best' => ['name' => 'Bundle Carousel', 'provider' => 'snapchat', 'reason' => 'أعلى ROAS (4.20×)'],
            'declining' => [['name' => 'Static Offer', 'provider' => 'meta', 'reason' => null]],
            'fatigued' => [],
        ];
        $base['projects'][0]['observations'] = [
            [
                'id' => 'b', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => ['spend'],
                'title' => 'حملة «الصيف» تستهلك الميزانية أسرع من الخطة',
                'detail' => 'صُرف 8,000.00 SAR من أصل 10,000.00 SAR.',
                'scope' => ['type' => 'campaign', 'name' => 'الصيف'],
            ],
        ];

        return $base;
    }

    /**
     * A path key the enum does not know is shown AS the key, never relabelled.
     *
     * `pathLabel()` fell back to Awareness, so an unrecognised key was quietly presented as brand
     * spend — money labelled as something it is not, in the one section whose whole purpose is
     * keeping the paths apart. Found when a preview fixture keyed the map as a list: every row
     * rendered «الوعي», and the conversion path's spend appeared under the awareness label.
     */
    public function test_an_unknown_marketing_path_is_never_relabelled_as_awareness(): void
    {
        $presenter = new DigestPresenter('ar');

        $this->assertSame('الوعي', $presenter->pathLabel('awareness'));
        $this->assertSame('التحويل والمبيعات', $presenter->pathLabel('conversion'));
        // The failure mode: a list index reaching a function that expects a path name.
        $this->assertSame('0', $presenter->pathLabel('0'));
        $this->assertSame('nonsense', $presenter->pathLabel('nonsense'));
    }
}
