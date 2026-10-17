<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * EMAIL-CANONICAL-METRICS-001 — an email states the same figures as the web for the same window.
 *
 * The digest is composed by its own service, and a composer that re-derived a figure (a sum, a ratio,
 * a "not reported" rule) could drift from the engine the dashboard reads without anything failing.
 * This pins the figures the email prints to the aggregator's, for one project and one window.
 */
final class EmailWebMetricParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_digest_prints_the_figures_the_web_engine_computes_for_the_same_window(): void
    {
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-parity', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-parity', 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'Alpha', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-parity']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Reader', 'email' => 'reader@agency.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'portal' => 'agency', 'status' => 'active']);
        $user->assignRole($role);

        $day = Carbon::today()->subDay();
        $row = static fn (string $key, float $value, string $camp) => new NormalizedMetric(
            tenantId: (string) $tenant->id, projectId: (string) $project->id, provider: 'meta',
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'acct')->toString(),
            externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, $camp)->toString(),
            metricKey: $key, metricDate: $day, value: $value,
        );
        app(UpsertDailyMetrics::class)->handle([
            $row('spend', 500.0, 'c1'), $row('impressions', 20000.0, 'c1'), $row('clicks', 400.0, 'c1'), $row('conversions', 25.0, 'c1'),
            // A second campaign: the digest and the web must add the same rows, not different subsets.
            $row('spend', 250.0, 'c2'), $row('conversions', 5.0, 'c2'), $row('impressions', 10000.0, 'c2'), $row('clicks', 100.0, 'c2'),
        ]);

        $web = app(MetricsAggregator::class)->forProjects([(string) $project->id])
            ->totals($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $digest = app(DailyDigest::class)->build($user, (string) $tenant->id, [(string) $project->id], $day);
        $email = $digest['projects'][0]['totals'];

        foreach (['spend', 'impressions', 'clicks', 'conversions'] as $key) {
            $this->assertNotNull($web[$key] ?? null, "the web engine returned no {$key}");
            $this->assertEquals($web[$key], $email[$key] ?? null, "the email's {$key} differs from the web's for the same window");
        }
        $this->assertEquals(750.0, $email['spend']);
    }
}
