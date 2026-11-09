<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Services\IntegrationStatusResolver;
use App\Domains\Integrations\Status\AccountFacts;
use App\Domains\Integrations\Status\ConnectionFacts;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * KARAM-SYNC-HONESTY-001 — two accounts that read «fresh» beside nothing.
 *
 * Production, 2026-11-09, Karam Al-Nakheel. A Google Ads account billed in EUR synced successfully and
 * stored its spend withheld (no EUR→USD rate), and a ChatGPT Ads account answered «no rows» forty-two
 * times because its one campaign had no ad group. Both ended in a green sync and a «حديثة» chip.
 */
final class KaramSyncHonestyStatusTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00';

    private function freshness(string $provider, array $facts): array
    {
        $now = Carbon::parse(self::NOW);
        $conn = new ConnectionFacts(provider: $provider, scopes: []);
        $account = new AccountFacts(...array_merge([
            'binding' => 'active', 'lastSyncedAt' => $now->copy()->subMinutes(20),
            'latestRunStatus' => 'success', 'latestRunAt' => $now->copy()->subMinutes(20),
        ], $facts));

        return (new IntegrationStatusResolver)->resolveAccount($conn, $account, $now)['statuses']['freshness'];
    }

    public function test_money_waiting_for_a_rate_is_named_with_its_currency(): void
    {
        $f = $this->freshness('google', ['withheldMoneyRows' => 7, 'withheldCurrency' => 'EUR']);

        $this->assertSame('withheld', $f['state']);
        $this->assertSame('ينتظر سعر صرف اليورو — الإنفاق محجوب', $f['label_ar']);
        $this->assertSame('rate_missing', $f['detail']);
        $this->assertSame('record_fx_rate', $f['action']['code']);
        $this->assertSame(7, $f['withheld_rows']);
    }

    public function test_an_account_with_nothing_withheld_still_reads_fresh(): void
    {
        $this->assertSame('fresh', $this->freshness('google', ['withheldMoneyRows' => 0])['state']);
    }

    public function test_a_chatgpt_account_with_no_ad_groups_and_no_delivery_says_so(): void
    {
        $f = $this->freshness('openai_ads', ['latestRunStatus' => 'no_data', 'adSetCount' => 0, 'hasDelivery' => false]);

        $this->assertSame('partial', $f['state']);
        $this->assertSame('الحملة بلا مجموعات إعلانية أو إعلانات — لا صرف بعد', $f['label_ar']);
        $this->assertSame('create_ad_groups', $f['action']['code']);
        $this->assertSame(0, $f['ad_group_count']);
    }

    public function test_a_complete_structure_with_empty_insights_is_not_called_a_missing_ad_group(): void
    {
        // Production, Karam: 1 ad group, 8 approved ads, the provider's insights body is an empty list.
        $f = $this->freshness('openai_ads', ['latestRunStatus' => 'no_data', 'adSetCount' => 1, 'hasDelivery' => false]);

        $this->assertSame('no_delivery_yet', $f['detail']);
        $this->assertStringNotContainsString('بلا مجموعات', $f['label_ar']);
        $this->assertSame('await_first_delivery', $f['action']['code']);
    }

    public function test_it_is_not_claimed_when_the_facts_do_not_prove_it(): void
    {
        // It has delivered before.
        $this->assertNotContains($this->freshness('openai_ads', ['latestRunStatus' => 'no_data', 'adSetCount' => 0, 'hasDelivery' => true])['detail'], ['no_ad_groups', 'no_delivery_yet']);
        // Not asked (older caller): nothing is inferred.
        $this->assertNotContains($this->freshness('openai_ads', ['latestRunStatus' => 'no_data'])['detail'], ['no_ad_groups', 'no_delivery_yet']);
        // Another provider is never told about ChatGPT's hierarchy.
        $this->assertNotContains($this->freshness('meta', ['latestRunStatus' => 'no_data', 'adSetCount' => 0, 'hasDelivery' => false])['detail'], ['no_ad_groups', 'no_delivery_yet']);
    }
}
