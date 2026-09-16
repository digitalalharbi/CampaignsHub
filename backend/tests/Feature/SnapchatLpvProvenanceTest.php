<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The count that makes a historical re-sync decision concrete, held to what it promises: it writes
 * nothing, it splits the stored rows at the sweep's MEASURED reach, and it recognises the old
 * mapping's signature (`landing_page_views = page_views`, both filled from one pixel field).
 */
final class SnapchatLpvProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_splits_the_old_mapping_rows_at_the_sweeps_measured_reach_and_writes_nothing(): void
    {
        // The sweep re-fetches from 2026-09-09 — measured from the run, not assumed.
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'provider' => 'snapchat',
            'status' => 'success', 'window_start' => '2026-09-09', 'window_end' => '2026-09-16',
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);

        $this->entityRow('2026-08-20', lpv: 90, pageViews: 90);   // outside reach, old signature
        $this->entityRow('2026-08-21', lpv: 90, pageViews: 90);   // outside reach, old signature
        $this->entityRow('2026-09-12', lpv: 90, pageViews: 90);   // inside reach
        $this->entityRow('2026-08-22', lpv: 40, pageViews: 90);   // outside reach, delivery mapping
        $this->entityRow('2026-08-23', lpv: null, pageViews: 90); // no LPV at all — not counted

        $before = $this->digest();

        Artisan::call('content:lpv-provenance');
        $output = Artisan::output();

        $this->assertSame($before, $this->digest(), 'the provenance count changed a table it was only meant to read');

        $this->assertStringContainsString('earliest window start 2026-09-09', $output);
        $this->assertStringContainsString("outside the sweep's reach (before 2026-09-09): 3 row(s), 2 with the old mapping's signature", $output);
        $this->assertStringContainsString("inside the sweep's reach: 1 row(s)", $output);
        $this->assertMatchesRegularExpression('/ad\s+2026-08\s+3\s+2\s+2026-08-20\s+2026-08-22/', $output);
    }

    private function entityRow(string $date, ?int $lpv, ?int $pageViews): void
    {
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'entity_type' => 'ad',
            'entity_id' => (string) Str::uuid(),
            'external_entity_id' => 'ad-'.Str::random(6),
            'metric_date' => $date,
            'attribution_window' => 'default',
            'landing_page_views' => $lpv,
            'page_views' => $pageViews,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function digest(): string
    {
        $out = '';
        foreach (['entity_daily_metrics', 'metric_sync_runs'] as $table) {
            $row = DB::table($table)
                ->selectRaw("COUNT(*) AS n, MD5(COALESCE(string_agg(t::text, '' ORDER BY t::text), '')) AS d")
                ->fromRaw("\"{$table}\" AS t")
                ->first();
            $out .= $table.':'.$row->n.':'.$row->d.';';
        }

        return $out;
    }
}
