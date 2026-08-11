<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * COMMERCE-FX-001 — store money obeys the rule FX-001 established for ad money.
 *
 * ## The defect, stated plainly
 *
 * `commerce_orders` has recorded the provider's `currency` per row since COMMERCE-001, and every
 * reader added `total` across rows without ever looking at it. A merchant selling in dollars through
 * one shop and riyals through another had both added together, and the answer was a number in no
 * currency at all.
 *
 * `test_the_old_import_would_have_added_dollars_to_riyals` is the fail-first proof. It is written so
 * that it fails against the previous import — see its own note for the measurement.
 *
 * ## What is deliberately NOT here
 *
 * A live rate feed. Every rate below is a row in `currency_rates`, which is where a feed would put
 * them. Inventing one in code would be the hidden fixed rate this unit exists to forbid.
 */
final class CommerceReportingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'cfx-agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // The client the report is FOR decides the currency it is read in.
        $workspace = ClientWorkspace::create([
            'name' => 'Client', 'slug' => 'cfx-client', 'mode' => 'managed', 'default_currency' => 'SAR',
        ]);
        $this->project = Project::create([
            'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── The claim ─────────────────────────────────────────────────────────────────────────────

    /**
     * Two shops, two currencies, one report — and the total is in riyals.
     *
     * MEASURED against the previous import, not asserted about it: with the conversion removed, the
     * same two orders produced a revenue of **2000**, because `$1,000` and `1,000 SAR` were added as
     * though they were the same thing. The truth at 3.75 is 4,750, and the difference is not a
     * rounding argument — it is most of the number.
     */
    public function test_the_old_import_would_have_added_dollars_to_riyals(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o-usd', 1000.0, 'USD');
        $this->importOrder($this->store('sar-shop', 'SAR'), 'o-sar', 1000.0, 'SAR');

        $funnel = $this->funnel();

        $this->assertEqualsWithDelta(4750.0, (float) $funnel['totals']['revenue'], 0.01, 'dollars are being added to riyals');
        $this->assertSame('SAR', $funnel['totals']['reporting_currency']);
    }

    /** A shop already selling in the reporting currency is recorded as converted at par, not as untouched. */
    public function test_a_same_currency_order_is_stamped_identity_rather_than_left_blank(): void
    {
        $this->importOrder($this->store('sar-shop', 'SAR'), 'o1', 500.0, 'SAR');

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('SAR', $order->currency);
        $this->assertSame('SAR', $order->original_currency);
        $this->assertEqualsWithDelta(500.0, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $order->original_total, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $order->exchange_rate, 0.000001);
        // Without this, a converted row and a WITHHELD row look identical from the columns alone.
        $this->assertSame('identity', $order->rate_source);
    }

    /** Every monetary field on the order converts, at ONE rate, and the merchant's own figures survive. */
    public function test_every_monetary_field_on_an_order_is_converted_at_one_rate(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o1', 1000.0, 'USD', extra: [
            'subtotal' => 900.0,
            'shipping_total' => 50.0,
            'tax_total' => 50.0,
            'discount_total' => 100.0,
            'refunded_total' => 200.0,
        ]);

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        foreach ([
            'subtotal' => 900.0, 'shipping_total' => 50.0, 'tax_total' => 50.0,
            'discount_total' => 100.0, 'total' => 1000.0, 'refunded_total' => 200.0,
        ] as $column => $original) {
            $this->assertEqualsWithDelta($original * 3.75, (float) $order->{$column}, 0.01, "{$column} was not converted");
            $this->assertEqualsWithDelta($original, (float) $order->{"original_{$column}"}, 0.01, "{$column} lost its original");
        }

        $this->assertSame('USD', $order->original_currency);
        $this->assertSame('SAR', $order->currency);
        $this->assertSame('2026-06-01', $order->rate_date?->toDateString());
        $this->assertSame('ecb', $order->rate_source);

        // Net revenue is stated in the reporting currency: (1000 − 200) × 3.75.
        $this->assertEqualsWithDelta(3000.0, (float) $order->netRevenue(), 0.01);
    }

    /** A currency neither of the pair is SAR or USD converts the same way — nothing is special-cased. */
    public function test_a_third_currency_converts_through_its_own_rate(): void
    {
        $this->rate('AED', 'SAR', 1.02, '2026-06-01');

        $this->importOrder($this->store('aed-shop', 'AED'), 'o1', 400.0, 'AED');

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(408.0, (float) $order->total, 0.01);
        $this->assertSame('AED', $order->original_currency);
    }

    // ── Fail-closed ───────────────────────────────────────────────────────────────────────────

    /**
     * No rate → no figure. Not zero, and not the unconverted amount.
     *
     * Zero would read as a sale that earned nothing — the most damaging number this product can
     * print — and the unconverted amount is the defect itself.
     */
    public function test_an_order_with_no_rate_is_withheld_rather_than_guessed(): void
    {
        // Deliberately no KWD rate anywhere.
        $this->importOrder($this->store('kwd-shop', 'KWD'), 'o1', 300.0, 'KWD');

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertNull($order->total, 'a figure nobody can vouch for must not be written');
        $this->assertNotSame(0.0, $order->total);
        $this->assertEqualsWithDelta(300.0, (float) $order->original_total, 0.01, 'the original must survive');
        $this->assertSame('KWD', $order->original_currency);
        $this->assertNull($order->exchange_rate);
        $this->assertNull($order->rate_source);

        // The caller is FORCED to notice: a withheld order has no net revenue, rather than 0.0.
        $this->assertNull($order->netRevenue());
        $this->assertTrue($order->moneyWithheld());
    }

    /**
     * A withheld order is missing from the total, so the total says so.
     *
     * `SUM` skips nulls, which is the right arithmetic and the wrong silence: a report short by one
     * order looks exactly like a complete one. The count is what turns an absence into a statement.
     */
    public function test_a_total_shortened_by_a_withheld_order_reports_that_it_is_short(): void
    {
        $shop = $this->store('mixed-shop', 'SAR');
        $this->importOrder($shop, 'o-sar', 1000.0, 'SAR');
        $this->importOrder($shop, 'o-kwd', 300.0, 'KWD');

        $funnel = $this->funnel();

        $this->assertEqualsWithDelta(1000.0, (float) $funnel['totals']['revenue'], 0.01);
        $this->assertSame(2, $funnel['totals']['orders'], 'the order itself is still counted');
        $this->assertSame(1, $funnel['coverage']['orders_with_money_withheld']);
        $this->assertSame(['KWD'], $funnel['coverage']['money_withheld_currencies']);
        $this->assertSame('SAR', $funnel['coverage']['reporting_currency']);
    }

    /** The day a rate arrives, the next sweep converts what was withheld — nothing was lost. */
    public function test_a_withheld_order_converts_itself_once_a_rate_exists(): void
    {
        $shop = $this->store('kwd-shop', 'KWD');
        $this->importOrder($shop, 'o1', 300.0, 'KWD');

        $this->assertNull(CommerceOrder::withoutGlobalScopes()->firstOrFail()->total);

        $this->rate('KWD', 'SAR', 12.2, '2026-06-01');
        $this->importOrder($shop, 'o1', 300.0, 'KWD');   // the same order, re-swept

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(3660.0, (float) $order->total, 0.01);
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count(), 'the re-sweep must stay idempotent');
    }

    // ── Dates ─────────────────────────────────────────────────────────────────────────────────

    /**
     * An order is converted at the rate for the day it was PLACED.
     *
     * A ninety-day window is re-read on every sweep. Pricing January's revenue at today's rate would
     * make last month's report change every time somebody opened it.
     */
    public function test_an_order_uses_the_rate_of_the_day_it_was_placed(): void
    {
        $this->rate('USD', 'SAR', 3.60, '2026-01-01');
        $this->rate('USD', 'SAR', 4.00, '2026-06-01');

        $shop = $this->store('usd-shop', 'USD');
        $this->importOrder($shop, 'jan', 100.0, 'USD', placedAt: '2026-01-15');
        $this->importOrder($shop, 'jun', 100.0, 'USD', placedAt: '2026-06-15');

        $january = CommerceOrder::withoutGlobalScopes()->where('external_id', 'jan')->firstOrFail();
        $june = CommerceOrder::withoutGlobalScopes()->where('external_id', 'jun')->firstOrFail();

        $this->assertEqualsWithDelta(360.0, (float) $january->total, 0.01);
        // Nearest on-or-before: January's order keeps January's quote even though June's exists.
        $this->assertSame('2026-01-01', $january->rate_date?->toDateString());
        $this->assertEqualsWithDelta(400.0, (float) $june->total, 0.01);
        $this->assertSame('2026-06-01', $june->rate_date?->toDateString());
    }

    // ── What is money, and what is not ────────────────────────────────────────────────────────

    /** Counts are counts. A rate applied to a quantity would be nonsense that still looks like a number. */
    public function test_counts_are_never_multiplied_by_a_rate(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o1', 1000.0, 'USD', extra: [
            'items' => [[
                'external_id' => 'i1', 'product_external_id' => null, 'name' => 'عباءة',
                'quantity' => 3, 'unit_price' => 200.0, 'total' => 600.0,
            ]],
        ]);

        $item = CommerceOrderItem::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(3.0, (float) $item->quantity, 0.0001, 'a quantity is not money');
        // The line converts at its ORDER's rate, so the best-seller table adds up to the revenue.
        $this->assertEqualsWithDelta(2250.0, (float) $item->total, 0.01);
        $this->assertEqualsWithDelta(750.0, (float) $item->unit_price, 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $item->original_total, 0.01);
    }

    /** Best sellers are ranked in the reporting currency, because the lines were converted with the order. */
    public function test_the_best_seller_table_is_in_the_reporting_currency(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o1', 1000.0, 'USD', extra: [
            'items' => [[
                'external_id' => 'i1', 'product_external_id' => null, 'name' => 'عباءة',
                'quantity' => 2, 'unit_price' => 500.0, 'total' => 1000.0,
            ]],
        ]);

        $products = $this->funnel()['comparisons']['products'];

        $this->assertSame('عباءة', $products[0]['name']);
        $this->assertEqualsWithDelta(3750.0, (float) $products[0]['revenue'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $products[0]['quantity'], 0.01);
    }

    /** An abandoned cart is money too, and follows the same rule. */
    public function test_an_abandoned_cart_is_converted_and_keeps_its_original(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        app(ImportStoreData::class)->abandonedCarts($this->store('usd-shop', 'USD'), (string) $this->project->id, [[
            'external_id' => 'cart-1',
            'abandoned_at' => '2026-06-10 10:00:00',
            'currency' => 'USD',
            'total' => 80.0,
            'items_count' => 2,
        ]]);

        $cart = CommerceAbandonedCart::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(300.0, (float) $cart->total, 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $cart->original_total, 0.01);
        $this->assertSame('USD', $cart->original_currency);
        $this->assertSame('SAR', $cart->currency);
        $this->assertSame(2, (int) $cart->items_count, 'an item count is not money');
    }

    /**
     * A customer's lifetime spend is NOT converted — and is never a bare number either.
     *
     * It spans every order the customer ever placed, including ones outside any window this product
     * holds rates for, so a single dated rate would be a guess dressed as a figure. It is not summed
     * across shops anywhere; the currency is carried so it cannot be read as riyals by default.
     */
    public function test_a_lifetime_total_is_labelled_rather_than_converted(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        app(ImportStoreData::class)->customers($this->store('usd-shop', 'USD'), (string) $this->project->id, [[
            'external_id' => 'c1', 'name' => 'نورة', 'total_spent' => 900.0, 'orders_count' => 3,
        ]]);

        $customer = CommerceCustomer::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(900.0, (float) $customer->total_spent, 0.01);
        $this->assertSame('USD', $customer->currency);
        $this->assertSame(3, (int) $customer->orders_count);
    }

    // ── Edges ─────────────────────────────────────────────────────────────────────────────────

    /** Zero is a figure and stays one; an amount the provider never stated stays absent. */
    public function test_zero_stays_zero_and_an_unstated_amount_stays_absent(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o1', 0.0, 'USD', extra: ['discount_total' => 0.0]);

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $order->total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_total, 0.0001);
        // Never stated by the provider — absent, and distinguishable from a withheld conversion
        // because no original was recorded for it either.
        $this->assertNull($order->shipping_total);
        $this->assertNull($order->original_shipping_total);
        $this->assertFalse($order->moneyWithheld());
    }

    /**
     * An unlabelled figure falls back to the shop's currency, then to the reporting currency — and
     * the assumption is RECORDED rather than left implicit, which is the whole difference from the
     * code this replaces.
     */
    public function test_an_unlabelled_amount_falls_back_to_the_shop_and_records_the_assumption(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $this->importOrder($this->store('usd-shop', 'USD'), 'o1', 100.0, currency: null);

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('USD', $order->original_currency, "the shop's own currency stands in");
        $this->assertEqualsWithDelta(375.0, (float) $order->total, 0.01);
    }

    /** Cancelled and refunded orders still behave, in the reporting currency. */
    public function test_refunds_and_cancellations_are_netted_after_conversion(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-06-01');

        $shop = $this->store('usd-shop', 'USD');
        $this->importOrder($shop, 'refunded', 100.0, 'USD', extra: ['refunded_total' => 40.0]);
        $this->importOrder($shop, 'cancelled', 100.0, 'USD', extra: ['cancelled_at' => '2026-06-11 09:00:00']);

        $funnel = $this->funnel();

        // Gross counts the live order only; net subtracts the refund; both in riyals.
        $this->assertEqualsWithDelta(375.0, (float) $funnel['totals']['gross_revenue'], 0.01);
        $this->assertEqualsWithDelta(225.0, (float) $funnel['totals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(150.0, (float) $funnel['totals']['refunded'], 0.01);
        $this->assertSame(1, $funnel['totals']['cancelled_orders']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function rate(string $from, string $to, float $rate, string $date, string $source = 'ecb'): void
    {
        CurrencyRate::create([
            'base_currency' => $from, 'quote_currency' => $to,
            'rate' => $rate, 'rate_date' => $date, 'source' => $source,
        ]);
    }

    private function store(string $label, string $currency): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => 'salla', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'salla',
            'connection_name' => $label, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $store = new ExternalAccount;
        $store->forceFill([
            'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "cfx:{$label}"),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'salla',
            'account_type' => 'store',
            'external_id' => $label,
            'name' => $label,
            'currency' => $currency,
            'status' => 'active',
        ])->save();

        return $store;
    }

    /**
     * Run the REAL import — the path a Salla sweep takes — rather than writing rows by hand.
     *
     * @param  array<string,mixed>  $extra
     */
    private function importOrder(
        ExternalAccount $store,
        string $externalId,
        float $total,
        ?string $currency = null,
        string $placedAt = '2026-06-10',
        array $extra = [],
    ): void {
        app(ImportStoreData::class)->orders($store, (string) $this->project->id, [[
            'external_id' => $externalId,
            'status' => 'completed',
            'placed_at' => $placedAt.' 12:00:00',
            'currency' => $currency,
            'total' => $total,
            ...$extra,
        ]]);
    }

    /** Read it the way every screen does: through the one funnel service they all share. */
    private function funnel(): array
    {
        return app(StoreFunnelService::class)->build(
            (string) $this->tenant->id,
            (string) $this->project->id,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );
    }
}
