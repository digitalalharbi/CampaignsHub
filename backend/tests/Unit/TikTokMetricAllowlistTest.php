<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Providers\TikTokConnector;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * TIKTOK-METRIC-REFUSAL-001 — every metric the TikTok connector asks the report API for is one the
 * API documents.
 *
 * TikTok refuses the WHOLE daily report when one requested metric name is invalid: Production,
 * 2026-10-12, «Invalid metric fields: ['add_to_cart'] · code 40002», and the account synced nothing.
 * The list below is copied verbatim from «Basic report supported metrics» (API for Business v1.3,
 * https://business-api.tiktok.com/portal/docs/basic-reports-supported-metrics/v1.3), read on
 * 2026-10-12, with the meaning each name has there. A field added to the connector that is not on
 * this list fails here, before it can cost a client a day of figures.
 */
final class TikTokMetricAllowlistTest extends TestCase
{
    /** Documented name => what the documentation says it is. */
    private const DOCUMENTED = [
        'spend' => 'Cost',
        'impressions' => 'Impressions',
        'clicks' => 'Clicks (destination)',
        'reach' => 'Reach — unique users who saw the ads at least once',
        'video_play_actions' => 'Video views — plays started, replays excluded',
        'video_views_p100' => 'Video views at 100%',
        'web_event_add_to_cart' => 'Adds to cart (website)',
        'initiate_checkout' => 'Checkouts initiated (website)',
        'complete_payment' => 'Purchases (website)',
        'total_complete_payment_rate' => 'Purchase value (website)',
        'conversion' => 'Conversions — the optimisation event selected',
        'onsite_form' => 'Form submissions (TikTok) — lead actions on TikTok\'s own Instant Form (read 2026-10-28)',
    ];

    /** Names that look right and are not report metrics, or mean something else. */
    private const NOT_TO_REQUEST = [
        'add_to_cart' => 'not a report metric — the 40002 refusal of 2026-10-12',
        'total_purchase_value' => 'Purchase value (APP) — not the website purchases complete_payment counts',
        'form' => 'Form submissions (WEBSITE) — a pixel event, never a native Instant Form lead',
    ];

    /** @return array<string,string> canonical => TikTok field */
    private function requested(): array
    {
        /** @var array<string,string> */
        return (new ReflectionClassConstant(TikTokConnector::class, 'METRICS'))->getValue();
    }

    public function test_every_requested_metric_is_documented(): void
    {
        foreach ($this->requested() as $canonical => $field) {
            $this->assertArrayHasKey($field, self::DOCUMENTED, "{$canonical} is asked for as `{$field}`, which the TikTok report API does not document");
        }
    }

    public function test_no_known_wrong_name_is_requested(): void
    {
        foreach (self::NOT_TO_REQUEST as $field => $why) {
            $this->assertNotContains($field, $this->requested(), "`{$field}` is requested: {$why}");
        }
    }

    public function test_the_sales_stages_read_the_same_website_events(): void
    {
        $m = $this->requested();

        $this->assertSame('web_event_add_to_cart', $m['add_to_cart']);
        $this->assertSame('initiate_checkout', $m['checkout']);
        $this->assertSame('complete_payment', $m['purchases']);
        $this->assertSame('total_complete_payment_rate', $m['revenue'], 'revenue must price the same website purchases the count counts');
    }

    public function test_native_form_leads_are_the_instant_form_count_not_the_website_form(): void
    {
        $this->assertSame('onsite_form', $this->requested()['form_leads']);
    }
}
