<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Ga4ReportFailed;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GA4-INTEGRATION-001 — a measured day arrives, in the property's own calendar, and stays out of the
 * advertising tables.
 *
 * The three claims worth paying for, each with an expensive wrong version:
 *
 * - **A measured day is not an advertising day.** GA4's revenue is measured on the client's own site
 *   under GA4's attribution; an ad platform's is what IT believes its ads caused. One row landing in
 *   `daily_metrics` would be summed into a blended return by a dozen existing queries, and nobody
 *   would ever find it again. The owner's instruction: «Do not calculate a blended ROAS from
 *   incompatible attribution sources.»
 * - **The day belongs to the property.** GA4's date boundary follows the property's configured
 *   timezone. Asking for a server-local date returns a day the client's own Analytics screen does not
 *   have, and the two then disagree for ever.
 * - **DISCOVERED ≠ SELECTED.** An agency identity reaches dozens of clients' properties. Syncing what
 *   it can see reads one client's site traffic because somebody at the agency has access.
 */
final class Ga4PropertySyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ProviderConnection $connection;

    /**
     * What Google will answer NEXT — mutable, and deliberately so.
     *
     * `Http::fake()` MERGES stubs and the FIRST matching one wins, so calling it a second time with a
     * different body changes nothing. The restatement test was passing on that: it asserted the
     * second answer had replaced the first while actually receiving the first answer twice, which is
     * the one thing it exists to disprove. The stub therefore reads these fields at request time.
     */
    private string $sessions = '4100';

    private ?string $revenue = '8900.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'M', 'slug' => 'm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'ga4',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'Google Analytics 4',
        );
    }

    // ── The figures land, where they belong ───────────────────────────────────────────────────

    public function test_a_selected_propertys_days_are_stored_with_the_calendar_that_produced_them(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle();

        $out = app(Ga4PropertySync::class)->sync($property, 2);

        $this->assertSame('Asia/Riyadh', $out['timezone']);
        $this->assertSame(2, $out['days']);

        $rows = MeasurementDailyMetric::withoutGlobalScopes()
            ->where('metric_date', '2026-10-07')
            ->pluck('value', 'metric_key');

        $this->assertSame('4100.000000', $rows['sessions']);
        $this->assertSame('17.000000', $rows['transactions']);
        $this->assertSame('8900.500000', $rows['revenue']);

        // Every row says which calendar it was measured in, and which property.
        $row = MeasurementDailyMetric::withoutGlobalScopes()->where('metric_key', 'sessions')->firstOrFail();
        $this->assertSame('Asia/Riyadh', $row->timezone);
        $this->assertSame('111', $row->property_id);
        $this->assertSame($this->project->id, $row->project_id);
    }

    /**
     * The claim this whole unit rests on.
     *
     * Not one GA4 figure may be visible to a query that sums advertising days — and `daily_metrics`
     * is summed by campaign and by project in a dozen places that will never be audited again.
     */
    public function test_not_one_measured_figure_lands_in_the_advertising_tables(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle();

        app(Ga4PropertySync::class)->sync($property, 2);

        $this->assertGreaterThan(0, MeasurementDailyMetric::withoutGlobalScopes()->count());
        $this->assertSame(
            0,
            DailyMetric::withoutGlobalScopes()->count(),
            'a GA4 figure was written into the advertising metrics table',
        );
        $this->assertSame(
            0,
            DB::table('creative_daily_metrics')->count(),
            'a GA4 figure was written into the creative metrics table',
        );
    }

    /**
     * The window is asked for in the PROPERTY's calendar, not the server's.
     *
     * The clock is frozen at 21:30 UTC, which is already the NEXT day in Riyadh. A sync computing
     * «today» on the server would ask Google for 2026-10-08 while the client's own Analytics screen
     * is already showing 2026-10-09 — and the two would disagree, permanently, by one day.
     */
    public function test_the_window_follows_the_propertys_timezone_and_not_the_servers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 21:30:00', 'UTC'));

        $property = $this->property(selected: true);
        $this->fakeGoogle();

        $out = app(Ga4PropertySync::class)->sync($property, 1);

        $this->assertSame('2026-10-09', $out['to'], 'the window was computed on the server clock');

        $asked = collect(Http::recorded())
            ->first(fn (array $pair): bool => str_contains($pair[0]->url(), ':runReport'));

        $this->assertNotNull($asked);
        $this->assertSame('2026-10-09', $asked[0]->data()['dateRanges'][0]['endDate']);

        Carbon::setTestNow();
    }

    /** A re-run of the same window updates the day rather than doubling it. */
    public function test_a_second_sync_restates_the_day_rather_than_duplicating_it(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle();

        app(Ga4PropertySync::class)->sync($property, 2);
        $first = MeasurementDailyMetric::withoutGlobalScopes()->count();

        // GA4 restates a day for up to 48 hours as late events arrive. The second answer is higher.
        $this->sessions = '4400';

        app(Ga4PropertySync::class)->sync($property, 2);

        $this->assertSame($first, MeasurementDailyMetric::withoutGlobalScopes()->count());
        $this->assertSame(
            '4400.000000',
            MeasurementDailyMetric::withoutGlobalScopes()
                ->where('metric_date', '2026-10-07')->where('metric_key', 'sessions')->value('value'),
            'the restated day did not replace the first, incomplete one',
        );
    }

    // ── What it refuses to do ─────────────────────────────────────────────────────────────────

    /**
     * A discovered property nobody selected is refused, and reads nothing.
     *
     * The normal state for most of an agency's estate. Syncing it would both spend that client's Data
     * API quota and file their site's traffic under no project — which is to say under whichever
     * project read the table next.
     */
    public function test_a_property_nobody_selected_is_refused_and_no_call_is_made(): void
    {
        $property = $this->property(selected: false);
        $this->fakeGoogle();

        $this->expectException(Ga4NotSelected::class);

        try {
            app(Ga4PropertySync::class)->sync($property, 2);
        } finally {
            $this->assertSame(0, MeasurementDailyMetric::withoutGlobalScopes()->count());
            Http::assertNothingSent();
        }
    }

    /**
     * A metric the property did not report is ABSENT, never a zero.
     *
     * A property with no ecommerce configured reports no revenue. Writing that down as 0.00 would tell
     * a client their site earned nothing — «Never convert unavailable into zero».
     */
    public function test_a_metric_the_property_did_not_report_is_not_written_as_zero(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle(revenue: null);

        app(Ga4PropertySync::class)->sync($property, 2);

        $this->assertSame(
            0,
            MeasurementDailyMetric::withoutGlobalScopes()->where('metric_key', 'revenue')->count(),
            'an unreported revenue was stored as a zero',
        );
        // …while the metrics it DID report are there, so the absence is specific and not collateral.
        $this->assertGreaterThan(0, MeasurementDailyMetric::withoutGlobalScopes()->where('metric_key', 'sessions')->count());
    }

    /** A refusal is raised, never written down as a day of zeros. */
    public function test_a_refused_report_is_raised_rather_than_stored_as_an_empty_day(): void
    {
        $property = $this->property(selected: true);

        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR'], 200),
            '*analyticsdata.googleapis.com*' => Http::response(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429),
        ]);

        $this->expectException(Ga4ReportFailed::class);

        try {
            app(Ga4PropertySync::class)->sync($property, 2);
        } finally {
            $this->assertSame(0, MeasurementDailyMetric::withoutGlobalScopes()->count());
        }
    }

    /** A property that states no timezone is refused rather than defaulted to UTC. */
    public function test_a_property_without_a_timezone_is_refused_rather_than_assumed_utc(): void
    {
        $property = $this->property(selected: true);

        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response(['displayName' => 'Acme'], 200),
            '*analyticsdata.googleapis.com*' => Http::response(['rows' => []], 200),
        ]);

        $this->expectException(Ga4ReportFailed::class);

        app(Ga4PropertySync::class)->sync($property, 2);
    }

    // ── Money, and what is not money ──────────────────────────────────────────────────────────

    /**
     * The currency rides on money and on nothing else.
     *
     * A currency stamped on a session count would be read by the display layer as «this is money»,
     * and an engagement rate shown as ‏61.00 ر.س is the sort of thing a client screenshots.
     */
    public function test_the_propertys_currency_is_carried_by_money_and_by_nothing_else(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle();

        app(Ga4PropertySync::class)->sync($property, 2);

        $rows = MeasurementDailyMetric::withoutGlobalScopes()
            ->where('metric_date', '2026-10-07')->get()->keyBy('metric_key');

        $this->assertSame('SAR', $rows['revenue']->currency);
        $this->assertNull($rows['sessions']->currency);
        $this->assertNull($rows['engagement_rate']->currency);

        // …and the property itself now knows its own settings, which discovery never asked for.
        $this->assertSame('SAR', $property->refresh()->currency);
        $this->assertSame('Asia/Riyadh', $property->timezone);
    }

    /** Another tenant's measured days are not this tenant's to read. */
    public function test_another_tenants_measured_days_are_not_visible(): void
    {
        $property = $this->property(selected: true);
        $this->fakeGoogle();

        app(Ga4PropertySync::class)->sync($property, 2);
        $this->assertGreaterThan(0, MeasurementDailyMetric::count());

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);

        $this->assertSame(
            0,
            MeasurementDailyMetric::count(),
            'another tenant could read this tenant\'s measured days',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function property(bool $selected): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'provider_connection_id' => $this->connection->getKey(),
            'provider' => 'ga4',
            'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => '111',
            'name' => 'Acme Store',
            'parent_external_id' => '100',
            'parent_name' => 'Acme Group',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        if ($selected) {
            /*
             * Selection goes through the EXISTING binding, with `purpose = analytics`. There is no
             * parallel selection path for measurement, and this fixture is where that shows.
             */
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'client_workspace_id' => $this->project->client_workspace_id,
                'project_id' => $this->project->id,
                'external_account_id' => $account->id,
                'provider' => 'ga4',
                'purpose' => 'analytics',
                'is_active' => true,
            ]);
        }

        return $account;
    }

    /**
     * Two hosts, two answers — the Admin API for what the property IS, the Data API for its days.
     *
     * `metricHeaders` are returned in a DIFFERENT order from the request on purpose: reading
     * `metricValues[3]` as revenue because revenue was fourth in the request is a bug that produces
     * plausible numbers in the wrong rows, and this fixture is what catches it.
     */
    private function fakeGoogle(string $sessions = '4100', ?string $revenue = '8900.5'): void
    {
        $this->sessions = $sessions;
        $this->revenue = $revenue;

        Http::fake([
            '*analyticsadmin.googleapis.com*' => Http::response([
                'displayName' => 'Acme Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR',
            ], 200),
            '*analyticsdata.googleapis.com*' => function (): PromiseInterface {
                /*
                 * `metricHeaders` are returned in a DIFFERENT order from the request on purpose:
                 * reading `metricValues[2]` as revenue because revenue was third in the request is a
                 * bug that produces plausible numbers in the wrong rows, and this is what catches it.
                 */
                $headers = ['transactions', 'sessions', 'purchaseRevenue', 'engagementRate'];
                $values = ['17', $this->sessions, $this->revenue, '0.6134'];

                if ($this->revenue === null) {
                    // The property reports no revenue at all — no ecommerce configured.
                    $headers = ['transactions', 'sessions', 'engagementRate'];
                    $values = ['17', $this->sessions, '0.6134'];
                }

                return Http::response([
                    'metricHeaders' => array_map(static fn (string $n): array => ['name' => $n], $headers),
                    'rows' => array_map(static fn (string $date): array => [
                        'dimensionValues' => [['value' => $date]],
                        'metricValues' => array_map(static fn (?string $v): array => ['value' => $v], $values),
                    ], ['20261007', '20261008']),
                ], 200);
            },
        ]);
    }
}
