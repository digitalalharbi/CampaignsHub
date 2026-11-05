<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 — the stored figures of a scripted sync, as a digest taken BEFORE the engine was touched.
 *
 * The digests below were recorded on `origin/test/integration-boundaries` (38a4a1f56), with no checkpoint, budget or dead-letter
 * code present. The engine work around the write must not move one byte of what is written: every column except the generated
 * ids and timestamps is hashed, across the three tables a metrics run writes, for an account with a currency (figures landed) and
 * one without (money withheld, MONEY-CURRENCY-NEVER-ASSUMED-001).
 */
final class SyncFiguresCharacterisationTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    /** Recorded on the unchanged base. Changing either is a change to stored figures, not a test update. */
    private const LANDED_DIGEST = 'c99bc5334f5ffaaeafb52ce7ab9f33c53687e572337ebba5daa5b8fb0792c8df';

    private const WITHHELD_DIGEST = '7bb39b43cff2d692a2520481692393ba4654db22b34f278f16352043c7742e7b';

    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Sleep::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_figures_a_sync_stores_are_byte_identical_to_the_recording(): void
    {
        $this->world('act_1', 'SAR');
        SyncAccountMetricsJob::dispatchSync((string) ExternalAccount::withoutGlobalScopes()->where('external_id', 'act_1')->value('id'), '2026-08-01', '2026-08-02');

        $this->assertSame(['success'], DB::table('metric_sync_runs')->pluck('status')->all());
        $this->assertSame(self::LANDED_DIGEST, $this->digest());
    }

    public function test_the_figures_of_an_account_with_no_currency_stay_withheld_byte_for_byte(): void
    {
        $this->world('act_1', null);
        SyncAccountMetricsJob::dispatchSync((string) ExternalAccount::withoutGlobalScopes()->where('external_id', 'act_1')->value('id'), '2026-08-01', '2026-08-02');

        $this->assertSame(['partial_mapping'], DB::table('metric_sync_runs')->pluck('status')->all());
        $this->assertStringContainsString('currency', (string) DB::table('metric_sync_runs')->value('error'));
        $this->assertSame(self::WITHHELD_DIGEST, $this->digest());
    }

    private function world(string $externalId, ?string $currency): void
    {
        $tenant = $this->bwTenant();
        $project = $this->bwProject($tenant, $this->bwWorkspace($tenant));
        $connection = $this->bwConnection($tenant, 'meta');
        $account = $this->bwAccount($connection, $externalId);
        $account->forceFill(['currency' => $currency])->save();
        $this->bwBind($account, $project);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
        $adSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'adset-1', 'name' => 'Set', 'status' => 'active',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_ad_set_id' => $adSet->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'external_id' => 'ad-1', 'name' => 'Ad', 'status' => 'active',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            if (str_contains($url, '/insights')) {
                $grain = str_contains($url, 'level=adset') ? ['adset_id' => 'adset-1'] : (str_contains($url, 'level=ad') ? ['ad_id' => 'ad-1', 'adset_id' => 'adset-1'] : []);

                return Http::response(['data' => [
                    ['campaign_id' => 'cmp-1', 'date_start' => '2026-08-01', 'spend' => '1200.50', 'impressions' => '40000', 'clicks' => '800', 'reach' => '30000', ...$grain],
                    ['campaign_id' => 'cmp-1', 'date_start' => '2026-08-02', 'spend' => '333.33', 'impressions' => '9000', 'clicks' => '120', 'reach' => '7000', ...$grain],
                ]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    private function digest(): string
    {
        $out = [];
        foreach (['daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics'] as $table) {
            $rows = DB::table($table)->get()->map(function ($row): array {
                $row = (array) $row;
                ksort($row);

                return Arr::except($row, array_values(array_filter(array_keys($row), static fn (string $column): bool => $column === 'id'
                    || str_ends_with($column, '_id') || in_array($column, ['created_at', 'updated_at', 'synced_at', 'fetched_at'], true))));
            })->map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            $out[$table] = $rows;
        }

        return hash('sha256', json_encode($out, JSON_THROW_ON_ERROR));
    }
}
