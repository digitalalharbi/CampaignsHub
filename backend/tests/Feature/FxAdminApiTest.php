<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsFxWorld;
use Tests\TestCase;

/**
 * FX-ENGINE-001 — the admin contract behind /admin/settings/currency-rates.
 */
final class FxAdminApiTest extends TestCase
{
    use BuildsFxWorld;
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/fx';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_a_platform_admin_reaches_any_of_it(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'secret123']);

        $this->actingAs($user)->getJson(self::BASE.'/rates')->assertForbidden();
        $this->actingAs($user)->postJson(self::BASE.'/preview', ['amount' => 1, 'from' => 'EUR', 'to' => 'USD', 'date' => '2026-11-10'])->assertForbidden();
        $this->actingAs($user)->postJson(self::BASE.'/recompute', ['from' => '2026-11-01', 'to' => '2026-11-10'])->assertForbidden();
        $this->actingAs($user)->putJson(self::BASE.'/policy', ['display_currency' => 'SAR'])->assertForbidden();
    }

    public function test_the_page_lists_the_currencies_actually_used_with_status_and_withheld_counts(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $eur = $this->fxAdAccount('EUR');
        $this->fxAdAccount('EUR');
        $this->fxAdAccount('GBP');
        $this->fxAdAccount('SAR', 'snapchat');
        $this->fxSpendRow($eur, 253, '2026-11-14');
        app(RateLedger::class)->record('GBP', 'USD', 1.25, '2026-11-01', CurrencyRate::MANUAL);

        $data = $this->actingAs($this->fxAdmin())->getJson(self::BASE.'/rates')->assertOk()->json('data');

        $by = collect($data['currencies'])->keyBy('currency');
        $this->assertSame(['EUR', 'GBP', 'SAR'], $by->keys()->sort()->values()->all());
        $this->assertSame(2, $by['EUR']['accounts']);
        $this->assertSame('missing', $by['EUR']['status']);
        $this->assertSame(1, $by['EUR']['withheld']);
        $this->assertSame('stale', $by['GBP']['status']);
        $this->assertSame('2026-11-01', $by['GBP']['rate_date']);
        $this->assertSame('valid', $by['SAR']['status']);
        $this->assertSame('pegged', $by['SAR']['kind']);
        $this->assertSame('USD', $data['policy']['display_currency']);
        $this->assertFalse($data['policy']['auto_source_enabled'], 'the free automatic source is OFF by default');
    }

    public function test_a_rate_can_be_added_with_an_end_a_note_and_as_pegged_and_editing_makes_a_version(): void
    {
        $admin = $this->fxAdmin();

        $this->actingAs($admin)->postJson(self::BASE.'/rates', [
            'base' => 'eur', 'quote' => 'usd', 'rate' => 1.10, 'rate_date' => '2026-11-06', 'effective_to' => '2026-11-30', 'note' => 'bank ref 1',
        ])->assertCreated()->assertJsonPath('data.kind', 'manual')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.source', 'manual:fx-admin@example.test');

        $this->actingAs($admin)->postJson(self::BASE.'/rates', [
            'base' => 'EUR', 'quote' => 'USD', 'rate' => 1.12, 'rate_date' => '2026-11-06', 'note' => 'bank ref 2',
        ])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.outcome', 'applied');

        $this->actingAs($admin)->postJson(self::BASE.'/rates', [
            'base' => 'AED', 'quote' => 'USD', 'rate' => 0.272294, 'rate_date' => '2026-01-01', 'kind' => 'pegged',
        ])->assertCreated()->assertJsonPath('data.kind', 'pegged');

        $history = $this->actingAs($admin)->getJson(self::BASE.'/rates/history?base=EUR&quote=USD')->assertOk()->json('data.versions');
        $this->assertCount(2, $history);
        $this->assertSame('bank ref 2', $history[0]['note']);
        $this->assertSame(1.10, $history[0]['previous_rate']);
    }

    public function test_the_preview_says_what_253_eur_is_and_which_rate_did_it(): void
    {
        app(RateLedger::class)->record('EUR', 'USD', 1.12, '2026-11-09', CurrencyRate::MANUAL, by: $admin = $this->fxAdmin());

        $this->actingAs($admin)->postJson(self::BASE.'/preview', ['amount' => 253, 'from' => 'EUR', 'to' => 'USD', 'date' => '2026-11-10'])
            ->assertOk()
            ->assertJsonPath('data.converted_amount', 283.36)
            ->assertJsonPath('data.rate', 1.12)
            ->assertJsonPath('data.rate_date', '2026-11-09')
            ->assertJsonPath('data.kind', 'manual')
            ->assertJsonPath('data.recorded_by', 'fx-admin@example.test')
            ->assertJsonPath('data.withheld', false);

        $this->actingAs($admin)->postJson(self::BASE.'/preview', ['amount' => 253, 'from' => 'EUR', 'to' => 'SAR', 'date' => '2026-11-10'])
            ->assertOk()->assertJsonPath('data.converted_amount', 1062.6);

        $this->actingAs($admin)->postJson(self::BASE.'/preview', ['amount' => 253, 'from' => 'JPY', 'to' => 'USD', 'date' => '2026-11-10'])
            ->assertOk()->assertJsonPath('data.withheld', true)->assertJsonPath('data.converted_amount', null)
            ->assertJsonPath('data.pending_label', 'بانتظار سعر صرف (JPY)');
    }

    /**
     * The confirmed defect's other half: the rate was recorded AFTER the 253 EUR had been stored withheld. Recompute
     * re-converts it from the preserved original; the platform's own number is not touched.
     */
    public function test_recompute_updates_stored_numbers_from_their_originals_and_summarises_the_difference(): void
    {
        $admin = $this->fxAdmin();
        $eur = $this->fxAdAccount('EUR');
        $withheld = $this->fxSpendRow($eur, 253, '2026-11-10');
        $stale = $this->fxSpendRow($eur, 100, '2026-11-09', rate: 1.05);
        app(RateLedger::class)->record('EUR', 'USD', 1.12, '2026-11-09', CurrencyRate::MANUAL, by: $admin);

        // PROJECT-LIFECYCLE-001 §7 (N4) — recording the rate RELEASES the withheld figure at once (a NULL is never left
        // convertible); a figure already converted (the 1.05 one) is re-priced only by «إعادة حساب».
        $this->assertSame(283.36, round((float) DB::table('daily_metrics')->where('id', $withheld)->value('value'), 2));
        $this->assertSame(105.0, round((float) DB::table('daily_metrics')->where('id', $stale)->value('value'), 2), 'not re-priced by the release');

        $dry = $this->actingAs($admin)->postJson(self::BASE.'/recompute', ['from' => '2026-11-01', 'to' => '2026-11-15'])
            ->assertOk()->json('data');
        $this->assertFalse($dry['applied']);
        $this->assertSame(1, $dry['changed']);
        $this->assertSame(0, $dry['released']);
        $this->assertSame(105.0, round((float) DB::table('daily_metrics')->where('id', $stale)->value('value'), 2), 'a dry run writes nothing');

        $done = $this->actingAs($admin)->postJson(self::BASE.'/recompute', ['from' => '2026-11-01', 'to' => '2026-11-15', 'apply' => true])
            ->assertOk()->json('data');
        $this->assertTrue($done['applied']);
        $eurRow = collect($done['by_currency'])->firstWhere('currency', 'EUR');
        $this->assertEqualsWithDelta(388.36, $eurRow['before'], 0.001);
        $this->assertEqualsWithDelta(395.36, $eurRow['after'], 0.001);

        $row = DB::table('daily_metrics')->where('id', $withheld)->first();
        $this->assertSame(283.36, round((float) $row->value, 2));
        $this->assertSame(253.0, (float) $row->original_amount, 'the raw platform number is never modified');
        $this->assertSame('EUR', $row->original_currency);
        $this->assertSame(112.0, round((float) DB::table('daily_metrics')->where('id', $stale)->value('value'), 2));
        $this->assertDatabaseHas('audit_logs', ['action' => 'fx.recompute.applied']);
    }

    public function test_recompute_refuses_an_unbounded_period(): void
    {
        $this->actingAs($this->fxAdmin())->postJson(self::BASE.'/recompute', ['from' => '2026-01-01', 'to' => '2026-11-15'])->assertStatus(422);
    }

    public function test_the_display_currency_is_usd_or_sar_for_the_platform_and_per_workspace(): void
    {
        $admin = $this->fxAdmin();
        $tenant = $this->fxTenant();

        $this->actingAs($admin)->putJson(self::BASE.'/policy', ['display_currency' => 'SAR', 'auto_source_enabled' => true])
            ->assertOk()->assertJsonPath('data.display_currency', 'SAR')->assertJsonPath('data.auto_source_enabled', true);
        $this->actingAs($admin)->putJson(self::BASE.'/policy', ['display_currency' => 'EUR'])->assertStatus(422);

        $this->actingAs($admin)->putJson(self::BASE.'/workspaces/'.$tenant->id.'/display-currency', ['display_currency' => 'USD'])->assertOk();
        $ws = collect($this->actingAs($admin)->getJson(self::BASE.'/rates')->json('data.workspaces'))->firstWhere('id', (string) $tenant->id);
        $this->assertSame('USD', $ws['display_currency']);
        $this->assertSame('USD', $ws['effective']);

        $this->actingAs($admin)->putJson(self::BASE.'/workspaces/'.$tenant->id.'/display-currency', ['display_currency' => null])->assertOk();
        $ws = collect($this->actingAs($admin)->getJson(self::BASE.'/rates')->json('data.workspaces'))->firstWhere('id', (string) $tenant->id);
        $this->assertNull($ws['display_currency']);
        $this->assertSame('SAR', $ws['effective'], 'no own choice → the platform default');
        $this->assertDatabaseHas('audit_logs', ['action' => 'fx.policy.updated']);
    }

    /** The FREE automatic source: off by default; switched on, `fx:rates` records each used floating currency against USD. */
    public function test_the_free_automatic_source_is_off_by_default_and_records_used_currencies_when_switched_on(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        Http::fake(['www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response((string) file_get_contents(__DIR__.'/../Fixtures/ecb/eurofxref-daily-2026-11-04.xml'))]);
        $this->fxAdAccount('EUR');
        $this->fxAdAccount('GBP');

        $this->artisan('fx:rates')->assertSuccessful();
        $this->assertSame(0, CurrencyRate::query()->where('kind', 'automatic')->count(), 'off by default: nothing fetched');
        Http::assertNothingSent();

        $this->actingAs($this->fxAdmin())->putJson(self::BASE.'/policy', ['auto_source_enabled' => true])->assertOk();
        $this->app->forgetScopedInstances();
        $this->artisan('fx:rates')->assertSuccessful();

        $eur = CurrencyRate::query()->where('base_currency', 'EUR')->where('quote_currency', 'USD')->first();
        $this->assertNotNull($eur);
        $this->assertSame('automatic', $eur->kind);
        $this->assertSame('ecb_reference', $eur->source);
        $this->assertEqualsWithDelta(1.1267, (float) $eur->rate, 1e-9);
        $gbp = CurrencyRate::query()->where('base_currency', 'GBP')->where('quote_currency', 'USD')->first();
        $this->assertEqualsWithDelta(1.1267 / 0.8346, (float) $gbp->rate, 1e-6);
    }
}
