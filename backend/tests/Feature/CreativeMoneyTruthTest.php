<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\BackfillCreativeMoneyProvenance;
use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-MONEY-TRUTH-001 — a creative's money obeys the same contract as everything else.
 *
 * ## The defect this asserts against
 *
 * `daily_metrics` goes through `InsightRowNormaliser`, which is where FX-001 lives: money is
 * converted into the project's reporting currency, and when no rate can be vouched for the value is
 * WITHHELD — null, with `original_amount` and `original_currency` kept so the row converts itself
 * the day a rate exists.
 *
 * `creative_daily_metrics` did not. `AccountMetricsSyncer` calls `UpsertCreativeDailyMetrics`
 * directly with the connector's rows, and the table had no currency column at all — `spend` and
 * `revenue` were bare decimals defaulting to 0. Snapchat reports in the ad account's currency, so
 * production stored USD figures, and `CreativePulseSection` rendered them under a hard-coded «SAR».
 *
 * That is worse than the withheld-zero this product already fixed once: not a missing number, a
 * WRONG one wearing the right number's label. 4,308.60 USD shown as «4,309 SAR» understates spend
 * by roughly 3.75× and reads as a measured fact.
 */
final class CreativeMoneyTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        // `is_currency` is what decides money; without the catalogue the test would not be production.
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            // The reporting currency comes from the CLIENT — one client's projects must stay addable.
            'default_currency' => 'SAR',
        ]);

        // The production shape: the project reports in SAR, the ad account spends in USD.
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
            'currency' => 'USD',
            'discovered_at' => Carbon::now(),
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /**
     * The production case exactly: USD spend, SAR project, no rate on file.
     *
     * The number must be withheld rather than stored as a figure the UI will label SAR.
     */
    public function test_unconvertible_money_is_withheld_not_stored_as_the_wrong_currency(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 4128.93, 'revenue' => 12969.03, 'impressions' => 2884062],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertNull($row->spend, 'A USD figure stored as a bare number becomes «SAR» on the card.');
        $this->assertNull($row->revenue);

        $this->assertEqualsWithDelta(4128.93, (float) $row->spend_original, 0.01);
        $this->assertEqualsWithDelta(12969.03, (float) $row->revenue_original, 0.01);
        $this->assertSame('USD', $row->original_currency, 'Without this the row can never convert itself later.');
        $this->assertSame('SAR', $row->project_currency);

        // Counts are not money and are never withheld.
        $this->assertEqualsWithDelta(2884062, (float) $row->impressions, 0.01);
    }

    /** With a rate on file the figure is converted, and the original is still kept. */
    public function test_money_is_converted_when_a_rate_exists(): void
    {
        $this->rate('USD', 'SAR', '2026-08-01', 3.75);
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 100.0],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertEqualsWithDelta(375.0, (float) $row->spend, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $row->spend_original, 0.01);
        $this->assertSame('USD', $row->original_currency);
    }

    /** An account already reporting in the project's currency needs no rate and is not withheld. */
    public function test_same_currency_needs_no_rate(): void
    {
        $this->account->update(['currency' => 'SAR']);
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 500.0],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertEqualsWithDelta(500.0, (float) $row->spend, 0.01);
        $this->assertSame('SAR', $row->original_currency);
    }

    /**
     * A withheld day is still a day the creative RAN — SNAP-CREATIVE-METRICS-LIVE-001 must survive
     * this change. Delivery is measured on the original amount, not on the withheld null.
     */
    public function test_a_withheld_money_day_still_counts_as_delivery(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-05', 'spend' => 250.0],
        ]);

        $this->assertSame(
            '2026-08-05',
            $creative->fresh()?->last_active_at?->toDateString(),
            'Withholding the figure must not erase the fact that the creative delivered.',
        );
    }

    /** A metric the platform never sent stays absent — withholding is not the same as absence. */
    public function test_an_unreported_measure_is_still_absent_not_withheld(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'impressions' => 100],
        ]);

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertNull($row->spend);
        $this->assertNull($row->spend_original, 'No spend was reported, so there is no original to keep.');
    }

    /**
     * What the CARD reads — the write being right is only half of it.
     *
     * The figures reach every creative surface through `CreativeMetrics`, and this asserts the two
     * rules the money contract turns on: the withheld original survives with its currency named, and
     * no cost-per is derived from a withheld figure. «CPA 0.00» over real spend is the same lie one
     * level down, and it is the one that reads as an achievement.
     */
    public function test_the_read_path_carries_the_withheld_money_and_derives_no_ratio_from_it(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 4128.93, 'impressions' => 2884062, 'clicks' => 21802, 'conversions' => 102],
        ]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->id],
            Carbon::parse('2026-07-25'),
            Carbon::parse('2026-08-10'),
        )[(string) $creative->id];

        // The contract's own field names, so one frontend reader renders this and a dashboard KPI.
        $this->assertNull($figures['spend']);
        $this->assertSame(1, $figures['spend_withheld_rows']);
        $this->assertEqualsWithDelta(4128.93, (float) $figures['spend_original'], 0.01);
        $this->assertSame('USD', $figures['money_original_currency']);
        $this->assertSame(1, $figures['money_original_currencies'], 'One currency, so it can be named exactly.');

        // Counts are untouched — they were never money.
        $this->assertEqualsWithDelta(2884062, (float) $figures['impressions'], 0.01);

        // And nothing derived from the withheld figure is invented.
        $this->assertNull($figures['cpa'], 'A cost per result computed from a withheld zero reads as free.');
        $this->assertNull($figures['cpc']);
        $this->assertNull($figures['cpm']);
        $this->assertNull($figures['roas']);
    }

    /*
     * ── The backfill, on rows written before any of this existed ─────────────────────────────────
     *
     * Production holds 814 such rows: an unconverted provider figure sitting in `spend` with nothing
     * recording which currency it was. These assert what the migration's docblock claims, because
     * the claims are about rewriting real stored money on a live database.
     */

    public function test_a_legacy_row_keeps_its_amount_and_loses_the_wrong_label(): void
    {
        $creative = $this->creative('cr-1');
        $this->legacyRow($creative->id, spend: 4128.93, revenue: 12969.03);

        app(BackfillCreativeMoneyProvenance::class)->execute();

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertNull($row->spend, 'The figure was never in the project currency.');
        $this->assertEqualsWithDelta(4128.93, (float) $row->spend_original, 0.01, 'The amount must survive.');
        $this->assertEqualsWithDelta(12969.03, (float) $row->revenue_original, 0.01);
        $this->assertSame('USD', $row->original_currency, 'One Snapchat account on the project, so it is knowable.');
    }

    /** «Running it twice changes nothing» — the claim most likely to be wrong, and the most costly. */
    public function test_the_backfill_is_idempotent(): void
    {
        $creative = $this->creative('cr-1');
        $this->legacyRow($creative->id, spend: 4128.93, revenue: 12969.03);

        $backfill = app(BackfillCreativeMoneyProvenance::class);
        $backfill->execute();
        $second = $backfill->execute();

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertSame(['moved' => 0, 'currencies' => 0], $second, 'A second pass had work to do.');
        $this->assertEqualsWithDelta(4128.93, (float) $row->spend_original, 0.01, 'The amount was overwritten by a null.');
        $this->assertSame('USD', $row->original_currency);
    }

    /**
     * An ambiguous currency stays NULL.
     *
     * A project binding two Snapchat accounts that spend in different currencies cannot say which
     * one a given row came from — the table has no account column. Null renders as «conversion
     * unavailable», which is true; a guess would be the defect this change exists to remove.
     */
    public function test_an_ambiguous_currency_is_left_unstated_rather_than_guessed(): void
    {
        $second = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->account->provider_connection_id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-2',
            'name' => 'Snap EUR',
            'status' => 'active',
            'currency' => 'EUR',
            'discovered_at' => Carbon::now(),
        ]);

        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $second->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-2', 'name' => 'Second', 'status' => 'active',
        ]);

        $creative = $this->creative('cr-1');
        $this->legacyRow($creative->id, spend: 500.0, revenue: null);

        app(BackfillCreativeMoneyProvenance::class)->execute();

        $row = DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->first();

        $this->assertEqualsWithDelta(500.0, (float) $row->spend_original, 0.01, 'The amount is still kept.');
        $this->assertNull($row->original_currency, 'USD and EUR on one project — the row cannot say which.');
    }

    /** A row the new pipeline already wrote correctly must not be touched. */
    public function test_a_row_that_already_has_its_provenance_is_left_alone(): void
    {
        $creative = $this->creative('cr-1');

        app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 250.0],
        ]);

        $result = app(BackfillCreativeMoneyProvenance::class)->execute();

        $this->assertSame(0, $result['moved']);
        $this->assertEqualsWithDelta(
            250.0,
            (float) DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->value('spend_original'),
            0.01,
        );
    }

    /** The pre-currency shape: an unconverted figure with nothing saying what it is. */
    private function legacyRow(string $creativeId, float $spend, ?float $revenue): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $creativeId,
            'campaign_id' => null,
            'metric_date' => '2026-08-01',
            'spend' => $spend,
            'revenue' => $revenue,
            'impressions' => 2884062,
            'is_demo' => false,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function rate(string $base, string $quote, string $date, float $rate): void
    {
        DB::table('currency_rates')->insert([
            'id' => (string) Str::uuid(),
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate_date' => $date,
            'rate' => $rate,
            'source' => 'test',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function creative(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $this->campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);
    }
}
