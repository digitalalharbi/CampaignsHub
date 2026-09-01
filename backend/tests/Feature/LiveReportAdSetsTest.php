<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-DETAIL-PARITY-001 — the rung between the campaign and the ad, and the flags that reach it.
 *
 * A «detailed report» that stops at the campaign is a summary with a longer label. The ad-set grain
 * is where a media buyer's decisions actually live — an audience, a placement, a budget split — and
 * it has been in `entity_daily_metrics` since ADSET-METRICS-TRUTH-001 asked every provider for it
 * rather than one.
 *
 * The half that is easy to forget is this one: a section added to the live payload and not to the
 * sanitiser's list is a section that ignores the link's hide flags. An operator who hid spend from
 * a client would find it again one rung down, in a table nobody thought to check.
 */
final class LiveReportAdSetsTest extends TestCase
{
    use RefreshDatabase;

    private function share(array $flags = []): ReportShare
    {
        $share = new ReportShare;
        $share->forceFill([
            'hide_spend' => false,
            'hide_revenue' => false,
            'hide_campaign_names' => false,
            ...$flags,
        ]);

        return $share;
    }

    private function payload(): array
    {
        return [
            'totals' => ['spend' => 1000.0, 'revenue' => 4000.0],
            'deltas' => [],
            'campaigns' => [['campaign_name' => 'Eid', 'spend' => 600.0, 'revenue' => 2400.0]],
            'ad_sets' => [
                ['external_entity_id' => 'as-1', 'spend' => 400.0, 'revenue' => 1600.0, 'campaign_name' => 'Eid'],
                ['external_entity_id' => 'as-2', 'spend' => 200.0, 'revenue' => 800.0, 'campaign_name' => 'Eid'],
            ],
        ];
    }

    /** A link that hides spend hides it at EVERY rung, not only the ones written first. */
    public function test_hiding_spend_reaches_the_ad_sets(): void
    {
        $out = app(ShareService::class)->sanitizeLive($this->payload(), $this->share(['hide_spend' => true]));

        foreach ($out['ad_sets'] as $row) {
            $this->assertNull($row['spend'], 'an ad set kept the spend the link hides');
        }

        // And the revenue is untouched, because this link hid only the spend.
        $this->assertSame(1600.0, $out['ad_sets'][0]['revenue']);
    }

    /** The campaign name is renamed one rung down as well, or the rename hides nothing. */
    public function test_hiding_campaign_names_reaches_the_ad_sets(): void
    {
        $out = app(ShareService::class)->sanitizeLive($this->payload(), $this->share(['hide_campaign_names' => true]));

        foreach ($out['ad_sets'] as $row) {
            $this->assertNotSame('Eid', $row['campaign_name']);
        }
    }

    /** A link that hides nothing is returned untouched — the sanitiser is not a rewriter. */
    public function test_a_link_that_hides_nothing_changes_nothing(): void
    {
        $payload = $this->payload();

        $this->assertSame($payload, app(ShareService::class)->sanitizeLive($payload, $this->share()));
    }
}
