<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * RESULT-EVENTS-001 — the mapping is set per project, read by the client link, and absent means no Results.
 */
final class ResultEventsApiTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Project $project;

    private Report $report;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 're-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 're-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $tenant, Portal::Agency);
        $owner->assignRole($role);
        $this->actingAs($owner, 'sanctum');

        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'NASEL', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'name' => 'Riwaq', 'status' => 'active', 'objective' => 'leads']);
        foreach (['spend' => 20.0, 'impressions' => 1000.0, 'clicks' => 50.0, 'conversions' => 700.0] as $key => $value) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $this->campaign->id, 'provider' => 'tiktok', 'metric_key' => $key,
                'metric_date' => now()->subDay()->toDateString(), 'value' => $value,
            ]);
        }
        $this->report = Report::create([
            'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'name' => 'R', 'type' => 'live', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(), 'data' => [],
        ]);
    }

    private function results(array $settings = []): array
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live', 'settings' => $settings,
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => [(string) $this->campaign->id], 'providers' => ['tiktok'],
                'earliest' => now()->subDays(30)->toDateString(), 'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data.results');
    }

    public function test_an_unconfigured_project_has_no_results_even_with_700_provider_conversions(): void
    {
        $results = $this->results();

        $this->assertFalse($results['configured']);
        $this->assertNull($results['total']);
    }

    public function test_the_project_mapping_is_saved_validated_and_read_by_the_client_link(): void
    {
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['tiktok' => ['all' => 'garbage']]])->assertStatus(422);
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['myspace' => ['all' => 'leads']]])->assertStatus(422);
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['tiktok' => ['everywhere' => 'leads']]])->assertStatus(422);

        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['tiktok' => ['all' => 'conversions']]])->assertOk();
        $this->getJson("/api/v1/projects/{$this->project->id}/result-events")->assertOk()->assertJsonPath('data.result_events.tiktok.all', 'conversions');

        $results = $this->results();
        $this->assertTrue($results['configured']);
        $this->assertEquals(700, $results['total'], 'an explicit choice to call TikTok conversions Results is honoured');
    }

    public function test_a_link_override_replaces_the_projects_mapping_and_an_unreported_metric_is_not_zero(): void
    {
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['tiktok' => ['all' => 'conversions']]])->assertOk();

        $results = $this->results(['result_events' => ['tiktok' => ['all' => 'leads']]]);

        $this->assertTrue($results['configured']);
        $this->assertNull($results['total'], 'leads were never reported, so there is no result to state — not a zero and not the 700 conversions');
    }

    public function test_clearing_the_mapping_removes_results_again(): void
    {
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => ['tiktok' => ['all' => 'conversions']]])->assertOk();
        $this->putJson("/api/v1/projects/{$this->project->id}/result-events", ['result_events' => []])->assertOk();

        $this->assertFalse($this->results()['configured']);
    }
}
