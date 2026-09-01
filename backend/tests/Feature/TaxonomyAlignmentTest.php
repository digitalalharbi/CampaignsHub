<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignOutcome;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Enums\Industry;
use App\Domains\ClientWorkspaces\Enums\ServiceLevel;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Taxonomy\Services\TaxonomyService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the Taxonomy engine is the TRUE source of truth: every enum-backed definition's option keys are a
 * superset of the live enum/validator values the API actually stores against (so the SPA can never submit a
 * value that 422s or blanks a filter), the Service→Category→Type tree is really parent-linked, and the new
 * additive multi-select columns exist and validate. Existing enum columns/validators are proven unchanged.
 */
final class TaxonomyAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private ClientWorkspace $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(TaxonomyEngineSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);

        $this->client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'Project A', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /**
     * The engine's active platform option keys for a definition.
     *
     * @return list<string>
     */
    private function engineKeys(string $definitionKey): array
    {
        $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where('key', $definitionKey)->whereNull('tenant_id')->firstOrFail();

        return TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->whereNull('tenant_id')->where('is_active', true)
            ->pluck('key')->all();
    }

    public function test_every_enum_backed_definition_is_a_superset_of_its_live_enum(): void
    {
        // definition key => the live enum/validator values it MUST contain (verbatim keys).
        $live = [
            'campaign.objective' => CampaignObjective::values(),
            'campaign.outcome' => CampaignOutcome::values(),
            'client.status' => ClientStatus::values(),
            'client.service_level' => ServiceLevel::values(),
            'client.industry' => Industry::values(),
            // RequestStatusMachine forward-map states (the live request state machine).
            'request.status' => ['new', 'under_review', 'waiting_client', 'qualified', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled', 'archived'],
            // Rule::in(...) validators.
            'request.priority' => ['critical', 'high', 'medium', 'low'],
            'client.priority' => ['low', 'normal', 'high'],
            'request.payment_status' => ['none', 'pending', 'paid', 'failed', 'refunded'],
            'project.status' => ['draft', 'onboarding', 'active', 'paused', 'completed', 'archived'],
        ];

        foreach ($live as $definitionKey => $values) {
            $engineKeys = $this->engineKeys($definitionKey);
            foreach ($values as $value) {
                $this->assertContains(
                    $value,
                    $engineKeys,
                    "Engine definition [{$definitionKey}] is missing live value [{$value}] → the SPA would 422 or blank a filter.",
                );
            }
        }
    }

    public function test_reports_and_alerts_definitions_are_supersets_of_live_controller_values(): void
    {
        // Track 7: definition key => the live controller const / Rule::in values it MUST contain verbatim, so the
        // report builder & alert rule form can never submit a value the API rejects (422).
        $live = [
            // ReportController::TYPES + audience Rule::in
            'report.type' => ['executive', 'project', 'campaign', 'platform', 'platform_comparison', 'weekly', 'monthly', 'custom'],
            'report.audience' => ['client', 'internal', 'executive'],
            // AlertController::TYPES + severity + channels Rule::in
            'alert.type' => ['budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results', 'sync_failure', 'token_expiry', 'report_failed', 'sla_warning', 'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue'],
            'alert.severity' => ['info', 'warning', 'critical'],
            'alert.channel' => ['in_app', 'email', 'whatsapp'],
        ];

        foreach ($live as $definitionKey => $values) {
            $engineKeys = $this->engineKeys($definitionKey);
            // These are system, closed enums — the engine set is EXACTLY the live values (no drift, no extras).
            sort($engineKeys);
            $sorted = $values;
            sort($sorted);
            $this->assertSame(
                $sorted,
                $engineKeys,
                "Engine definition [{$definitionKey}] active keys diverge from the live controller enum → the SPA would 422.",
            );
        }
    }

    public function test_enumerated_definitions_have_no_divergent_active_keys(): void
    {
        // The critical fix: these closed sets must be EXACTLY the live enum — no aspirational extras left active.
        $exact = [
            'campaign.objective' => CampaignObjective::values(),
            'campaign.outcome' => CampaignOutcome::values(),
            'client.industry' => Industry::values(),
            'client.service_level' => ServiceLevel::values(),
            'client.priority' => ['low', 'normal', 'high'],
            'request.status' => ['new', 'under_review', 'waiting_client', 'qualified', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled', 'archived'],
        ];

        foreach ($exact as $definitionKey => $values) {
            $engineKeys = $this->engineKeys($definitionKey);
            sort($engineKeys);
            sort($values);
            $this->assertSame($values, $engineKeys, "Engine definition [{$definitionKey}] active keys diverge from the live enum.");
        }
    }

    public function test_request_tree_is_parent_linked(): void
    {
        $serviceIds = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->whereIn('taxonomy_definition_id', TaxonomyDefinition::withoutGlobalScope(TenantScope::class)->where('key', 'request.service')->pluck('id'))
            ->whereNull('tenant_id')->pluck('id')->all();
        $categoryOptions = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->whereIn('taxonomy_definition_id', TaxonomyDefinition::withoutGlobalScope(TenantScope::class)->where('key', 'request.category')->pluck('id'))
            ->whereNull('tenant_id')->get();
        $categoryIds = $categoryOptions->pluck('id')->all();
        $typeOptions = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->whereIn('taxonomy_definition_id', TaxonomyDefinition::withoutGlobalScope(TenantScope::class)->where('key', 'request.type')->pluck('id'))
            ->whereNull('tenant_id')->get();

        $this->assertNotEmpty($serviceIds);
        $this->assertNotEmpty($categoryOptions);
        // Every category is linked to a real service option.
        foreach ($categoryOptions as $category) {
            $this->assertNotNull($category->parent_option_id, "category [{$category->key}] has no service parent");
            $this->assertContains($category->parent_option_id, $serviceIds, "category [{$category->key}] parent is not a service option");
        }
        // request.type is non-empty and every type is linked to a real category option.
        $this->assertNotEmpty($typeOptions, 'request.type must have options');
        foreach ($typeOptions as $type) {
            $this->assertNotNull($type->parent_option_id, "type [{$type->key}] has no category parent");
            $this->assertContains($type->parent_option_id, $categoryIds, "type [{$type->key}] parent is not a category option");
        }
    }

    public function test_options_endpoint_exposes_dependent_categories_with_parent_key_and_metadata(): void
    {
        TaxonomyService::flushRegistrations();
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/taxonomy.php'));

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/taxonomies/request.category/options')
            ->assertOk()
            ->assertJsonPath('success', true)
            // Dependent categories are returned (not swallowed by the tree) and carry their parent linkage.
            ->assertJsonPath('data.0.parent_option_id', fn ($v) => $v !== null)
            ->assertJsonPath('data.0.parent_key', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_paid_service_catalog_is_seeded_and_tenant_manageable(): void
    {
        $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where('key', 'request.paid_service')->whereNull('tenant_id')->firstOrFail();

        // Hierarchical, multi-select, tenant-manageable (non-system + custom options allowed).
        $this->assertFalse($definition->is_system);
        $this->assertTrue($definition->allows_custom_options);
        $this->assertSame('multi', $definition->field_type);

        $options = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->whereNull('tenant_id')->where('is_active', true)->get();

        // 10 categories (roots) + ~90 services (children), all published (is_public).
        $this->assertSame(10, $options->whereNull('parent_option_id')->count());
        $this->assertGreaterThanOrEqual(90, $options->whereNotNull('parent_option_id')->count());
        $this->assertSame($options->count(), $options->where('is_public', true)->count());
    }

    public function test_new_multiselect_columns_exist(): void
    {
        foreach (['platforms', 'audiences', 'conversion_events', 'creative_types', 'tags'] as $column) {
            $this->assertTrue(Schema::hasColumn('unified_campaigns', $column), "unified_campaigns.{$column} missing");
        }
        foreach (['tags', 'enabled_services'] as $column) {
            $this->assertTrue(Schema::hasColumn('client_workspaces', $column), "client_workspaces.{$column} missing");
        }
    }

    public function test_campaign_accepts_taxonomy_multiselect_arrays(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns", [
                'name' => 'Q4 Launch', 'objective' => 'sales',
                'platforms' => ['meta', 'google'],
                'creative_types' => ['video', 'image'],
                'regions' => ['riyadh'],
                'audiences' => ['lookalike-1'],
                'conversion_events' => ['purchase'],
                'tags' => ['q4', 'priority'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('unified_campaigns', ['id' => $id, 'name' => 'Q4 Launch']);
        /** @var UnifiedCampaign $campaign */
        $campaign = UnifiedCampaign::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame(['meta', 'google'], $campaign->platforms);
        $this->assertSame(['video', 'image'], $campaign->creative_types);
        $this->assertSame(['q4', 'priority'], $campaign->tags);
    }

    public function test_campaign_rejects_unknown_platform_and_creative_type_keys(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns", [
                'name' => 'Bad Platform', 'platforms' => ['not_a_platform'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('platforms.0');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns", [
                'name' => 'Bad Creative', 'creative_types' => ['hologram'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('creative_types.0');
    }

    public function test_existing_campaign_enum_validator_is_unchanged(): void
    {
        // The objective enum column/validator is untouched: a non-enum objective is still rejected.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns", [
                'name' => 'Bad Objective', 'objective' => 'not_a_real_objective',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('objective');
    }

    public function test_client_classification_accepts_tags_and_enabled_services(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/app/clients/{$this->client->id}/classification", [
                'industry' => 'e_commerce', // live enum key, unchanged
                'tags' => ['vip', 'retainer'],
                'enabled_services' => ['paid_advertising_management', 'analytics'],
            ])
            ->assertOk();

        /** @var ClientWorkspace $fresh */
        $fresh = ClientWorkspace::withoutGlobalScopes()->findOrFail($this->client->id);
        $this->assertSame(['vip', 'retainer'], $fresh->tags);
        $this->assertSame(['paid_advertising_management', 'analytics'], $fresh->enabled_services);
        $this->assertSame('e_commerce', $fresh->industry);
    }
}
