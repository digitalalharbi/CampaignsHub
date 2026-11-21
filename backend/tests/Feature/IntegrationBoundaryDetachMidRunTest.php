<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (case 1) — a binding detached BETWEEN a job's start check and its write: the write must not land.
 *
 * `SyncAccountMetricsJob` (and the structure, store and GA4 jobs) re-prove the binding when they START. A provider call takes
 * seconds to minutes, so the customer can detach the account, switch syncing off or move it to another project while the call is
 * in flight — and a write guarded only by the start check lands a client's figures in a project they asked us to stop feeding.
 * The detach here happens INSIDE the faked provider response, which is the only honest way to place it between check and write.
 *
 * Existing neighbours, credited and not repeated: `SyncGateHardeningTest` and `SyncScopeReverificationTest` (detached BEFORE the
 * job runs), `OneOwnershipRuleTest::test_a_detached_account_writes_no_further_metrics`, `MetricsWriteFollowsActiveBindingTest`
 * (re-bound BEFORE the sync starts). Nothing before this file detached DURING one.
 */
final class IntegrationBoundaryDetachMidRunTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $account;

    /** Substring of the request that triggers the detach; null = never (the control). */
    private ?string $detachOn = null;

    private string $detachMode = 'unbound';

    private bool $detached = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');

        $this->tenant = $this->bwTenant();
        $workspace = $this->bwWorkspace($this->tenant);
        $this->projectA = $this->bwProject($this->tenant, $workspace, 'A');
        $this->projectB = $this->bwProject($this->tenant, $workspace, 'B');

        $connection = $this->bwConnection($this->tenant, 'meta');
        $this->account = $this->bwAccount($connection, 'act_1');
        $this->bwBind($this->account, $this->projectA);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
        $adSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'adset-1', 'name' => 'Set', 'status' => 'active',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_ad_set_id' => $adSet->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'external_id' => 'ad-1', 'name' => 'Ad', 'status' => 'active',
        ]);
        ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_account_id' => $this->account->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'external_creative_id' => 'cr-1', 'name' => 'Creative', 'format' => 'image',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function detachModes(): array
    {
        return [
            'the account is unlinked (binding inactive)' => ['unbound'],
            'syncing is switched off on the binding' => ['sync_off'],
            'the account is moved to another project' => ['moved'],
        ];
    }

    // ── Ad-account metrics ────────────────────────────────────────────────────────────────────

    public function test_control_an_undisturbed_sync_lands_every_grain_and_reads_as_success(): void
    {
        $this->fakeMeta();

        $this->runJob();

        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->where('project_id', $this->projectA->id)->count());
        $this->assertSame(2, DB::table('entity_daily_metrics')->count(), 'ad set + ad');
        $this->assertSame(1, DB::table('creative_daily_metrics')->count());
        $this->assertSame('success', $this->lastRun()->status instanceof \BackedEnum ? $this->lastRun()->status->value : (string) $this->lastRun()->status);
    }

    #[DataProvider('detachModes')]
    public function test_a_detach_during_the_campaign_read_stores_nothing_in_any_grain(string $mode): void
    {
        $this->detachOn = 'level=campaign';
        $this->detachMode = $mode;
        $this->fakeMeta();

        $this->runJob();

        $this->assertTrue($this->detached, 'the detach never fired — the test proved nothing');
        $this->assertSame(0, DB::table('daily_metrics')->count(), 'figures landed after the account was taken away from the project');
        $this->assertSame(0, DB::table('entity_daily_metrics')->count());
        $this->assertSame(0, DB::table('creative_daily_metrics')->count());
        $run = $this->lastRun();
        $this->assertSame('awaiting_assignment', $this->statusOf($run), 'a run that stored nothing because the binding went away must say so, not read as success or failure');
    }

    public function test_a_detach_after_the_campaign_figures_but_before_the_creative_figures_keeps_the_first_and_stops_the_rest(): void
    {
        $this->detachOn = 'creative{id}';
        $this->fakeMeta();

        $this->runJob();

        $this->assertTrue($this->detached);
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $this->projectA->id)->where('metric_key', 'spend')->count(), 'the campaign figures were written while the binding still held and are legitimate');
        $this->assertSame(0, DB::table('creative_daily_metrics')->count(), 'creative figures landed after the detach');
        $this->assertSame(0, DB::table('entity_daily_metrics')->count(), 'ad-set / ad figures landed after the detach');
        $this->assertNotSame('success', $this->statusOf($this->lastRun()), 'a run cut short by a detach must not read as success');
    }

    public function test_a_detach_during_the_ad_set_read_keeps_the_campaign_figures_and_writes_no_entity_rows_and_the_run_is_not_a_success(): void
    {
        $this->detachOn = 'level=adset';
        $this->fakeMeta();

        $this->runJob();

        $this->assertTrue($this->detached);
        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->count());
        $this->assertSame(0, DB::table('entity_daily_metrics')->count(), 'ad-set / ad rows landed after the detach');
        $this->assertNotSame('success', $this->statusOf($this->lastRun()));
    }

    // ── Structure ─────────────────────────────────────────────────────────────────────────────

    public function test_a_detach_during_the_structure_read_files_no_campaign_ad_set_or_ad(): void
    {
        $this->detachOn = '/campaigns';
        $this->fakeMetaStructure();

        $run = app(AccountStructureSyncer::class)->sync($this->account->refresh());

        $this->assertTrue($this->detached);
        $this->assertSame(0, ExternalCampaign::withoutGlobalScopes()->where('external_id', 'cmp-NEW')->count(), 'a campaign was filed into a project the account had left');
        $this->assertSame(0, ExternalAdSet::withoutGlobalScopes()->where('external_id', 'adset-NEW')->count());
        $this->assertSame(0, ExternalAd::withoutGlobalScopes()->where('external_id', 'ad-NEW')->count());
        $this->assertNotSame('success', (string) $run->status);
    }

    // ── Store ─────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('storeDetachPoints')]
    public function test_a_detach_during_a_store_read_files_no_order_and_no_product_after_it(string $on, int $productsExpected): void
    {
        $this->bwConfigure('salla', 'commerce_platforms');
        $connection = $this->bwConnection($this->tenant, 'salla');
        $store = $this->bwAccount($connection, 'store_1', 'store', ['currency' => 'SAR']);
        $this->bwBind($store, $this->projectA, 'ecommerce');
        $this->account = $store;
        $this->detachOn = $on;

        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->maybeDetach($r->url());
            $url = $r->url();
            if (str_contains($url, '/store/info')) {
                return Http::response(['data' => ['id' => 'store_1', 'name' => 'Ours']]);
            }
            if (str_contains($url, '/products')) {
                return Http::response(['data' => [['id' => 'p1', 'name' => 'A', 'price' => ['amount' => 10, 'currency' => 'SAR']]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
            }
            if (str_contains($url, '/orders')) {
                return Http::response(['data' => [[
                    'id' => 'o1', 'reference_id' => 1, 'status' => ['slug' => 'completed', 'name' => 'x'],
                    'date' => ['date' => '2026-08-01 10:00:00.000000', 'timezone' => 'Asia/Riyadh'],
                    'amounts' => ['total' => ['amount' => 50.0, 'currency' => 'SAR']],
                    'customer' => ['id' => 'c1', 'first_name' => 'a', 'last_name' => 'b', 'email' => 'a@b.sa'],
                ]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
            }

            return Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
        });

        $run = app(StoreSyncer::class)->sync($store->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertTrue($this->detached, 'the detach never fired');
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count(), 'an order was filed after the store was taken away from the project');
        $this->assertSame($productsExpected, CommerceProduct::withoutGlobalScopes()->count());
        $this->assertNotSame('success', (string) $run->status);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function storeDetachPoints(): array
    {
        return [
            'during the first (products) read' => ['/products', 0],
            'during the orders read, after products were legitimately filed' => ['/orders', 1],
        ];
    }

    // ── GA4 ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_detach_during_the_ga4_report_stores_no_measured_day(): void
    {
        $connection = $this->bwConnection($this->tenant, 'ga4');
        $property = $this->bwAccount($connection, '111', Ga4PropertyDiscovery::ACCOUNT_TYPE, ['currency' => null]);
        $this->bwBind($property, $this->projectA, 'analytics');
        $this->account = $property;
        $this->detachOn = 'analyticsdata.googleapis.com';

        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->maybeDetach($r->url());
            if (str_contains($r->url(), 'analyticsadmin')) {
                return Http::response(['displayName' => 'Acme', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR'], 200);
            }

            return Http::response([
                'metricHeaders' => [['name' => 'sessions']],
                'rows' => [['dimensionValues' => [['value' => Carbon::now('Asia/Riyadh')->format('Ymd')]], 'metricValues' => [['value' => '100']]]],
            ], 200);
        });

        try {
            app(Ga4PropertySync::class)->sync($property->refresh(), 2);
            $this->fail('a property detached mid-run must be refused like an unselected one');
        } catch (Ga4NotSelected) {
            // refused, as an unselected property is
        }

        $this->assertTrue($this->detached);
        $this->assertSame(0, MeasurementDailyMetric::withoutGlobalScopes()->count(), 'measured days landed after the property was taken away from the project');
        $this->assertSame('awaiting_assignment', (string) IntegrationSyncRun::withoutGlobalScopes()->where('type', 'measurement')->latest('started_at')->firstOrFail()->status);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, '2026-08-01', '2026-08-01');
    }

    private function lastRun(): MetricSyncRun
    {
        return MetricSyncRun::withoutGlobalScopes()->latest('started_at')->firstOrFail();
    }

    private function statusOf(MetricSyncRun|IntegrationSyncRun $run): string
    {
        return $run->status instanceof \BackedEnum ? (string) $run->status->value : (string) $run->status;
    }

    /** Fires the configured detach once, from INSIDE the provider call. */
    private function maybeDetach(string $url): void
    {
        if ($this->detachOn === null || $this->detached || ! str_contains(urldecode($url), $this->detachOn)) {
            return;
        }
        $this->detached = true;

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id);
        match ($this->detachMode) {
            'sync_off' => $bindings->update(['sync_enabled' => false]),
            'moved' => (function () use ($bindings): void {
                $bindings->update(['is_active' => false]);
                $this->bwBind($this->account, $this->projectB);
            })(),
            default => $bindings->update(['is_active' => false]),
        };
    }

    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->maybeDetach($request->url());
            $url = urldecode($request->url());
            $row = ['campaign_id' => 'cmp-1', 'date_start' => '2026-08-01', 'spend' => '1200', 'impressions' => '40000', 'clicks' => '800'];

            if (str_contains($url, 'creative{id}')) {
                return Http::response(['data' => [['id' => 'ad-1', 'creative' => ['id' => 'cr-1']]]], 200);
            }
            if (! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, 'level=adset')) {
                return Http::response(['data' => [[...$row, 'adset_id' => 'adset-1']]], 200);
            }
            if (str_contains($url, 'level=ad') && str_contains($url, 'fields=ad_id')) {
                return Http::response(['data' => [[...$row, 'ad_id' => 'ad-1', 'adset_id' => 'adset-1']]], 200);
            }
            if (str_contains($url, 'level=ad')) {
                return Http::response(['data' => [[...$row, 'ad_id' => 'ad-1', 'adset_id' => 'adset-1']]], 200);
            }

            return Http::response(['data' => [$row]], 200);
        });
    }

    private function fakeMetaStructure(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->maybeDetach($request->url());
            $url = urldecode($request->url());

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'cmp-NEW', 'name' => 'New', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']]]);
            }
            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [['id' => 'adset-NEW', 'campaign_id' => 'cmp-NEW', 'name' => 'New set', 'status' => 'ACTIVE']]]);
            }
            if (str_contains($url, '/ads')) {
                return Http::response(['data' => [['id' => 'ad-NEW', 'adset_id' => 'adset-NEW', 'campaign_id' => 'cmp-NEW', 'name' => 'New ad', 'status' => 'ACTIVE']]]);
            }

            return Http::response(['data' => []]);
        });
    }
}
