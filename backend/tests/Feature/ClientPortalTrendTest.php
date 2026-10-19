<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * CP-DASH-001 — the client's delivery curve: a point per day that HAS data, delivery metrics only,
 * and never spend, whatever the daily series carries underneath.
 */
final class ClientPortalTrendTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    public function test_the_overview_carries_a_delivery_curve_with_gaps_left_as_gaps_and_no_money(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->seed(RequestCatalogSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Alpha', 'status' => 'active']);

        $row = static fn (string $key, float $v, string $date) => new NormalizedMetric(
            tenantId: (string) $tenant->id, projectId: (string) $project->id, provider: 'meta',
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'a')->toString(), externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'c')->toString(),
            metricKey: $key, metricDate: Carbon::parse($date), value: $v,
        );
        // Two days with data and a day with none between them.
        app(UpsertDailyMetrics::class)->handle([
            $row('impressions', 1000, '2026-10-10'), $row('clicks', 50, '2026-10-10'), $row('conversions', 5, '2026-10-10'), $row('spend', 100, '2026-10-10'),
            $row('impressions', 3000, '2026-10-12'), $row('clicks', 90, '2026-10-12'), $row('conversions', 9, '2026-10-12'), $row('spend', 150, '2026-10-12'),
        ]);

        $user = User::create(['name' => 'Client', 'email' => 'c@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::ClientPortal, role: 'client_admin', clientScopeIds: [(string) $ws->id]));
        $this->actingAs($user);

        $res = $this->getJson('/api/v1/client/overview?from=2026-10-01&to=2026-10-17', $this->spa)->assertOk();
        $trend = $res->json('data.trend');

        $this->assertSame(['2026-10-10', '2026-10-12'], array_column($trend, 'date'), 'the empty 11th is absent, not a zero');
        $this->assertEquals(9, $trend[1]['conversions']);
        $this->assertSame(['date', 'impressions', 'clicks', 'conversions'], array_keys($trend[0]), 'delivery only: money never rides in the curve');
        $this->assertStringNotContainsString('spend', json_encode($trend));
        Carbon::setTestNow();
    }
}
