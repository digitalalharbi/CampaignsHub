<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\InsightRowNormaliser;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * FX-001 — money arrives in the currency the platform spent it in, and is reported in one.
 *
 * ## The defect, stated plainly
 *
 * `daily_metrics` has carried `original_currency`, `project_currency`, `original_amount`,
 * `converted_amount` and `exchange_rate` since C3.1. Nothing populated them:
 * `AccountMetricsSyncer::ingest()` built every metric from `value` alone. A SAR ad account's spend was
 * written as a bare number and summed into a USD dashboard as though it were dollars.
 *
 * `test_the_old_pipeline_would_have_added_riyals_to_dollars` is the fail-first proof, written so it
 * fails against the previous code and passes against this one — see its own note for how.
 *
 * The basis is USD for every project (MONEY-USD-001). It is not read from the workspace: `value` is
 * the column every screen sums, and a per-tenant basis would make two projects unaddable.
 *
 * ## What is deliberately NOT here
 *
 * A live rate feed. The rates in these tests are rows in `currency_rates`, which is where a feed would
 * put them; wiring one is an external-credentials task and inventing a rate in code would be exactly
 * the hidden fixed rate this unit forbids.
 */
final class ReportingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** 1/3.75 — the riyal's peg read the way this codebase converts, SAR into the canonical USD. */
    private const SAR_USD = 0.2666666667;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'fx-agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        /*
         * SAR deliberately, and it is a guard rather than a setting: the reporting currency is USD
         * regardless of what a workspace prefers. See the MONEY-USD-001 test at the foot of the file.
         */
        $workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'fx-client', 'mode' => 'managed', 'default_currency' => 'SAR']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    private function uid(string $label): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "fx-test:{$label}");
    }

    private function rate(string $from, string $to, float $rate, string $date, string $source = 'ecb'): void
    {
        CurrencyRate::create([
            'base_currency' => $from, 'quote_currency' => $to,
            'rate' => $rate, 'rate_date' => $date, 'source' => $source,
        ]);
    }

    /**
     * An ad account in `$currency`, with one discovered campaign for its insights to attach to.
     */
    private function account(string $currency, string $label = 'a'): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta-'.$label, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => $this->uid("acct-{$label}"),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => "act_{$label}",
            'name' => "Account {$label}",
            'currency' => $currency,
            'status' => 'active',
        ])->save();

        $campaign = new ExternalCampaign;
        $campaign->forceFill([
            'id' => $this->uid("camp-{$label}"),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'external_id' => "c-{$label}",
            'name' => "Campaign {$label}",
            'provider' => 'meta',
            'status' => 'active',
        ])->save();

        return $account;
    }

    /**
     * Run the real ingest chain: normalise the rows, upsert them, and leave them to be read.
     *
     * The connector is deliberately absent. It contributes nothing to what a figure MEANS — its own
     * adapter tests already cover the mapping from a platform's field names — and standing one up
     * here would only reintroduce the plumbing that kept this half of the pipeline untested.
     */
    private function sync(ExternalAccount $account, array $records): void
    {
        [$metrics] = app(InsightRowNormaliser::class)->normalise($account, $records, MetricsAggregator::readKeys());

        app(UpsertDailyMetrics::class)->handle($metrics);
    }

    /** Read the window the way every screen does — through the aggregator, scoped by context. */
    private function totals(Carbon $from, Carbon $to): array
    {
        app(ProjectContext::class)->setProjectId($this->project->id);

        return app(MetricsAggregator::class)->totals($from, $to);
    }

    private function spendRow(string $key = 'spend'): ?DailyMetric
    {
        return DailyMetric::withoutGlobalScopes()->where('metric_key', $key)->first();
    }

    // ── the fail-first proof ─────────────────────────────────────────────────────────────────────

    /**
     * THE DEFECT: two currencies added together, and the sum presented as one of them.
     *
     * 1,000 SAR at 0.2666… is 266.67 USD, so a project spending 1,000 USD on one account and 1,000
     * SAR on another has spent 1,266.67 USD. The old pipeline wrote both figures raw and answered
     * 2,000 — a number that is not true in any currency, and the reason nothing looked broken is that
     * every screen was wrong in the same way.
     *
     * Fail-first, and MEASURED rather than asserted from memory: reproducing the old pass-through
     * (the normaliser with `spend` unmarked as currency, which is exactly what the previous code did
     * unconditionally) makes this window total **2000.0**, overstating the real 1,266.67 by more than
     * half.
     */
    public function test_the_old_pipeline_would_have_added_riyals_to_dollars(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('USD', 'usd'), [['campaign_id' => 'c-usd', 'date' => '2026-06-01', 'spend' => 1000]]);
        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 1000]]);

        $totals = $this->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(1266.67, (float) $totals['spend'], 0.01, 'riyals are being added to dollars');
    }

    // ── conversion, per currency ─────────────────────────────────────────────────────────────────

    /** USD → USD is an identity, and is still RECORDED as one. */
    public function test_the_reporting_currency_needs_no_rate_and_is_still_labelled(): void
    {
        $this->sync($this->account('USD', 'usd'), [['campaign_id' => 'c-usd', 'date' => '2026-06-01', 'spend' => 500]]);

        $row = $this->spendRow();

        $this->assertEqualsWithDelta(500.0, (float) $row->value, 0.01);
        $this->assertSame('USD', $row->original_currency);
        $this->assertSame('USD', $row->project_currency);
        $this->assertEqualsWithDelta(500.0, (float) $row->original_amount, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $row->exchange_rate, 0.000001);
    }

    /** SAR → USD converts, and keeps the riyals it started from. */
    public function test_a_riyal_account_is_converted_and_the_original_survives(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750]]);

        $row = $this->spendRow();

        $this->assertEqualsWithDelta(200.0, (float) $row->value, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $row->converted_amount, 0.01);
        $this->assertEqualsWithDelta(750.0, (float) $row->original_amount, 0.01, 'the riyals were destroyed');
        $this->assertSame('SAR', $row->original_currency);
        $this->assertSame('USD', $row->project_currency);
        $this->assertEqualsWithDelta(self::SAR_USD, (float) $row->exchange_rate, 0.000001);
    }

    /** A third currency is not a special case — the same lookup, the same columns. */
    public function test_a_third_currency_converts_the_same_way(): void
    {
        $this->rate('AED', 'USD', 0.2723, '2026-06-01');

        $this->sync($this->account('AED', 'aed'), [['campaign_id' => 'c-aed', 'date' => '2026-06-01', 'spend' => 100]]);

        $row = $this->spendRow();

        $this->assertEqualsWithDelta(27.23, (float) $row->value, 0.01);
        $this->assertSame('AED', $row->original_currency);
    }

    /**
     * The row's OWN currency beats the account's.
     *
     * A platform that labels each insight is the authority on its own figures — an account whose
     * settings say USD can still report a campaign billed in another currency.
     */
    public function test_a_currency_on_the_row_beats_the_accounts(): void
    {
        $this->rate('AED', 'USD', 0.2723, '2026-06-01');

        $this->sync($this->account('USD', 'mix'), [
            ['campaign_id' => 'c-mix', 'date' => '2026-06-01', 'spend' => 100, 'currency' => 'AED'],
        ]);

        $this->assertSame('AED', $this->spendRow()->original_currency);
    }

    // ── the dated rate ───────────────────────────────────────────────────────────────────────────

    /**
     * A historical day is converted at ITS rate, not at today's.
     *
     * This is the whole reason `currency_rates` carries a date rather than one row per pair. Last
     * quarter's report must not move because the riyal moved this morning.
     */
    public function test_a_historical_day_uses_the_rate_of_that_day(): void
    {
        $this->rate('SAR', 'USD', 0.26, '2026-01-01');
        $this->rate('SAR', 'USD', 0.28, '2026-06-01');

        $this->sync($this->account('SAR', 'old'), [['campaign_id' => 'c-old', 'date' => '2026-03-15', 'spend' => 100]]);

        $row = $this->spendRow();

        $this->assertEqualsWithDelta(26.0, (float) $row->value, 0.01, 'a March day was converted at June’s rate');
        // Nearest on-or-before: the rate genuinely came from January, and the row says so rather than
        // implying a quote existed on the fifteenth of March.
        $this->assertSame('2026-01-01', Carbon::parse((string) $row->rate_date)->toDateString());
        $this->assertSame('ecb', $row->rate_source);
    }

    // ── fail-closed ──────────────────────────────────────────────────────────────────────────────

    /**
     * No rate → no figure. Not zero, and not the unconverted amount.
     *
     * Zero reads as «this campaign spent nothing», which is the most damaging number this product can
     * show. The unconverted amount is the original defect. A null says «there is a monetary fact here
     * and no rate anybody can vouch for», and the original survives beside it.
     */
    public function test_a_missing_rate_withholds_the_figure_rather_than_inventing_one(): void
    {
        // Deliberately no rate for JPY.
        $this->sync($this->account('JPY', 'jpy'), [['campaign_id' => 'c-jpy', 'date' => '2026-06-01', 'spend' => 5000]]);

        $row = $this->spendRow();

        $this->assertNull($row->value, 'a figure was published that nothing could vouch for');
        $this->assertNull($row->converted_amount);
        $this->assertNull($row->exchange_rate);
        $this->assertEqualsWithDelta(5000.0, (float) $row->original_amount, 0.01, 'the original was lost too');
        $this->assertSame('JPY', $row->original_currency);
    }

    /** A withheld row does not poison the total, and does not silently vanish from it either. */
    public function test_a_withheld_figure_is_excluded_from_totals_and_is_countable(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750]]);
        $this->sync($this->account('JPY', 'jpy'), [['campaign_id' => 'c-jpy', 'date' => '2026-06-01', 'spend' => 5000]]);

        $totals = $this->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(200.0, (float) $totals['spend'], 0.01, 'an unconvertible figure entered the total');

        $withheld = DailyMetric::withoutGlobalScopes()
            ->where('metric_key', 'spend')->whereNull('value')->count();

        $this->assertSame(1, $withheld, 'the withheld row is not countable, so nobody can be told');
    }

    /**
     * FX-WITHHELD-UI-001 — the AGGREGATOR must say «withheld», not just the database.
     *
     * The test above proves a withheld row is countable with a direct query. No screen runs direct
     * queries: every surface reads `MetricsAggregator`, and until now that returned `spend => 0` with
     * nothing beside it. So a project whose platform reported 5,000 JPY rendered «0» on the
     * dashboard, «0 USD» as CPA and «—» as ROAS, over a label reading «لم ترسله المنصة» — which is
     * false, because the platform sent it and we withheld it.
     *
     * The totals now carry the original amount and the withheld row count, so a reader can show the
     * real figure and say the conversion is unavailable, instead of showing a zero that is a lie.
     */
    public function test_the_totals_report_the_withheld_original_beside_the_converted_zero(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750]]);
        // No JPY rate exists, so this 5,000 is withheld rather than invented.
        $this->sync($this->account('JPY', 'jpy'), [['campaign_id' => 'c-jpy', 'date' => '2026-06-01', 'spend' => 5000]]);

        $totals = $this->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-01'));

        // The converted total still excludes what cannot be converted — that part was always right.
        $this->assertEqualsWithDelta(200.0, (float) $totals['spend'], 0.01);

        // What is new: the total itself now admits the withholding.
        $this->assertSame(
            1,
            (int) $totals['spend_withheld_rows'],
            'The aggregator hides the withholding, so every screen reading it must render 0 as if it were true.',
        );

        /*
         * ONLY the withheld original — 5,000 JPY. The 750 SAR converted perfectly well and is already
         * inside the 200 USD asserted above; adding its original here would count it twice and
         * overstate what could not be converted.
         *
         * This assertion read 5,750 at first, which encoded the bug rather than the rule. The
         * breakdown test is what exposed it, by grouping a converted row beside an unconvertible one.
         */
        $this->assertEqualsWithDelta(
            5000.0,
            (float) $totals['spend_original'],
            0.01,
            'The withheld original is wrong, so a card would state an amount nobody withheld.',
        );
    }

    /**
     * MONEY-TRUTH-002 — the BREAKDOWNS carry provenance too, not only the grand total.
     *
     * `totals()` learned this first, and platform comparison and campaign ranking read different
     * methods entirely. A platform that spent 5,000 JPY ranked as having spent nothing, directly
     * beneath a summary card showing the real amount — the same lie one level down.
     */
    public function test_the_provider_breakdown_reports_withheld_money(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750]]);
        $this->sync($this->account('JPY', 'jpy'), [['campaign_id' => 'c-jpy', 'date' => '2026-06-01', 'spend' => 5000]]);

        app(ProjectContext::class)->setProjectId($this->project->id);
        $rows = app(MetricsAggregator::class)->byProvider(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-01'));
        app(ProjectContext::class)->forget();

        $this->assertNotEmpty($rows, 'The breakdown returned nothing at all.');

        $withheld = array_values(array_filter($rows, fn ($r) => (int) ($r['spend_withheld_rows'] ?? 0) > 0));

        $this->assertNotEmpty(
            $withheld,
            'No row admits the withholding, so a platform that spent real money ranks as having spent nothing.',
        );
        $this->assertEqualsWithDelta(5000.0, (float) $withheld[0]['spend_original'], 0.01);
        $this->assertSame('JPY', $withheld[0]['money_original_currency']);
    }

    /** Once the rate exists, a re-sync replaces the withheld figure — nothing has to be re-fetched. */
    public function test_a_resync_converts_a_row_that_was_withheld(): void
    {
        $rows = [['campaign_id' => 'c-jpy', 'date' => '2026-06-01', 'spend' => 5000]];
        $account = $this->account('JPY', 'jpy');

        $this->sync($account, $rows);
        $this->assertNull($this->spendRow()->value);

        $this->rate('JPY', 'USD', 0.0067, '2026-06-01');
        $this->sync($account, $rows);

        $this->assertEqualsWithDelta(33.5, (float) $this->spendRow()->refresh()->value, 0.01);
    }

    // ── money only ───────────────────────────────────────────────────────────────────────────────

    /**
     * Counts are not multiplied by a rate.
     *
     * Impressions × 0.2666… is nonsense that still looks like a number, which is exactly the kind
     * that survives a review. `metric_definitions.is_currency` is what decides, so a metric
     * catalogued as money later is normalised without anybody editing the syncer.
     */
    public function test_counts_are_never_converted(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [[
            'campaign_id' => 'c-sar', 'date' => '2026-06-01',
            'spend' => 750, 'impressions' => 1000, 'clicks' => 50, 'purchases' => 4,
        ]]);

        foreach (['impressions' => 1000.0, 'clicks' => 50.0, 'purchases' => 4.0] as $key => $expected) {
            $row = $this->spendRow($key);
            $this->assertEqualsWithDelta($expected, (float) $row->value, 0.01, "{$key} was converted");
            $this->assertNull($row->original_currency, "{$key} was labelled with a currency");
            $this->assertNull($row->exchange_rate);
        }
    }

    /** Revenue is money and is converted with the same rate spend was. */
    public function test_revenue_is_converted_like_spend(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [[
            'campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750, 'revenue' => 3000,
        ]]);

        $this->assertEqualsWithDelta(800.0, (float) $this->spendRow('revenue')->value, 0.01);
        $this->assertEqualsWithDelta(self::SAR_USD, (float) $this->spendRow('revenue')->exchange_rate, 0.000001);
    }

    // ── edges ────────────────────────────────────────────────────────────────────────────────────

    /** Zero is a real figure and stays zero — converted, labelled, and not confused with «unknown». */
    public function test_zero_spend_is_converted_and_stays_zero(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 0]]);

        $row = $this->spendRow();

        $this->assertEqualsWithDelta(0.0, (float) $row->value, 0.000001);
        $this->assertNotNull($row->original_currency, 'a zero lost its currency and reads as unconvertible');
    }

    /** An unlabelled account is treated as already reporting — stated, not assumed silently. */
    public function test_an_account_with_no_currency_is_treated_as_the_reporting_currency(): void
    {
        $account = $this->account('USD', 'none');
        $account->forceFill(['currency' => null])->save();

        $this->sync($account, [['campaign_id' => 'c-none', 'date' => '2026-06-01', 'spend' => 90]]);

        $this->assertSame('USD', $this->spendRow()->original_currency);
        $this->assertEqualsWithDelta(90.0, (float) $this->spendRow()->value, 0.01);
    }

    /** Several currencies in one window all land in one reporting currency, and add up correctly. */
    public function test_three_currencies_in_one_window_aggregate_correctly(): void
    {
        $this->rate('SAR', 'USD', self::SAR_USD, '2026-06-01');
        $this->rate('AED', 'USD', 0.2723, '2026-06-01');

        $this->sync($this->account('SAR', 'sar'), [['campaign_id' => 'c-sar', 'date' => '2026-06-01', 'spend' => 750]]);
        $this->sync($this->account('AED', 'aed'), [['campaign_id' => 'c-aed', 'date' => '2026-06-01', 'spend' => 100]]);
        $this->sync($this->account('USD', 'usd'), [['campaign_id' => 'c-usd', 'date' => '2026-06-01', 'spend' => 100]]);

        $totals = $this->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-01'));

        // 200 + 27.23 + 100
        $this->assertEqualsWithDelta(327.23, (float) $totals['spend'], 0.01);
    }

    /**
     * MONEY-USD-001 — a workspace's own currency does NOT override the canonical basis.
     *
     * This test asserted the opposite until the basis was fixed: `forProject()` read
     * `client_workspaces.default_currency` and normalised into it, so this project stored riyals and
     * a dollar-preferring one stored dollars. That is two truths in one column. `value` is what every
     * read path sums — dashboard, analytics, funnel, reports, public links — so a per-workspace basis
     * means two projects cannot be added together and the same scope answers differently depending on
     * who asked.
     *
     * The workspace built in `setUp()` still says SAR, deliberately: it is the guard. If somebody
     * reinstates the override, this fails rather than silently splitting the column again.
     *
     * What a workspace may still choose is how money is DISPLAYED — applied on the way out, over one
     * stored basis.
     */
    public function test_a_workspace_currency_does_not_override_the_canonical_basis(): void
    {
        $workspace = ClientWorkspace::create(['name' => 'US Client', 'slug' => 'fx-us', 'mode' => 'managed', 'default_currency' => 'USD']);
        $project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'US', 'status' => 'active']);

        $this->assertSame('USD', app(ReportingCurrency::class)->forProject((string) $project->id));
        $this->assertSame(
            'USD',
            app(ReportingCurrency::class)->forProject((string) $this->project->id),
            'A workspace preference reopened a second aggregation basis.',
        );
    }

    // ── the resolver itself ──────────────────────────────────────────────────────────────────────

    /** An inverse pair resolves, and says the figure was derived rather than published that way. */
    public function test_an_inverse_pair_resolves_and_is_labelled_as_derived(): void
    {
        $this->rate('SAR', 'USD', 0.2667, '2026-06-01');

        $resolved = app(CurrencyConverter::class)->resolve('USD', 'SAR', Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(3.7495, $resolved['rate'], 0.001);
        $this->assertSame('ecb:inverse', $resolved['source']);
    }

    /** A rate published AFTER the date is not used — that would be hindsight, not history. */
    public function test_a_rate_published_after_the_date_is_not_used(): void
    {
        $this->rate('USD', 'SAR', 3.75, '2026-07-01');

        $this->assertNull(app(CurrencyConverter::class)->resolve('USD', 'SAR', Carbon::parse('2026-06-01')));
    }

    /** The resolver reports absence instead of throwing, so one bad day cannot abort a whole sync. */
    public function test_the_resolver_reports_absence_rather_than_throwing(): void
    {
        $this->assertNull(app(CurrencyConverter::class)->resolve('JPY', 'SAR', Carbon::parse('2026-06-01')));
    }
}
