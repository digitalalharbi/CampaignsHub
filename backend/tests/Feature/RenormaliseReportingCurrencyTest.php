<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MONEY-USD-002 — history re-normalised from the originals, and safe to run twice.
 *
 * The whole design rests on one rule: recompute from `original_amount` + `original_currency`, never
 * from `value`. `value` has already had a rate applied; applying a second one produces a plausible
 * number, which is what makes double conversion expensive to find.
 */
final class RenormaliseReportingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-ren-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // `daily_metrics.project_id` is a real foreign key, so the rows need a real project.
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-ren-'.uniqid(), 'mode' => 'managed']);
        $this->projectId = (string) Project::create([
            'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ])->id;
    }

    private function row(array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('daily_metrics')->insert(array_merge([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'project_id' => $this->projectId,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-03-10',
            'value' => 3750.0, 'original_currency' => 'SAR', 'project_currency' => 'SAR',
            'original_amount' => 3750.0, 'converted_amount' => 3750.0, 'exchange_rate' => 1.0,
            'created_at' => now(), 'updated_at' => now(),
        ], $over));

        return $id;
    }

    private function rate(string $from, string $to, float $rate, string $date = '2026-03-01'): void
    {
        CurrencyRate::create([
            'base_currency' => $from, 'quote_currency' => $to,
            'rate' => $rate, 'rate_date' => $date, 'source' => 'test',
        ]);
    }

    public function test_it_converts_from_the_original_not_from_the_already_converted_value(): void
    {
        $this->rate('SAR', 'USD', 0.2666);
        $id = $this->row();

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();

        $after = DB::table('daily_metrics')->find($id);
        $this->assertSame('USD', $after->project_currency);
        $this->assertEqualsWithDelta(999.75, (float) $after->value, 0.01);
        // The original is never rewritten — it is what makes a re-run correct.
        $this->assertSame('SAR', $after->original_currency);
        $this->assertEqualsWithDelta(3750.0, (float) $after->original_amount, 0.01);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->rate('SAR', 'USD', 0.2666);
        $id = $this->row();

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();
        $once = (float) DB::table('daily_metrics')->find($id)->value;

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();
        $twice = (float) DB::table('daily_metrics')->find($id)->value;

        // The second pass sees a row already in the target and leaves it alone. If it recomputed from
        // `value` this would be 999.75 * 0.2666 = 266.5 — plausible, and wrong.
        $this->assertSame($once, $twice);
    }

    public function test_a_row_already_in_the_reporting_currency_is_left_untouched(): void
    {
        $id = $this->row(['original_currency' => 'USD', 'project_currency' => 'USD', 'value' => 1000.0, 'original_amount' => 1000.0]);

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();

        $after = DB::table('daily_metrics')->find($id);
        $this->assertEqualsWithDelta(1000.0, (float) $after->value, 0.001);
    }

    public function test_a_row_with_no_rate_stays_withheld_rather_than_approximated(): void
    {
        // No EUR->USD rate exists. FX-001's rule is unchanged: an absence, not a guess.
        $id = $this->row(['original_currency' => 'EUR', 'original_amount' => 500.0, 'value' => 500.0]);

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();

        $after = DB::table('daily_metrics')->find($id);
        $this->assertNull($after->value);
        $this->assertNull($after->exchange_rate);
        // The original survives, so the row converts itself the day a rate exists.
        $this->assertEqualsWithDelta(500.0, (float) $after->original_amount, 0.01);
        $this->assertSame('EUR', $after->original_currency);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->rate('SAR', 'USD', 0.2666);
        $id = $this->row();

        $this->artisan('metrics:renormalise-currency')->assertSuccessful();

        $after = DB::table('daily_metrics')->find($id);
        $this->assertSame('SAR', $after->project_currency, 'a dry run must not write');
        $this->assertEqualsWithDelta(3750.0, (float) $after->value, 0.01);
    }

    public function test_it_uses_the_rate_for_the_metric_date_not_todays(): void
    {
        // A March figure must keep converting at March's rate, or two runs a week apart disagree
        // about the past.
        $this->rate('SAR', 'USD', 0.2666, '2026-03-01');
        $this->rate('SAR', 'USD', 0.9999, '2026-08-01');
        $id = $this->row(['metric_date' => '2026-03-10']);

        $this->artisan('metrics:renormalise-currency --apply')->assertSuccessful();

        $this->assertEqualsWithDelta(0.2666, (float) DB::table('daily_metrics')->find($id)->exchange_rate, 0.0001);
    }
}
