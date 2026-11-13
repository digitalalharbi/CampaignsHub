<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Platform\Alerts\MoyasarSettlementSource;
use App\Domains\Platform\Alerts\PlatformAlertInbox;
use App\Domains\Platform\Alerts\PlatformAlertPresenter;
use App\Domains\Platform\Alerts\SettlementSource;
use App\Domains\Platform\Models\PlatformAlert;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PLATFORM-PAYMENT-ALERTS-SETTLEMENT-001 — «المبالغ لم تصل», read from Moyasar's Settlement API.
 *
 * Owner decision 2026-11-13: the same stored Moyasar secret key, read-only. A confirmed payment is reported only when
 * every settlement since it was paid was read and none carries it as a `payment` line, after the configured period
 * (7 days by default — Moyasar documents no settlement cycle). A refused key (401/403) is logged without the key and
 * switches the check off; an unreadable answer is never «unsettled». The responses below follow the documented
 * shapes: `settlements[]` + `meta`, and `lines[]` (`payment_id`, `type`, `amount`, `transacted_at`) + `meta`.
 */
final class MoyasarSettlementSourceTest extends TestCase
{
    use RefreshDatabase;

    /** A placeholder, not a key: the tests prove it never reaches a log line. */
    private const PLACEHOLDER = 'placeholder-secret-for-tests';

    private Carbon $t0;

    private Tenant $tenant;

    /** @var array<string, mixed> what the fake Moyasar answers, by path */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        Http::preventStrayRequests();
        Cache::flush();
        config(['services.moyasar.secret_key' => self::PLACEHOLDER, 'platform_alerts.settlement_source' => 'moyasar']);

        $this->t0 = Carbon::parse('2026-11-01 09:00:00', 'UTC');
        $this->travelTo($this->t0);

        $admin = User::create(['name' => 'Platform Owner', 'email' => 'owner@campaignshub.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->tenant = Tenant::create(['name' => 'متجر الورد', 'slug' => 'ward-store', 'status' => 'active']);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $answer = $this->answers[$path.'#'.($query['page'] ?? '1')] ?? $this->answers[$path] ?? null;

            return is_callable($answer) ? $answer($request) : Http::response($answer ?? ['message' => 'not found'], $answer === null ? 404 : 200);
        });
    }

    public function test_a_confirmed_payment_in_no_settlement_after_the_period_is_reported_and_a_settled_one_is_not(): void
    {
        $settled = $this->paidPayment('pay_settled');
        $missing = $this->paidPayment('pay_missing');

        // Two pages of settlements, the second settlement two pages of lines — every page is read.
        $this->answers['/v1/settlements#1'] = $this->settlements(['st_1'], next: 2);
        $this->answers['/v1/settlements#2'] = $this->settlements(['st_2'], next: null, page: 2);
        $this->answers['/v1/settlements/st_1/lines#1'] = $this->lines([['pay_other', 'payment']]);
        $this->answers['/v1/settlements/st_2/lines#1'] = $this->lines([['pay_missing', 'refund'], ['pay_missing', 'fee']], next: 2);
        $this->answers['/v1/settlements/st_2/lines#2'] = $this->lines([['pay_settled', 'payment']], page: 2);

        $this->travelTo($this->t0->copy()->addDays(6));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->count(), 'inside the 7-day period nothing is asked or said');
        Http::assertNothingSent();

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::UNSETTLED, $alert->kind);
        $this->assertSame('unsettled:'.$missing->getKey(), $alert->dedup_key, 'a refund or fee line is not an arrival');
        $this->assertSame(2, $alert->facts['settlement_coverage']['settlements']);
        $this->assertSame(7, $alert->facts['expected_days']);
        $more = implode(' ', app(PlatformAlertPresenter::class)->present($alert, true)['more']);
        $this->assertStringContainsString('قرأنا تسويات ميسر', $more);
        $this->assertStringContainsString('عددها 2', $more);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/v1/settlements?')
            && $r->method() === 'GET'
            && str_starts_with((string) ($r->header('Authorization')[0] ?? ''), 'Basic ')
            && str_contains(urldecode($r->url()), 'created[gt]='));
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() !== 'GET');
        $this->assertNotNull($settled->refresh()->paid_at, 'read-only: nothing about the payment changed');
    }

    public function test_an_account_moyasar_has_never_settled_raises_no_payment_and_says_so_once(): void
    {
        // Production, 2026-11-13: the key reads settlements and the account has none (`total_count: 0`).
        $this->paidPayment('pay_one');
        $this->paidPayment('pay_two');
        $this->answers['/v1/settlements'] = $this->settlements([], total: 0);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->artisan('platform:payment-alerts')->expectsOutputToContain('Settlement check: no_settlement_history')->assertSuccessful();

        $this->assertSame(0, PlatformAlert::query()->count(), 'no settlement ever is not «the money did not arrive» for each payment');
        $this->assertSame(SettlementSource::NO_HISTORY, app(SettlementSource::class)->state());
        $sent = count(Http::recorded());
        $this->sweep();
        $this->assertCount($sent, Http::recorded(), 'said once: the next sweeps do not ask again until the recheck');
    }

    public function test_a_history_that_does_not_yet_cover_the_payments_period_raises_nothing(): void
    {
        $this->paidPayment('pay_recent');
        // Settled once, before this payment's 7 days were up: the next settlement may well carry it.
        $this->answers['/v1/settlements'] = $this->settlements(['st_1'], createdAt: $this->t0->copy()->addDays(5));
        $this->answers['/v1/settlements/st_1/lines'] = $this->lines([['pay_other', 'payment']]);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_a_settlements_lines_are_read_once(): void
    {
        $this->paidPayment('pay_settled');
        $this->answers['/v1/settlements'] = $this->settlements(['st_1']);
        $this->answers['/v1/settlements/st_1/lines'] = $this->lines([['pay_settled', 'payment']]);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();
        $this->sweep();

        Http::assertSentCount(3); // list, lines, list — the lines of a settlement never change
        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_a_refused_key_is_logged_without_the_key_switches_the_check_off_and_raises_nothing(): void
    {
        $this->paidPayment('pay_missing');
        $this->answers['/v1/settlements'] = fn () => Http::response(['type' => 'authentication_error', 'message' => 'Invalid authorization credentials'], 401);
        Log::spy();

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->artisan('platform:payment-alerts')->expectsOutputToContain('Settlement check: refused_by_gateway')->assertSuccessful();

        $this->assertSame(0, PlatformAlert::query()->count(), 'a key Moyasar will not honour is not «the money did not arrive»');
        $this->assertSame(SettlementSource::REFUSED, app(SettlementSource::class)->state());
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'platform_alerts.moyasar_settlements.refused'
                && $context['status'] === 401
                && $context['type'] === 'authentication_error'
                && ! str_contains((string) json_encode($context), self::PLACEHOLDER);
        })->once();

        // Off until the refusal window passes: the next sweeps do not ask again.
        $this->sweep();
        Http::assertSentCount(1);

        $this->travelTo($this->t0->copy()->addDays(8)->addHours(7));
        $this->answers['/v1/settlements'] = $this->settlements(['st_9']);
        $this->answers['/v1/settlements/st_9/lines'] = $this->lines([['pay_other', 'payment']]);
        $this->sweep();
        $this->assertSame(SettlementSource::AVAILABLE, app(SettlementSource::class)->state());
        $this->assertSame(1, PlatformAlert::query()->count(), 'asked again after the window: a later settlement without it, so it is reported');
    }

    public function test_a_forbidden_answer_is_treated_the_same_way(): void
    {
        $this->paidPayment('pay_missing');
        $this->answers['/v1/settlements'] = fn () => Http::response(['type' => 'permission_error', 'message' => 'User not authorized'], 403);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count());
        $this->assertSame(SettlementSource::REFUSED, app(SettlementSource::class)->state());
    }

    public function test_an_unreadable_answer_is_never_read_as_unsettled(): void
    {
        $this->paidPayment('pay_missing');
        $this->answers['/v1/settlements'] = $this->settlements(['st_1']);
        $this->answers['/v1/settlements/st_1/lines'] = fn () => Http::response(['message' => 'boom'], 500);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->count(), 'one unread settlement could hold the payment');
        $this->assertSame(SettlementSource::AVAILABLE, app(SettlementSource::class)->state(), 'a server error is not a refusal');

        $this->answers['/v1/settlements/st_1/lines'] = fn () => throw new ConnectionException('timed out');
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_a_list_longer_than_the_page_ceiling_is_unknown_not_unsettled(): void
    {
        config(['platform_alerts.settlement_max_pages' => 2]);
        $this->paidPayment('pay_missing');
        $this->answers['/v1/settlements#1'] = $this->settlements([], next: 2);
        $this->answers['/v1/settlements#2'] = $this->settlements([], next: 3, page: 2);

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_without_a_stored_key_the_check_is_blocked_and_asks_nothing(): void
    {
        config(['services.moyasar.secret_key' => null]);
        $this->paidPayment('pay_missing');

        $this->travelTo($this->t0->copy()->addDays(8));
        $this->artisan('platform:payment-alerts')->expectsOutputToContain('Settlement check: BLOCKED_EXTERNAL_CREDENTIALS')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_the_production_binding_is_the_moyasar_source_and_can_be_switched_off(): void
    {
        $this->assertInstanceOf(MoyasarSettlementSource::class, app(SettlementSource::class));

        config(['platform_alerts.settlement_source' => 'none']);
        $this->assertSame(SettlementSource::BLOCKED, app(SettlementSource::class)->state());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function sweep(): void
    {
        app(PlatformAlertInbox::class)->sweep();
    }

    private function paidPayment(string $moyasarId): SubscriptionPayment
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'tenant_id' => $this->tenant->getKey(), 'purpose' => 'subscription', 'plan_code' => 'growth', 'billing_interval' => 'monthly',
            'provider' => 'moyasar', 'amount' => '199.00', 'currency' => 'SAR', 'status' => 'paid', 'paid_at' => now(),
            'provider_payment_id' => $moyasarId, 'idempotency_key' => 'subscription:'.Str::uuid(),
        ])->save();
        // A settled invoice, so the payment is otherwise healthy and only the settlement question remains.
        DB::table('subscription_invoices')->insert([
            'id' => (string) Str::uuid(), 'number' => 'CH-'.Str::random(6), 'tenant_id' => $this->tenant->getKey(), 'subscription_payment_id' => $payment->getKey(),
            'bill_to_name' => 'Ward', 'bill_to_email' => 'billing@ward.test', 'currency' => 'SAR', 'total' => 199, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $payment->refresh();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed> the documented List Settlements shape
     */
    private function settlements(array $ids, ?int $next = null, int $page = 1, ?Carbon $createdAt = null, ?int $total = null): array
    {
        return [
            'settlements' => array_map(fn (string $id): array => [
                'id' => $id, 'recipient_type' => 'Entity', 'recipient_id' => (string) Str::uuid(), 'currency' => 'SAR',
                'amount' => 24209, 'fee' => 46, 'tax' => 6, 'reference' => null, 'settlement_count' => 1,
                'invoice_url' => 'https://example.test/invoice.pdf', 'csv_list_url' => 'https://example.test/list.csv',
                'created_at' => ($createdAt ?? $this->t0->copy()->addDays(7)->addHours(12))->toIso8601String(),
            ], $ids),
            'meta' => ['current_page' => $page, 'next_page' => $next, 'prev_page' => $page > 1 ? $page - 1 : null, 'total_pages' => $next ?? $page, 'total_count' => $total ?? count($ids)],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines  [payment_id, type]
     * @return array<string, mixed> the documented List Settlement Lines shape
     */
    private function lines(array $lines, ?int $next = null, int $page = 1): array
    {
        return [
            'lines' => array_map(fn (array $l): array => [
                'payment_id' => $l[0], 'type' => $l[1], 'currency' => 'SAR', 'payment_amount' => 19900, 'amount' => 19500,
                'fee' => 400, 'tax' => 52, 'transacted_at' => $this->t0->toIso8601String(), 'source' => ['type' => 'creditcard', 'company' => 'mada'],
            ], $lines),
            'meta' => ['current_page' => $page, 'next_page' => $next, 'prev_page' => $page > 1 ? $page - 1 : null, 'total_pages' => $next ?? $page, 'total_count' => count($lines)],
        ];
    }
}
