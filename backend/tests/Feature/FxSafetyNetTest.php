<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Fx\FxMonitor;
use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Platform\Alerts\PlatformAlertInbox;
use App\Domains\Platform\Alerts\PlatformAlertPresenter;
use App\Domains\Platform\Models\PlatformAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFxWorld;
use Tests\TestCase;

/**
 * FX-ENGINE-001 safety nets — the admin is alerted in the app about a currency with no rate or a stale one, and a daily guard
 * logs any stored money figure that did not pass through convert().
 */
final class FxSafetyNetTest extends TestCase
{
    use BuildsFxWorld;
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/fx';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_linking_an_account_in_a_currency_with_no_rate_alerts_the_admin_and_a_rate_clears_it(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->fxAdAccount('EUR');

        $alert = PlatformAlert::query()->where('kind', FxMonitor::MISSING)->first();
        $this->assertNotNull($alert, 'raised at link time');
        $this->assertSame('EUR', $alert->facts['currency']);
        $this->assertNull($alert->emailed_at);

        $admin = $this->fxAdmin();
        $alerts = $this->actingAs($admin)->getJson(self::BASE.'/rates')->json('data.alerts');
        $this->assertSame(['fx_rate_missing'], array_column($alerts, 'kind'));

        app(RateLedger::class)->record('EUR', 'USD', 1.12, '2026-11-15', CurrencyRate::MANUAL, by: $admin);
        $this->artisan('fx:monitor')->assertSuccessful();
        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_a_used_currency_whose_rate_is_older_than_seven_days_raises_a_stale_alert(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        app(RateLedger::class)->record('GBP', 'USD', 1.25, '2026-11-01', CurrencyRate::MANUAL);
        $this->fxAdAccount('GBP');

        $this->artisan('fx:monitor')->assertSuccessful();

        $alert = PlatformAlert::query()->where('kind', FxMonitor::STALE)->first();
        $this->assertNotNull($alert);
        $this->assertSame('2026-11-01', $alert->facts['rate_date']);
    }

    public function test_the_daily_guard_logs_a_stored_figure_that_bypassed_convert(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $eur = $this->fxAdAccount('EUR');
        // A path that wrote 253 as a dollar figure with no rate behind it: exactly the «253 $» defect, stored.
        DB::table('daily_metrics')->where('id', $this->fxSpendRow($eur, 253, '2026-11-14'))->update(['value' => 253]);

        $this->artisan('fx:monitor')->assertFailed();

        $guard = $this->actingAs($this->fxAdmin())->getJson(self::BASE.'/rates')->json('data.guard');
        $this->assertSame(1, $guard['stored_without_rate']);
        $this->assertFalse($guard['ok']);
    }

    public function test_the_inbox_card_names_the_currency_and_links_the_rates_page_and_is_never_mailed(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->fxAdAccount('EUR');
        $alert = PlatformAlert::query()->where('kind', FxMonitor::MISSING)->firstOrFail();

        $card = app(PlatformAlertPresenter::class)->present($alert, true);

        $this->assertSame('عملة بدون سعر صرف: EUR', $card['title']);
        $this->assertSame([['label' => 'أسعار الصرف', 'path' => '/admin/settings/currency-rates']], $card['links']);
        $this->assertSame(0, app(PlatformAlertInbox::class)->sweep()['mailed']);
        $this->assertNull($alert->refresh()->emailed_at);
    }
}
