<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Reports\Models\ReportShare;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAnalyticsSeeder;
use Database\Seeders\DemoReportsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deny by default holds for the demo world too, so the demo world selects its accounts. It did not: every metric, campaign and order the
 * seeders write named an account with no binding (the store project's metric rows named one with no account row at all), and the day
 * `UNBOUND_ROWS_VISIBLE` went false the whole seeded world read «no data» on 130 browser tests. PHPUnit never saw it, because
 * `SeededAccountBinder` selects accounts for a test world — which is why this one opts out and seeds the way the gate does.
 */
final class DemoWorldSelectsItsAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();

        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
    }

    public function test_every_seeded_row_that_names_an_account_is_read_through_an_active_binding(): void
    {
        foreach (['daily_metrics', 'external_campaigns', 'metric_sync_runs', 'commerce_orders', 'commerce_products', 'commerce_customers', 'commerce_abandoned_carts'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} is seeded");
            $this->assertSame(0, $this->unselected($table, "{$table}.external_account_id"), "{$table} holds rows of an account nobody selected");
        }

        $this->assertGreaterThan(0, DB::table('entity_daily_metrics')->count());
        $this->assertSame(0, $this->unselected('entity_daily_metrics', 'COALESCE(entity_daily_metrics.external_account_id, (select c.external_account_id from external_campaigns c where c.id = entity_daily_metrics.external_campaign_id))'));
    }

    public function test_a_metric_row_names_an_account_that_exists(): void
    {
        $this->assertSame(0, DB::table('daily_metrics')->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('external_accounts as a')->whereColumn('a.id', 'daily_metrics.external_account_id'),
        )->count());
    }

    public function test_the_store_project_has_one_account_per_platform_not_a_second_identity_for_its_figures(): void
    {
        $project = DB::table('projects')->where('name', DemoAnalyticsSeeder::STORE_PROJECT)->value('id');
        $perProvider = DB::table('daily_metrics')->where('project_id', $project)
            ->groupBy('provider')->selectRaw('provider, count(distinct external_account_id) as accounts')->pluck('accounts', 'provider');

        $this->assertNotEmpty($perProvider);
        $this->assertSame([1], $perProvider->map(fn ($n) => (int) $n)->unique()->values()->all());
    }

    /** The live link's platforms ceiling is read from a report generated at seed time: generated before the selection, it says nothing. */
    public function test_a_report_generated_by_the_seed_carries_figures_and_its_live_link_names_the_platforms(): void
    {
        $share = ReportShare::withoutGlobalScopes()->where('token_hash', hash('sha256', DemoReportsSeeder::DEMO_LIVE_TOKEN))->firstOrFail();

        $this->assertNotEmpty($share->scope['providers'] ?? []);
    }

    public function test_no_sweep_ever_fetches_a_demo_account(): void
    {
        $demo = ExternalAccount::withoutGlobalScopes()->whereRaw("(metadata->>'is_demo') = 'true'");

        $this->assertGreaterThan(0, (clone $demo)->count());
        $this->assertSame(0, app(AccountAssignment::class)->scopeToAssigned($demo)->count());
    }

    private function unselected(string $table, string $accountExpression): int
    {
        return DB::table($table)->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('project_integration_bindings as b')
                ->whereRaw("b.external_account_id = {$accountExpression}")
                ->whereColumn('b.project_id', "{$table}.project_id")
                ->where('b.is_active', true),
        )->count();
    }
}
