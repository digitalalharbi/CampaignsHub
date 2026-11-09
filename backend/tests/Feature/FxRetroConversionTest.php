<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * FX-RETRO-001 — a rate that arrives after the money converts the money that was waiting for it.
 *
 * Production, 2026-11-09: Karam Al-Nakheel's Google Ads account bills in EUR, no EUR→USD rate existed,
 * so its spend was stored withheld (`value` NULL, original kept). The original was kept «so the row
 * converts itself the day a rate exists» — but nothing made it so. Recording a rate only wrote the rate.
 */
final class FxRetroConversionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'FX', 'slug' => 'fx-retro', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'fx-retro-c', 'mode' => 'managed', 'default_currency' => 'USD']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function withheld(string $date, float $original = 100.0, string $from = 'EUR'): string
    {
        $id = (string) Uuid::uuid4();
        DB::table('daily_metrics')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Uuid::uuid4(), 'external_campaign_id' => (string) Uuid::uuid4(),
            'provider' => 'google', 'metric_key' => 'spend', 'metric_date' => $date,
            'value' => null, 'original_amount' => $original, 'original_currency' => $from, 'project_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function withheldEntity(string $date): string
    {
        $id = (string) Uuid::uuid4();
        DB::table('entity_daily_metrics')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider' => 'google', 'entity_type' => 'ad', 'entity_id' => (string) Uuid::uuid4(), 'external_entity_id' => 'x',
            'metric_date' => $date, 'attribution_window' => 'default',
            'spend' => null, 'spend_original' => 10.0, 'revenue' => null, 'revenue_original' => 40.0,
            'original_currency' => 'EUR', 'project_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_recording_a_rate_converts_the_withheld_rows_from_their_originals(): void
    {
        $day = $this->withheld('2026-11-08', 15.817804);
        $entity = $this->withheldEntity('2026-11-08');

        app(CurrencyRateFeed::class)->record('EUR', 'USD', 1.16, Carbon::parse('2026-11-08'), 'manual:owner@campaignshub.io');

        $row = DB::table('daily_metrics')->find($day);
        $this->assertEqualsWithDelta(15.817804 * 1.16, (float) $row->value, 0.0001);
        $this->assertEqualsWithDelta(1.16, (float) $row->exchange_rate, 0.0001);
        $this->assertSame('2026-11-08', Carbon::parse((string) $row->rate_date)->toDateString());
        $this->assertSame('manual:owner@campaignshub.io', $row->rate_source);
        $this->assertEqualsWithDelta(15.817804, (float) $row->original_amount, 0.0001);

        $e = DB::table('entity_daily_metrics')->find($entity);
        $this->assertEqualsWithDelta(11.6, (float) $e->spend, 0.0001);
        $this->assertEqualsWithDelta(46.4, (float) $e->revenue, 0.0001);
    }

    public function test_the_admin_form_does_it_too(): void
    {
        $id = $this->withheld('2026-11-08');
        $user = User::create(['name' => 'Owner', 'email' => 'owner@campaignshub.io', 'password' => 'secret123']);
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($user)->postJson('/api/v1/admin/fx/rates', [
            'base' => 'EUR', 'quote' => 'USD', 'rate' => 1.1, 'rate_date' => '2026-11-08',
        ])->assertCreated();

        $this->assertEqualsWithDelta(110.0, (float) DB::table('daily_metrics')->find($id)->value, 0.0001);
    }

    public function test_a_day_older_than_every_quote_stays_withheld_and_each_day_uses_its_own_rate(): void
    {
        $old = $this->withheld('2026-11-01');
        $mid = $this->withheld('2026-11-06');
        $new = $this->withheld('2026-11-09');
        $feed = app(CurrencyRateFeed::class);

        // The later quote first: it can only reach the days on or after it; the earlier one then reaches the middle day.
        $feed->record('EUR', 'USD', 1.20, Carbon::parse('2026-11-08'), 'test');
        $this->assertNull(DB::table('daily_metrics')->find($mid)->value);
        $feed->record('EUR', 'USD', 1.10, Carbon::parse('2026-11-05'), 'test');

        $this->assertNull(DB::table('daily_metrics')->find($old)->value, 'nothing is back-dated');
        $this->assertEqualsWithDelta(110.0, (float) DB::table('daily_metrics')->find($mid)->value, 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) DB::table('daily_metrics')->find($new)->value, 0.0001);
    }

    public function test_a_converted_row_is_never_restated_by_a_later_rate_and_a_rerun_changes_nothing(): void
    {
        $id = $this->withheld('2026-11-08');
        $feed = app(CurrencyRateFeed::class);
        $feed->record('EUR', 'USD', 1.10, Carbon::parse('2026-11-08'), 'test');
        $once = DB::table('daily_metrics')->find($id);

        // The same day corrected afterwards: the figure already published does not move.
        $feed->record('EUR', 'USD', 1.50, Carbon::parse('2026-11-08'), 'test');
        $feed->record('EUR', 'USD', 1.50, Carbon::parse('2026-11-08'), 'test');

        $twice = DB::table('daily_metrics')->find($id);
        $this->assertEqualsWithDelta((float) $once->value, (float) $twice->value, 0.0000001);
        $this->assertEqualsWithDelta(1.10, (float) $twice->exchange_rate, 0.0000001);
    }

    public function test_other_currency_pairs_are_left_withheld(): void
    {
        $gbp = $this->withheld('2026-11-08', 100.0, 'GBP');

        app(CurrencyRateFeed::class)->record('EUR', 'USD', 1.1, Carbon::parse('2026-11-08'), 'test');

        $this->assertNull(DB::table('daily_metrics')->find($gbp)->value);
        $this->assertSame(1, CurrencyRate::query()->count());
    }
}
