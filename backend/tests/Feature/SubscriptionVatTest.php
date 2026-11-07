<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Services\SubscriptionTax;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRICING-VAT-001 — one rule for the VAT on a subscription charge.
 *
 * The 2026-11 SAR catalogue is priced VAT-exclusive: the charge is price + 15%. The launch catalogue was sold all-in: its charge is
 * unchanged and its VAT is the part of it shown as included. Either way the invoice total is exactly the amount charged.
 */
final class SubscriptionVatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_a_2026_11_price_is_charged_with_vat_added(): void
    {
        $split = app(SubscriptionTax::class)->split('team', '699.00');

        $this->assertFalse($split['prices_include_vat']);
        $this->assertSame(['699.00', '104.85', '803.85'], [$split['net'], $split['tax'], $split['gross']]);
    }

    public function test_a_launch_price_is_charged_unchanged_with_its_vat_shown_as_included(): void
    {
        $split = app(SubscriptionTax::class)->split('growth', '49.00');

        $this->assertTrue($split['prices_include_vat']);
        $this->assertSame('49.00', $split['gross'], 'what a launch subscriber pays does not change');
        $this->assertSame('49.00', number_format((float) $split['net'] + (float) $split['tax'], 2, '.', ''));
        $this->assertSame('6.39', $split['tax']);
    }

    public function test_an_unknown_plan_never_gains_a_tax_nobody_priced_in(): void
    {
        $this->assertSame('10.00', app(SubscriptionTax::class)->split('no-such-plan', '10')['gross']);
        $this->assertSame('10.00', app(SubscriptionTax::class)->split(null, '10')['gross']);
    }
}
