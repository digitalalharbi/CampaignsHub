<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Command-center per-campaign metrics: each campaign's summary returns ONLY its own numbers, and a
 * cross-project / unknown campaign id fails closed (404). This is the isolation the whole page rests on.
 */
final class CampaignMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    private UnifiedCampaign $campA1;

    private UnifiedCampaign $campA2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);

        $this->campA1 = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'A1', 'objective' => 'sales', 'status' => 'active']);
        $this->campA2 = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'A2', 'objective' => 'sales', 'status' => 'active']);

        // Distinct metrics per campaign so a leak is unmistakable.
        $this->seedMetrics($this->campA1->id, spend: 1000, conv: 10, rev: 4000);
        $this->seedMetrics($this->campA2->id, spend: 500, conv: 4, rev: 1500);
    }

    private function seedMetrics(string $campaignId, float $spend, float $conv, float $rev): void
    {
        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s.$campaignId);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->tenant->id, projectId: $this->projectA->id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: 'meta', metricKey: $k, metricDate: Carbon::parse('2026-06-15'),
            value: $v, unifiedCampaignId: $campaignId,
        );
        app(UpsertDailyMetrics::class)->handle([
            $m('impressions', 10000), $m('clicks', 200), $m('conversions', $conv), $m('spend', $spend), $m('revenue', $rev),
        ]);
    }

    private function url(Project $p, string $campaignId, string $section = 'summary'): string
    {
        return "/api/v1/projects/{$p->id}/campaigns/{$campaignId}/{$section}?from=2026-06-01&to=2026-06-30";
    }

    public function test_each_campaign_summary_is_scoped_to_itself(): void
    {
        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id))->assertOk()->json('data.current');
        $a2 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA2->id))->assertOk()->json('data.current');

        $this->assertEqualsWithDelta(1000, $a1['spend'], 0.01);
        $this->assertEqualsWithDelta(10, $a1['conversions'], 0.01);
        $this->assertEqualsWithDelta(500, $a2['spend'], 0.01);
        $this->assertEqualsWithDelta(4, $a2['conversions'], 0.01);
        // ROAS is derived per campaign, not shared.
        $this->assertEqualsWithDelta(4.0, $a1['roas'], 0.01);
        $this->assertEqualsWithDelta(3.0, $a2['roas'], 0.01);
    }

    public function test_cross_project_campaign_id_fails_closed(): void
    {
        // campA1 belongs to project A; requesting it under project B must 404 (project scope).
        $this->actingAs($this->owner)
            ->getJson($this->url($this->projectB, $this->campA1->id))
            ->assertNotFound();

        // Unknown id → 404.
        $this->actingAs($this->owner)
            ->getJson($this->url($this->projectA, (string) Uuid::uuid4()))
            ->assertNotFound();
    }

    public function test_requires_view_permission(): void
    {
        $stranger = User::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => 's@a.test', 'password' => 'secret123']);
        $this->actingAs($stranger)
            ->getJson($this->url($this->projectA, $this->campA1->id))
            ->assertForbidden();
    }

    public function test_activity_timeline_is_scoped_to_the_campaign(): void
    {
        AuditLog::create([
            'tenant_id' => $this->tenant->id, 'action' => 'campaign.updated',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA1->id,
            'before' => ['total_budget' => 100], 'after' => ['total_budget' => 200],
        ]);
        AuditLog::create([
            'tenant_id' => $this->tenant->id, 'action' => 'campaign.paused',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA2->id,
        ]);

        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'activity'))->assertOk()->json('data');
        $this->assertCount(1, $a1);
        $this->assertSame('campaign.updated', $a1[0]['action']);

        // Cross-project campaign id → 404 (never another project's timeline).
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'activity'))->assertNotFound();
    }

    public function test_alerts_are_scoped_to_the_campaign(): void
    {
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'user_id' => $this->owner->id,
            'type' => 'alert', 'severity' => 'critical', 'title' => 'CPA high',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA1->id, 'status' => 'unread',
        ]);
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'user_id' => $this->owner->id,
            'type' => 'alert', 'severity' => 'info', 'title' => 'Other',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA2->id, 'status' => 'unread',
        ]);

        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'alerts'))->assertOk()->json('data');
        $this->assertCount(1, $a1);
        $this->assertSame('CPA high', $a1[0]['title']);
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'alerts'))->assertNotFound();
    }

    public function test_reports_are_scoped_to_the_campaign(): void
    {
        Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'campaign_id' => (string) $this->campA1->id,
            'name' => 'Campaign monthly', 'type' => 'monthly', 'status' => 'completed', 'currency' => 'SAR', 'audience' => 'client',
        ]);
        Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'campaign_id' => (string) $this->campA2->id,
            'name' => 'Other campaign', 'type' => 'monthly', 'status' => 'completed', 'currency' => 'SAR', 'audience' => 'client',
        ]);

        $r1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'reports'))->assertOk()->json('data');
        $this->assertCount(1, $r1);
        $this->assertSame('Campaign monthly', $r1[0]['name']);
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'reports'))->assertNotFound();
    }

    public function test_annotation_create_and_approval_workflow(): void
    {
        $base = "/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/annotations";

        // Create a recommendation (starts as draft).
        $id = $this->actingAs($this->owner)->postJson($base, [
            'kind' => 'recommendation', 'title' => 'Scale Google', 'evidence' => 'ROAS 8.4x vs 4.9x', 'priority' => 'high',
        ])->assertCreated()->json('data.id');
        $this->assertSame('draft', CampaignAnnotation::find($id)->status);

        // Approve it (owner has reports.approve).
        $this->actingAs($this->owner)->patchJson("{$base}/{$id}", ['status' => 'approved'])->assertOk();
        $this->assertSame('approved', CampaignAnnotation::find($id)->status);

        // A user without reports.approve cannot change status.
        $editor = User::create(['tenant_id' => $this->tenant->id, 'name' => 'E', 'email' => 'e@a.test', 'password' => 'secret123']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ed', 'slug' => 'ed']);
        $role->givePermissionTo(...Permission::whereIn('key', ['campaigns.view', 'campaigns.update'])->pluck('key')->all());
        $editor->assignRole($role);
        $this->actingAs($editor)->patchJson("{$base}/{$id}", ['status' => 'rejected'])->assertForbidden();

        // Listing is campaign-scoped.
        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->projectB->id}/campaigns/{$this->campA1->id}/annotations")->assertNotFound();
    }
}
