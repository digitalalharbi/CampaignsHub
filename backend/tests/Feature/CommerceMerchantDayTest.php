<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * COMMERCE-TZ-001 — a merchant's day is not a UTC day.
 *
 * ## The defect, and why fixing half of it is worse than fixing none
 *
 * `SallaConnector::date()` received `{ date: "2026-08-05 01:30:00", timezone: "Asia/Riyadh" }` and
 * kept the string, throwing the zone away. `Carbon::parse()` then read that wall clock in the
 * application's timezone, so `placed_at` held **01:30 UTC** for a sale the merchant made at 01:30 in
 * Riyadh — three hours late as an instant, and wrong on every screen that renders a TIME.
 *
 * The reason it survived review is that it was wrong CONSISTENTLY. Report windows were built in the
 * same wrong frame — `startOfDay()` on a UTC Carbon — so a query for «5 August» matched exactly the
 * rows a merchant would call 5 August. Two errors cancelling.
 *
 * Which is why the parse alone must never be fixed: correct `placed_at` to 2026-08-04T22:30:00Z while
 * the window still runs 00:00–23:59 UTC and that sale silently leaves the merchant's 5 August. The
 * merchant's own dashboard says one number, the client's report says another, and nothing in either
 * explains the difference. `test_fixing_the_instant_alone_would_drop_a_sale_out_of_the_merchants_day`
 * is that scenario, pinned.
 *
 * ## What this file establishes
 *
 * 1. `placed_at` is a true instant, resolved through an explicit chain — the payload's own zone, then
 *    the store's, then the client workspace's, then UTC as a **recorded assumption**.
 * 2. `placed_on` is the merchant's own calendar date, computed once at ingest, and it is what a
 *    merchant-day total is grouped by.
 * 3. A report window is a range of DATES in the CLIENT's timezone, converted to instants — so a
 *    client and their merchant can disagree about timezone without either being given wrong figures.
 * 4. No assumption is hidden: an order whose zone had to be assumed is counted and surfaced.
 *
 * Salla and Zid are exercised separately throughout. They are not the same: Salla wraps its dates in
 * an object that states its own zone, Zid sends a string that may or may not carry an offset.
 */
final class CommerceMerchantDayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'tz-agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::create([
            'name' => 'Client', 'slug' => 'tz-client', 'mode' => 'managed',
            'default_currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        ]);
        $this->project = Project::create([
            'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function store(string $label, ?string $timezone, string $provider = 'salla'): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $label, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $store = new ExternalAccount;
        $store->forceFill([
            'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "tz:{$label}"),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => 'store',
            'external_id' => $label,
            'name' => $label,
            'currency' => 'SAR',
            'timezone' => $timezone,
            'status' => 'active',
        ])->save();

        return $store;
    }

    /** The real import, given whatever shape the connector would have handed it. */
    private function import(ExternalAccount $store, string $externalId, mixed $placedAt, float $total = 100.0): CommerceOrder
    {
        app(ImportStoreData::class)->orders($store, (string) $this->project->id, [[
            'external_id' => $externalId,
            'status' => 'completed',
            'placed_at' => $placedAt,
            'currency' => 'SAR',
            'total' => $total,
        ]]);

        return CommerceOrder::withoutGlobalScopes()->where('external_id', $externalId)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function funnel(string $from, string $to): array
    {
        return app(StoreFunnelService::class)->build(
            (string) $this->tenant->id,
            (string) $this->project->id,
            Carbon::parse($from),
            Carbon::parse($to),
        );
    }

    private function ordersIn(string $from, string $to): int
    {
        return (int) $this->funnel($from, $to)['totals']['orders'];
    }

    // ── the instant ───────────────────────────────────────────────────────────────────────────

    /**
     * A sale made at half past one in Riyadh happened at half past ten the previous evening, UTC.
     *
     * The measured defect: with the zone thrown away, `placed_at` held `2026-08-05T01:30:00Z` — the
     * merchant's wall clock wearing a UTC label. Three hours wrong as an instant, on every screen
     * that renders a time and for every reader who is not in Riyadh.
     */
    public function test_a_salla_order_is_stored_as_the_instant_it_actually_happened(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');

        $order = $this->import($store, 'o1', ['date' => '2026-08-05 01:30:00.000000', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame('2026-08-04T22:30:00+00:00', $order->placed_at->utc()->toIso8601String());
    }

    /**
     * And it is still the merchant's fifth of August.
     *
     * This is the invariant the whole unit turns on. The instant moved to the previous day in UTC;
     * the merchant's calendar day did not, because a merchant's day is measured on their own clock.
     */
    public function test_the_merchant_day_is_recorded_from_the_merchants_own_clock(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');

        $order = $this->import($store, 'o1', ['date' => '2026-08-05 01:30:00.000000', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame('2026-08-05', $order->placed_on->toDateString());
        $this->assertSame('Asia/Riyadh', $order->placed_at_timezone);
        $this->assertSame('payload', $order->time_source);
    }

    /**
     * **The trap.** Correcting the instant without correcting the window loses the sale.
     *
     * With `placed_at` now at `2026-08-04T22:30:00Z`, a window built as 00:00–23:59 **UTC** on
     * 5 August excludes it. The merchant counts the sale on the 5th; the client's report would not.
     * The window is therefore evaluated in the CLIENT's timezone, which for this client is Riyadh —
     * so the same instant is inside the same day again, for the right reason this time.
     */
    public function test_fixing_the_instant_alone_would_drop_a_sale_out_of_the_merchants_day(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');
        $this->import($store, 'o1', ['date' => '2026-08-05 01:30:00.000000', 'timezone' => 'Asia/Riyadh']);

        // The instant is on 4 August in UTC — this is the assertion that makes the next one meaningful.
        $this->assertSame('2026-08-04', CommerceOrder::withoutGlobalScopes()->firstOrFail()->placed_at->utc()->toDateString());

        $this->assertSame(1, $this->ordersIn('2026-08-05', '2026-08-05'), 'the merchant counted this on the 5th');
        $this->assertSame(0, $this->ordersIn('2026-08-04', '2026-08-04'), 'and it must not also appear on the 4th');
    }

    // ── boundaries ────────────────────────────────────────────────────────────────────────────

    /** The first second of a merchant's day belongs to that day and to no other. */
    public function test_the_first_instant_of_a_merchant_day_does_not_leak_backwards(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');
        $this->import($store, 'midnight', ['date' => '2026-08-05 00:00:00.000000', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame(1, $this->ordersIn('2026-08-05', '2026-08-05'));
        $this->assertSame(0, $this->ordersIn('2026-08-04', '2026-08-04'));
    }

    /** And the last second does not leak forwards. */
    public function test_the_last_instant_of_a_merchant_day_does_not_leak_forwards(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');
        $this->import($store, 'last', ['date' => '2026-08-05 23:59:59.000000', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame(1, $this->ordersIn('2026-08-05', '2026-08-05'));
        $this->assertSame(0, $this->ordersIn('2026-08-06', '2026-08-06'));
    }

    /**
     * A NEGATIVE offset, where the merchant's day runs later than UTC rather than earlier.
     *
     * A sale at half past eleven at night in Los Angeles is already the next morning in UTC. Tested
     * separately from the Riyadh case because an implementation that adds where it should subtract
     * passes every positive-offset test in this file.
     */
    public function test_a_negative_offset_store_keeps_its_own_evening(): void
    {
        $store = $this->store('la-shop', 'America/Los_Angeles');

        $order = $this->import($store, 'la1', ['date' => '2026-08-05 23:30:00.000000', 'timezone' => 'America/Los_Angeles']);

        $this->assertSame('2026-08-06T06:30:00+00:00', $order->placed_at->utc()->toIso8601String());
        $this->assertSame('2026-08-05', $order->placed_on->toDateString(), 'the merchant sold this on the 5th');
    }

    /**
     * A historical timestamp uses the zone's rules AT THAT INSTANT, not today's offset.
     *
     * London is +00:00 in January and +01:00 in July. An implementation that resolved a zone to a
     * fixed number of minutes — the obvious shortcut — is an hour wrong for half the year, and the
     * half it is wrong for depends on when the code happens to run.
     */
    public function test_a_historical_timestamp_respects_the_offset_in_force_on_that_date(): void
    {
        $store = $this->store('london-shop', 'Europe/London');

        $winter = $this->import($store, 'w', ['date' => '2026-01-15 12:00:00.000000', 'timezone' => 'Europe/London']);
        $summer = $this->import($store, 's', ['date' => '2026-07-15 12:00:00.000000', 'timezone' => 'Europe/London']);

        $this->assertSame('2026-01-15T12:00:00+00:00', $winter->placed_at->utc()->toIso8601String());
        $this->assertSame('2026-07-15T11:00:00+00:00', $summer->placed_at->utc()->toIso8601String());
        // Both are still the merchant's own date.
        $this->assertSame('2026-01-15', $winter->placed_on->toDateString());
        $this->assertSame('2026-07-15', $summer->placed_on->toDateString());
    }

    // ── Salla and Zid are not the same provider ───────────────────────────────────────────────

    /**
     * Zid sends a string. When it carries its own offset, that offset is the truth and nothing is
     * applied on top of it.
     *
     * The failure this prevents is double conversion: taking an instant that is already absolute and
     * shifting it again by the store's zone, which is how a correct integration becomes three hours
     * wrong in the opposite direction.
     */
    public function test_an_offset_bearing_string_is_trusted_and_not_shifted_again(): void
    {
        $store = $this->store('zid-shop', 'Asia/Riyadh', provider: 'zid');

        $order = $this->import($store, 'z1', '2026-08-05T01:30:00+03:00');

        $this->assertSame('2026-08-04T22:30:00+00:00', $order->placed_at->utc()->toIso8601String());
        $this->assertSame('payload', $order->time_source);
        $this->assertSame('2026-08-05', $order->placed_on->toDateString());
    }

    /**
     * A bare Zid string has no zone of its own, so the STORE's zone is applied — and recorded.
     *
     * Zid's store profile does not publish a timezone in the shape this connector reads, so in
     * practice this row is usually the workspace fallback below. It is tested here because a store
     * that DOES report one must be believed over anything further down the chain.
     */
    public function test_a_bare_string_takes_the_stores_zone_when_it_has_one(): void
    {
        $store = $this->store('zid-shop', 'Asia/Riyadh', provider: 'zid');

        $order = $this->import($store, 'z2', '2026-08-05 01:30:00');

        $this->assertSame('2026-08-04T22:30:00+00:00', $order->placed_at->utc()->toIso8601String());
        $this->assertSame('store', $order->time_source);
    }

    /**
     * With no zone on the payload and none on the store, the CLIENT's timezone is used.
     *
     * A better guess than UTC and a worse one than the merchant's own — which is exactly why it is
     * recorded as `workspace` rather than silently blended with the cases above.
     */
    public function test_a_store_with_no_zone_falls_back_to_the_clients_timezone(): void
    {
        $store = $this->store('zid-nozone', null, provider: 'zid');

        $order = $this->import($store, 'z3', '2026-08-05 01:30:00');

        $this->assertSame('2026-08-04T22:30:00+00:00', $order->placed_at->utc()->toIso8601String());
        $this->assertSame('Asia/Riyadh', $order->placed_at_timezone);
        $this->assertSame('workspace', $order->time_source);
    }

    /**
     * And when nothing anywhere states a zone, UTC is ASSUMED — and the assumption is on the row.
     *
     * The order is kept. Dropping it would lose a real sale from every total to close a gap that
     * costs, at worst, a few hours of placement — and a lost sale is the worse error. What must not
     * happen is the assumption going unrecorded, which is the «hidden timezone assumption» this unit
     * exists to remove.
     */
    public function test_an_unknowable_zone_is_assumed_utc_and_says_so(): void
    {
        $this->workspace->forceFill(['timezone' => null])->save();
        $store = $this->store('zid-nozone', null, provider: 'zid');

        $order = $this->import($store, 'z4', '2026-08-05 01:30:00');

        $this->assertSame('2026-08-05T01:30:00+00:00', $order->placed_at->utc()->toIso8601String());
        $this->assertSame('UTC', $order->placed_at_timezone);
        $this->assertSame('assumed_utc', $order->time_source);
    }

    /** An assumption nobody can see is the defect. The funnel counts them. */
    public function test_the_funnel_states_how_many_orders_had_their_zone_assumed(): void
    {
        $this->workspace->forceFill(['timezone' => null])->save();
        $known = $this->store('riyadh-shop', 'Asia/Riyadh');
        $unknown = $this->store('zid-nozone', null, provider: 'zid');

        $this->import($known, 'a', ['date' => '2026-08-05 10:00:00.000000', 'timezone' => 'Asia/Riyadh']);
        $this->import($unknown, 'b', '2026-08-05 10:00:00');

        $funnel = $this->funnel('2026-08-01', '2026-08-31');

        $this->assertSame(1, $funnel['coverage']['orders_with_assumed_timezone']);
    }

    // ── the window belongs to the client ──────────────────────────────────────────────────────

    /**
     * The window is stated, not left for a reader to infer.
     *
     * Two people looking at «5 August» in two timezones are looking at two different sixty-thousand
     * seconds. The report says which one it used.
     */
    public function test_the_funnel_states_the_timezone_its_window_was_measured_in(): void
    {
        $this->assertSame('Asia/Riyadh', $this->funnel('2026-08-05', '2026-08-05')['totals']['reporting_timezone']);
    }

    /**
     * A client in one timezone reading a store in another gets a window in THEIRS.
     *
     * The client is who the report is for. Their «5 August» is the day they will ask about, and the
     * store's own day is preserved separately on the row — the two facts coexist rather than one
     * overwriting the other.
     */
    public function test_the_window_follows_the_client_while_the_row_keeps_the_merchants_day(): void
    {
        $this->workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');

        // 10:00 on 5 August in Riyadh is 07:00Z, which is 00:00 on 5 August in Los Angeles.
        $order = $this->import($store, 'o1', ['date' => '2026-08-05 10:00:00.000000', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame('2026-08-05', $order->placed_on->toDateString(), 'the merchant still owns their own day');
        $this->assertSame('America/Los_Angeles', $this->funnel('2026-08-05', '2026-08-05')['totals']['reporting_timezone']);
        $this->assertSame(1, $this->ordersIn('2026-08-05', '2026-08-05'));
        // 23:00 on 4 August in LA — the previous client day — holds nothing.
        $this->assertSame(0, $this->ordersIn('2026-08-04', '2026-08-04'));
    }

    // ── carts get the same treatment ──────────────────────────────────────────────────────────

    /** An abandoned cart is a timestamp too, and it is resolved the same way. */
    public function test_an_abandoned_cart_carries_the_same_instant_and_merchant_day(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');

        app(ImportStoreData::class)->abandonedCarts($store, (string) $this->project->id, [[
            'external_id' => 'c1',
            'abandoned_at' => ['date' => '2026-08-05 01:30:00.000000', 'timezone' => 'Asia/Riyadh'],
            'currency' => 'SAR',
            'total' => 250.0,
        ]]);

        $cart = CommerceAbandonedCart::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('2026-08-04T22:30:00+00:00', $cart->abandoned_at->utc()->toIso8601String());
        $this->assertSame('2026-08-05', $cart->abandoned_on->toDateString());
        $this->assertSame('payload', $cart->time_source);
    }

    /** A row with no timestamp at all keeps a null date rather than being given today's. */
    public function test_an_order_with_no_timestamp_gets_no_merchant_day(): void
    {
        $store = $this->store('riyadh-shop', 'Asia/Riyadh');

        $order = $this->import($store, 'nodate', null);

        $this->assertNull($order->placed_at);
        $this->assertNull($order->placed_on);
        $this->assertNull($order->time_source);
    }
}
