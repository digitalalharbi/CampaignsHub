<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\DemoCreativesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DEMO-RESEED-001 — `db:seed` twice on the same database must not blow up.
 *
 * ## The defect, and why it is a primary key rather than a delete
 *
 * `DemoCreativesSeeder` matched an existing creative on
 * `(project_id, provider, external_creative_id)` and then passed **`id` in the update payload**:
 *
 * ```php
 * ExternalCreative::updateOrCreate([...match...], ['id' => (string) Str::uuid(), ...]);
 * ```
 *
 * On a first run that is harmless — the row is created and the key is the one it was given. On a
 * SECOND run the row already exists, so `updateOrCreate` UPDATES it, and the update re-keys a row
 * whose children are still pointing at the old key. Postgres refuses, correctly:
 *
 * ```
 * SQLSTATE[23503]: update or delete on table "external_creatives"
 * violates foreign key constraint "creative_daily_metrics_creative_id_foreign"
 * ```
 *
 * The foreign key is not the problem — it is the thing that noticed. Re-keying a row that other
 * rows reference is the problem, and it would have silently orphaned thirty days of metrics per
 * creative if the constraint had not been there.
 *
 * `ExternalCreative` uses `HasUuidKey`, which mints a UUID in a `creating` hook, so the explicit
 * `id` was never needed for creation either.
 *
 * ## Why `migrate:fresh --seed` never caught it
 *
 * That path starts from an empty schema, so every creative is a CREATE and the update branch is
 * never taken. Every test, the gate and the installation guide use it. The one command nobody
 * runs in CI — `db:seed` on a database that already has a demo world — was the only way to meet it.
 */
final class DemoCreativesReseedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Demo', 'slug' => 'reseed-demo', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'name' => 'Client', 'slug' => 'reseed-client', 'mode' => 'managed', 'default_currency' => 'SAR',
        ]);
        $this->project = Project::create([
            'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /**
     * A campaign the seeder will find — it looks for campaigns that have daily metrics.
     *
     * Written through the query builder rather than a factory so this fixture states exactly the
     * two facts the seeder's own lookup depends on, and nothing else can drift underneath it.
     */
    private function campaignWithMetrics(): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Demo campaign',
            'status' => 'active',
            'objective' => 'sales',
            'total_budget' => 1000,
            'budget_currency' => 'SAR',
        ]);

        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $this->tenant->id,
            'project_id' => (string) $this->project->id,
            'unified_campaign_id' => (string) $campaign->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'provider' => 'sandbox',
            'metric_date' => now()->toDateString(),
            'metric_key' => 'spend',
            'value' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $campaign;
    }

    /**
     * Run it the way `php artisan db:seed` runs it — **unguarded**.
     *
     * This is not decoration, it is the whole reproduction. `SeedCommand::handle()` wraps the seeder
     * in `Model::unguarded()`, and that is what let a non-fillable `id` through `fill()` and onto an
     * UPDATE. Called plainly the guard silently drops it and the defect cannot be reached, which is
     * exactly how a first attempt at this test passed against the broken seeder.
     *
     * Named `reseed` because `seed` is already a public method on Laravel's own TestCase.
     */
    private function reseed(): void
    {
        Model::unguarded(fn () => (new DemoCreativesSeeder)->run());
    }

    /**
     * **The defect, pinned.** Running the seeder a second time used to abort on the foreign key.
     *
     * Written as «it completes» rather than «it does not throw» because the failure was an
     * exception that took the whole `db:seed` command down with it — an operator re-seeding a demo
     * database met a stack trace, not a warning.
     */
    public function test_seeding_twice_on_the_same_database_completes(): void
    {
        $this->campaignWithMetrics();

        $this->reseed();
        $this->reseed();

        $this->assertSame(4, ExternalCreative::withoutGlobalScopes()->count(), 'the second run must not add a second set');
    }

    /**
     * A creative keeps its identity across a re-seed — which is the actual fix.
     *
     * Its id is referenced by `creative_daily_metrics`, by report snapshots and by anything a person
     * has bookmarked. A seeder that re-keys it has not «refreshed the demo data», it has replaced
     * one shop's creatives with strangers wearing their names.
     */
    public function test_a_creative_keeps_its_primary_key_across_a_reseed(): void
    {
        $this->campaignWithMetrics();

        $this->reseed();
        $before = ExternalCreative::withoutGlobalScopes()->orderBy('external_creative_id')->pluck('id', 'external_creative_id');

        $this->reseed();
        $after = ExternalCreative::withoutGlobalScopes()->orderBy('external_creative_id')->pluck('id', 'external_creative_id');

        $this->assertSame($before->all(), $after->all(), 'a re-seed must not mint new keys for existing creatives');
    }

    /**
     * And its metrics are still ITS metrics — one window's worth, attached, not orphaned.
     *
     * The FK is what stopped the re-key, so without it the rows would have survived pointing at a
     * key nothing owned any more: present in the table, invisible to every query that joins.
     */
    public function test_the_daily_metrics_stay_attached_and_are_not_duplicated(): void
    {
        $this->campaignWithMetrics();

        $this->reseed();
        $first = DB::table('creative_daily_metrics')->count();

        $this->reseed();

        $this->assertSame($first, DB::table('creative_daily_metrics')->count(), 'a re-seed must not double the window');
        $this->assertSame(
            0,
            DB::table('creative_daily_metrics')
                ->whereNotIn('creative_id', ExternalCreative::withoutGlobalScopes()->pluck('id'))
                ->count(),
            'no metric row may reference a creative that no longer exists',
        );
    }

    /** Every row still belongs to the tenant and project it was seeded for. */
    public function test_a_reseed_does_not_leak_across_the_tenant_or_project(): void
    {
        $campaign = $this->campaignWithMetrics();

        $this->reseed();
        $this->reseed();

        foreach (['external_creatives', 'creative_daily_metrics'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)
                    ->where(fn ($q) => $q->where('tenant_id', '!=', (string) $campaign->tenant_id)
                        ->orWhere('project_id', '!=', (string) $campaign->project_id))
                    ->count(),
                "{$table} must stay inside its own tenant and project",
            );
        }
    }
}
