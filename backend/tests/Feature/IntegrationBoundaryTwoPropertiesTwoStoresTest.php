<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Jobs\SyncMeasurementPropertyJob;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (cases 3 and 4) — two GA4 properties under ONE Google grant, two Salla stores under ONE
 * merchant authorisation: only the SELECTED one is imported, and only the selected one is read.
 *
 * The matrix of the contract (§3): «two GA4 properties in the same account: only the selected one is synced» and «two stores of
 * one merchant: no order or product of an unselected store enters». Both were `IMPLEMENTED_NOT_VERIFIED` with unit-level proof
 * only (`Ga4PropertySyncTest::test_a_property_nobody_selected_is_refused_and_no_call_is_made`, `CommerceStoreIsolationTest`,
 * `SyncCensusTest`); nothing asserted the SWEEP, the queue, the provider traffic and the reader together for the same pair.
 *
 * Each case is asserted at four levels: the sweep (which jobs are queued), the job (what an already-queued job for the unselected
 * resource does), the provider traffic (`Http::recorded()` — the unselected id is never named) and the stored rows and read
 * endpoint (nothing of the unselected one enters or is shown).
 */
final class IntegrationBoundaryTwoPropertiesTwoStoresTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->bwTenant();
        $workspace = $this->bwWorkspace($this->tenant);
        $this->project = $this->bwProject($this->tenant, $workspace);
        $this->operator = $this->bwOperator($this->tenant);
        $this->bwConfigure('salla', 'commerce_platforms');
    }

    // ── GA4: two properties, one grant ────────────────────────────────────────────────────────

    /** @return array{0: ExternalAccount, 1: ExternalAccount} [selected 111, discovered-only 222] */
    private function twoProperties(): array
    {
        $grant = $this->bwConnection($this->tenant, 'ga4');
        $selected = $this->bwAccount($grant, '111', Ga4PropertyDiscovery::ACCOUNT_TYPE, ['currency' => null, 'name' => 'Acme Store']);
        $other = $this->bwAccount($grant, '222', Ga4PropertyDiscovery::ACCOUNT_TYPE, ['currency' => null, 'name' => 'Acme Blog']);
        $this->bwBind($selected, $this->project, 'analytics');

        return [$selected, $other];
    }

    public function test_the_measurement_sweep_queues_only_the_selected_property(): void
    {
        [$selected, $other] = $this->twoProperties();
        Queue::fake();

        $this->artisan('measurement:sync')->assertSuccessful();

        Queue::assertPushed(SyncMeasurementPropertyJob::class, 1);
        Queue::assertPushed(SyncMeasurementPropertyJob::class, fn (SyncMeasurementPropertyJob $job) => str_contains($job->uniqueId(), (string) $selected->id));
        Queue::assertNotPushed(SyncMeasurementPropertyJob::class, fn (SyncMeasurementPropertyJob $job) => str_contains($job->uniqueId(), (string) $other->id));
    }

    public function test_only_the_selected_propertys_data_is_requested_stored_and_shown(): void
    {
        [$selected, $other] = $this->twoProperties();
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $admin = str_contains($r->url(), 'analyticsadmin');

            return $admin
                ? Http::response(['displayName' => 'x', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'USD'], 200)
                : Http::response([
                    'metricHeaders' => [['name' => 'sessions']],
                    'rows' => [['dimensionValues' => [['value' => Carbon::now('Asia/Riyadh')->format('Ymd')]], 'metricValues' => [['value' => '100']]]],
                ], 200);
        });

        // The sweep's job for the selected property runs; so does a STALE job queued earlier for the other one.
        SyncMeasurementPropertyJob::dispatchSync((string) $selected->id, 2);
        SyncMeasurementPropertyJob::dispatchSync((string) $other->id, 2);

        $asked = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->all();
        $this->assertNotEmpty($asked, 'the selected property was never read — the test proved nothing');
        foreach ($asked as $url) {
            $this->assertStringNotContainsString('/properties/222', $url, 'the unselected property was requested from Google');
            $this->assertStringContainsString('/properties/111', $url);
        }

        $this->assertSame(['111'], MeasurementDailyMetric::withoutGlobalScopes()->distinct()->pluck('property_id')->all(), 'a measured day of another property was stored');
        $this->assertSame(0, MeasurementDailyMetric::withoutGlobalScopes()->where('external_account_id', $other->id)->count());

        // The manual button gets the same refusal, and no call.
        Http::fake();
        try {
            app(Ga4PropertySync::class)->sync($other->refresh(), 2);
            $this->fail('the unselected property was synced by hand');
        } catch (Ga4NotSelected) {
            // refused
        }
        Http::assertNothingSent();

        // The reader: figures of the unselected property are in the table under this project (it was once bound here) — never shown.
        $now = now();
        $selectedSessions = (float) DB::table('measurement_dimension_rows')->where('external_account_id', $selected->id)->where('breakdown', 'device')->get()
            ->sum(fn (object $r): float => (float) (json_decode((string) $r->metrics, true)['sessions'] ?? 0));
        $this->assertGreaterThan(0.0, $selectedSessions, 'the selected property stored no device rows — the reader check would be vacuous');
        foreach ([[$other, 7000]] as [$property, $sessions]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $property->id, 'property_id' => $property->external_id, 'metric_date' => Carbon::now()->toDateString(),
                'breakdown' => 'device', 'dimension_1' => 'mobile', 'dimension_2' => '', 'metrics' => json_encode(['sessions' => $sessions]),
                'currency' => null, 'timezone' => 'Asia/Riyadh', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $page = $this->actingAs($this->operator, 'sanctum')->withHeader('X-Project-Id', $this->project->id)
            ->getJson("/api/v1/projects/{$this->project->id}/measurement/analytics")->assertOk()->json('data');

        $this->assertSame('111', $page['property']['id'] ?? null, 'the page names a property that is not the selected one');
        $this->assertEqualsWithDelta($selectedSessions, (float) ($page['totals']['sessions'] ?? -1), 0.001, 'the unselected property\'s sessions were added to the selected one\'s');
        $this->assertStringNotContainsString('Acme Blog', (string) json_encode($page));
    }

    // ── Commerce: two stores, one merchant ────────────────────────────────────────────────────

    /** @return array{0: ExternalAccount, 1: ExternalAccount} [selected, discovered-only] */
    private function twoStores(): array
    {
        $grant = $this->bwConnection($this->tenant, 'salla');
        $selected = $this->bwAccount($grant, 'store_1', 'store', ['currency' => 'USD', 'name' => 'Main store']);
        $other = $this->bwAccount($grant, 'store_2', 'store', ['currency' => 'USD', 'name' => 'Outlet store']);
        $this->bwBind($selected, $this->project, 'ecommerce');

        return [$selected, $other];
    }

    public function test_the_store_sweep_queues_only_the_selected_store(): void
    {
        [$selected, $other] = $this->twoStores();
        Queue::fake();

        $this->artisan('commerce:sync')->assertSuccessful();

        Queue::assertPushed(SyncStoreJob::class, 1);
        Queue::assertPushed(SyncStoreJob::class, fn (SyncStoreJob $job) => str_contains($job->uniqueId(), (string) $selected->id));
        Queue::assertNotPushed(SyncStoreJob::class, fn (SyncStoreJob $job) => str_contains($job->uniqueId(), (string) $other->id));
    }

    public function test_only_the_selected_stores_orders_and_products_are_imported_and_shown(): void
    {
        [$selected, $other] = $this->twoStores();
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_contains($url, '/store/info')) {
                return Http::response(['data' => ['id' => 'store_1', 'name' => 'Main store']]);
            }
            if (str_contains($url, '/products')) {
                return Http::response(['data' => [['id' => 'p1', 'name' => 'A', 'price' => ['amount' => 10, 'currency' => 'USD']]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
            }
            if (str_contains($url, '/orders/o-main')) {
                return Http::response(['data' => ['id' => 'o-main', 'items' => [['id' => 'i1', 'name' => 'A', 'quantity' => 1, 'sku' => 'A-1', 'product' => ['id' => 'p1'], 'amounts' => ['total' => ['amount' => 300.0, 'currency' => 'USD']]]]]]);
            }
            if (str_contains($url, '/orders')) {
                return Http::response(['data' => [[
                    'id' => 'o-main', 'reference_id' => 1, 'status' => ['slug' => 'completed', 'name' => 'x'],
                    'date' => ['date' => '2026-08-01 10:00:00.000000', 'timezone' => 'Asia/Riyadh'],
                    'amounts' => ['sub_total' => ['amount' => 300.0, 'currency' => 'USD'], 'shipping_cost' => ['amount' => 0.0, 'currency' => 'USD'], 'total' => ['amount' => 300.0, 'currency' => 'USD']],
                    'customer' => ['id' => 'c1', 'first_name' => 'a', 'last_name' => 'b', 'email' => 'a@b.sa'],
                ]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
            }

            return Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
        });

        // The sweep's job for the selected store, and a stale job queued earlier for the unselected one.
        SyncStoreJob::dispatchSync((string) $selected->id, '2026-08-01', '2026-08-05');
        $before = count(Http::recorded());
        SyncStoreJob::dispatchSync((string) $other->id, '2026-08-01', '2026-08-05');
        $this->assertCount($before, Http::recorded(), 'a job for the unselected store reached the provider');

        $this->assertSame(['o-main'], CommerceOrder::withoutGlobalScopes()->pluck('external_id')->all());
        $this->assertSame([(string) $selected->id], CommerceOrder::withoutGlobalScopes()->pluck('external_account_id')->map(fn ($v) => (string) $v)->unique()->values()->all());
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->where('external_account_id', $other->id)->count());
        $this->assertSame(0, CommerceProduct::withoutGlobalScopes()->where('external_account_id', $other->id)->count());

        // The reader: the other store holds orders under this project (it was once bound here) — the funnel never counts them.
        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $other->id,
            'provider' => 'salla', 'external_id' => 'o-outlet', 'status' => 'completed', 'placed_at' => Carbon::now()->subDay(),
            'currency' => 'USD', 'total' => 9000, 'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);
        CommerceOrder::withoutGlobalScopes()->where('external_id', 'o-main')->update(['placed_at' => Carbon::now()->subDay()]);

        $funnel = $this->actingAs($this->operator, 'sanctum')->withHeader('X-Project-Id', $this->project->id)
            ->getJson("/api/v1/projects/{$this->project->id}/commerce/funnel")->assertOk()->json('data');
        $revenue = (float) (collect($funnel['stages'])->keyBy('key')['revenue']['value'] ?? -1);

        $this->assertEqualsWithDelta(300.0, $revenue, 0.001, 'the unselected store\'s revenue entered the project\'s funnel');
        $this->assertStringNotContainsString('Outlet store', (string) json_encode($funnel), 'the unselected store is named on the project');
    }

    public function test_a_delivery_for_the_unselected_store_queues_no_sync_and_one_for_the_selected_store_queues_exactly_one(): void
    {
        [$selected, $other] = $this->twoStores();
        app(ProviderConfigurationService::class)->save('salla', ['client_id' => 'app-1', 'client_secret' => 'salla-secret', 'webhook_secret' => 'hook-secret']);
        Queue::fake();

        $deliver = function (string $merchantStore, string $eventId): void {
            $body = json_encode(['event' => 'order.updated', 'merchant' => $merchantStore, 'event_id' => $eventId]);
            $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $body, 'hook-secret'),
            ], $body)->assertOk();
        };

        $deliver('store_2', 'evt-other');
        Queue::assertNotPushed(SyncStoreJob::class);
        $event = IntegrationWebhookEvent::query()->where('provider', 'salla')->where('external_event_id', 'evt-other')->first();
        $this->assertNotSame('processed', $event?->status, 'a delivery for an unselected store was processed as if it fed a project');

        $deliver('store_1', 'evt-main');
        Queue::assertPushed(SyncStoreJob::class, 1);
        Queue::assertPushed(SyncStoreJob::class, fn (SyncStoreJob $job) => str_contains($job->uniqueId(), (string) $selected->id));
        Queue::assertNotPushed(SyncStoreJob::class, fn (SyncStoreJob $job) => str_contains($job->uniqueId(), (string) $other->id));
    }
}
