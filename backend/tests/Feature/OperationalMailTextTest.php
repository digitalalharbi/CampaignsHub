<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\PlainFindings;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Frontend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — what an operational email SAYS.
 *
 * The Owner's complaint, as assertions: no campaign name as a headline, no unexplained English or
 * metric code in an Arabic mail, no «go to the platform» — and every button lands inside the system.
 */
final class OperationalMailTextTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = '/\b(roas|cpa|cpl|cpi|ctr|cpm|cpc|conversions|purchases|impressions|spend|sync_failed|budget_risk|period_comparison|rising_cost|stale_data)\b/i';

    private const PLATFORM_WORDING = '/(ادخل (إلى |الى )?(ال)?منصة|go to the platform|log ?in to the platform|open the platform\b(?! in the system)|pause on the platform|توقف من المنصة|أوقفها من المنصة)/iu';

    private static function visible(string $html): string
    {
        $html = preg_replace('#<(style|head)\b.*?</\1>#is', '', $html) ?? $html;

        return html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function emails(): array
    {
        $out = [];
        foreach (['ar', 'en'] as $locale) {
            foreach (['digest-project', 'digest-project-leads', 'digest-portfolio', 'alerts-bundle', 'alerts-one'] as $key) {
                $out["{$key}/{$locale}"] = [$key, $locale];
            }
        }

        return $out;
    }

    #[DataProvider('emails')]
    public function test_the_email_has_no_metric_codes_no_go_to_the_platform_and_every_button_stays_inside_the_system(string $key, string $locale): void
    {
        $html = MailGallery::render($key, $locale);
        $this->assertNotNull($html);
        $text = self::visible($html);

        // The codes are English words, so only an Arabic mail can be searched for them.
        if ($locale === 'ar') {
            $this->assertDoesNotMatchRegularExpression(self::KEYS, $text, "{$key}/{$locale} prints a raw metric key");
        }
        $this->assertDoesNotMatchRegularExpression(self::PLATFORM_WORDING, $text, "{$key}/{$locale} sends the reader to a platform");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('display:grid', $html);

        preg_match_all('/href="([^"]+)"/', $html, $m);
        $buttons = array_filter($m[1], static fn (string $h): bool => str_contains($h, '/app/'));
        $this->assertNotEmpty($buttons, 'no button into the system');
        foreach ($m[1] as $href) {
            $this->assertTrue(
                str_starts_with($href, Frontend::origin()) || str_starts_with($href, 'mailto:') || str_contains($href, '/mail/unsubscribe/') || str_starts_with($href, 'https://api.campaignshub.io/'),
                "{$key}/{$locale}: «{$href}» leaves the system",
            );
        }
        // Images only from our own origin.
        preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $imgs);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith(Frontend::origin(), $src);
        }
    }

    public function test_an_arabic_digest_carries_no_english_wording_beyond_names_and_units(): void
    {
        $text = self::visible((string) MailGallery::render('digest-project', 'ar'));
        // Strip what is legitimately Latin: the unit, names the reader stored, the brand.
        $text = preg_replace('/\b(USD|SAR|CampaignsHub|localhost|Demo|Bundle|Carousel|Static|Offer|Launch|Video|Film)\b/u', '', $text) ?? $text;
        preg_match_all('/[A-Za-z]{4,}/', $text, $m);
        $this->assertSame([], array_values(array_unique($m[0])), 'English wording inside the Arabic digest');
    }

    public function test_the_digest_says_no_data_for_a_platform_that_sent_no_reach_and_never_zero(): void
    {
        $text = self::visible((string) MailGallery::render('digest-portfolio', 'ar'));
        $this->assertStringContainsString('لا بيانات', $text);
    }

    public function test_the_portfolio_lists_the_projects_that_need_attention_first(): void
    {
        $html = (string) MailGallery::render('digest-portfolio', 'ar');
        $risk = strpos($html, 'عيادات النخبة');     // stale sync + rising cost → at risk
        $good = strpos($html, 'إطلاق العلامة');      // improving → excellent
        $this->assertNotFalse($risk);
        $this->assertNotFalse($good);
        $this->assertLessThan($good, strpos($html, 'data-block="portfolio-row"'));
        $firstRow = substr($html, (int) strpos($html, 'data-block="portfolio-row"'), 2500);
        // DIGESTS-VISUAL-V2-001 — the row says it as a state and a ✕ chip, not as a word: the worst project is the first row of the matrix.
        $this->assertStringContainsString('data-state="bad"', $firstRow);
        $this->assertStringContainsString('✕', $firstRow);
    }

    public function test_each_finding_kind_is_plain_in_both_languages(): void
    {
        $notes = [
            ['kind' => 'period_comparison', 'metric' => 'roas', 'change' => -0.2, 'value' => '3.1×', 'severity' => 'warning'],
            ['kind' => 'rising_cost', 'metric' => 'cpa', 'change' => 0.3, 'value' => '88 USD', 'severity' => 'warning'],
            ['kind' => 'falling_rate', 'metric' => 'ctr', 'change' => -0.3, 'value' => '1.2%', 'severity' => 'warning'],
            ['kind' => 'frequency_saturation', 'value' => '6.1', 'severity' => 'warning'],
            ['kind' => 'budget_pace', 'metric' => 'pace', 'value' => '1.67×', 'severity' => 'critical', 'scope' => ['name' => 'ميتا']],
            ['kind' => 'data_gap', 'severity' => 'info'],
            ['kind' => 'stale_data', 'severity' => 'critical'],
        ];
        foreach ($notes as $note) {
            foreach ([true, false] as $ar) {
                $say = PlainFindings::say($note, $ar);
                $joined = implode(' ', [$say['headline'], $say['text'], $say['action']]);
                $this->assertNotSame('', trim($say['headline']));
                $this->assertDoesNotMatchRegularExpression(self::KEYS, $ar ? $joined : '', "{$note['kind']} prints a metric key in Arabic");
                $this->assertDoesNotMatchRegularExpression(self::PLATFORM_WORDING, $joined);
            }
        }
    }

    public function test_an_alert_names_what_happened_where_and_what_to_do_and_the_campaign_name_is_only_a_secondary_line(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->getKey(), 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'كرم النخيل', 'status' => 'active']);
        $long = 'Ramadan push — retargeting '.str_repeat('very long name ', 8);
        $campaign = UnifiedCampaign::create(['tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'name' => $long, 'status' => 'active', 'objective' => 'sales', 'platforms' => ['snapchat']]);

        $item = [
            'event_id' => 'ev-1', 'rule_type' => 'cpa_increase', 'severity' => 'warning', 'notification_type' => 'budget_risk',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $campaign->getKey(),
            'title' => 'CPA increased', 'detail' => $long.' CPA rose from 61 to 88 — the last 7 days against the 7 before; this rule fires at a 25% rise.',
            'project_id' => (string) $project->getKey(), 'project_name' => 'كرم النخيل', 'triggered_at' => '2026-11-08T10:00:00+00:00',
            'context' => ['cpa_current' => 88.72, 'cpa_previous' => 61.0, 'window_days' => 7, 'threshold_pct' => 25.0, 'rise_pct' => 45.4],
        ];

        $ar = AlertEventText::compose($item, true);
        $this->assertSame('تكلفة النتيجة زادت على سناب شات', $ar['title']);
        $this->assertStringNotContainsString('Ramadan', $ar['title'].$ar['detail'].$ar['action'].$ar['numbers']);
        $this->assertSame('كرم النخيل · سناب شات · المبيعات', $ar['context']);
        $this->assertStringContainsString('61.00', $ar['numbers']);
        $this->assertStringContainsString('88.72', $ar['numbers']);
        $this->assertStringContainsString('25%', $ar['numbers']);
        $this->assertStringStartsWith('الحملة: Ramadan push', $ar['secondary']);
        $this->assertLessThanOrEqual(AlertEventText::NAME_LIMIT + 8, mb_strlen($ar['secondary']));
        $this->assertStringContainsString('…', $ar['secondary']);
        $this->assertSame(Frontend::origin().'/app/campaigns/'.$project->getKey().'/'.$campaign->getKey(), $ar['url']);
        $this->assertDoesNotMatchRegularExpression(self::KEYS, $ar['title'].$ar['detail'].$ar['action']);
        $this->assertDoesNotMatchRegularExpression(self::PLATFORM_WORDING, implode(' ', [$ar['title'], $ar['detail'], $ar['action']]));

        $en = AlertEventText::compose($item, false);
        $this->assertSame('Cost per result rose on Snapchat', $en['title']);
        $this->assertSame('Open the campaign', $en['button']);
    }

    public function test_every_rule_type_composes_plain_arabic_with_no_stored_english_and_a_link_inside_the_system(): void
    {
        $types = ['budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results', 'sync_failure', 'sync_outage', 'measurement_outage', 'token_expiry', 'metric_anomaly', 'tracking_health', 'ga4_sales_drop', 'something_new'];
        foreach ($types as $type) {
            $item = [
                'event_id' => 'e', 'rule_type' => $type, 'severity' => 'critical', 'notification_type' => 'performance',
                'entity_type' => '', 'entity_id' => '',
                'title' => 'Data sync failed', 'detail' => 'The latest sync for tiktok failed: Error 40100 invalid access token (raw provider error)',
                'project_id' => 'p-1', 'project_name' => 'مشروع', 'triggered_at' => 'x',
                'context' => ['provider' => 'tiktok', 'error' => 'Error 40100 invalid access token', 'spend' => 120.0, 'days' => 3, 'conversions' => 0],
            ];
            $c = AlertEventText::compose($item, true);
            $joined = implode(' ', [$c['title'], $c['detail'], $c['action'], $c['context']]);
            $this->assertStringNotContainsString('40100', $joined, "{$type} leaks a raw provider error");
            $this->assertStringNotContainsString('Data sync failed', $joined, "{$type} leaks the stored English title");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', preg_replace('/\p{Cf}/u', '', $joined) ?? $joined, "{$type} has English in an Arabic alert");
            $this->assertStringStartsWith(Frontend::origin().'/app/', $c['url']);
        }
    }
}
