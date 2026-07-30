<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Taxonomy\Exceptions\TaxonomyException;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Taxonomy\Services\TaxonomyService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The central Taxonomy & Option engine: canonical seed, platform ∪ tenant effective sets with strict tenant
 * isolation, system-key immutability, and delete protection (merge / reassign / deactivate). Additive — the
 * legacy request catalog enums are proven untouched.
 */
final class TaxonomyEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(TaxonomyEngineSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->context()->setTenantId($this->tenant->id);

        TaxonomyService::flushRegistrations();

        // Load the (intentionally unwired) taxonomy routes for HTTP assertions, exactly as the orchestrator will.
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/taxonomy.php'));
    }

    protected function tearDown(): void
    {
        TaxonomyService::flushRegistrations();
        parent::tearDown();
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function service(): TaxonomyService
    {
        return app(TaxonomyService::class);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R'.uniqid(), 'slug' => 'r-'.uniqid()]);
        if ($permissions !== []) {
            $role->givePermissionTo(...$permissions);
        }
        $user = User::create([
            'name' => 'U', 'email' => 'u'.uniqid().'@t.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    private function definition(string $key): TaxonomyDefinition
    {
        /** @var TaxonomyDefinition $d */
        $d = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where('key', $key)->whereNull('tenant_id')->firstOrFail();

        return $d;
    }

    private function platformOption(string $definitionKey, string $optionKey): TaxonomyOption
    {
        /** @var TaxonomyOption $o */
        $o = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $this->definition($definitionKey)->id)
            ->whereNull('tenant_id')->where('key', $optionKey)->firstOrFail();

        return $o;
    }

    public function test_seed_creates_definitions_and_canonical_options(): void
    {
        // Every matrix definition is present as a platform (tenant_id null) row.
        foreach (['request.service', 'request.status', 'request.payment_status', 'client.status', 'client.industry', 'campaign.objective', 'campaign.platforms', 'integration.category', 'project.status'] as $key) {
            $this->assertNotNull($this->definition($key), "missing definition {$key}");
        }

        // request.status keys are EXACTLY the live state machine (RequestStatusMachine) — 10 states.
        $statuses = $this->service()->options('request.status');
        $this->assertContains('under_review', $statuses->pluck('key')->all());
        $this->assertContains('waiting_client', $statuses->pluck('key')->all());
        $this->assertCount(10, $statuses);

        // campaign.objective carries dependent config in metadata.
        $sales = $this->platformOption('campaign.objective', 'sales');
        $this->assertSame(['roas', 'cpa', 'revenue'], $sales->metadata['kpi']);
        $this->assertSame('conversion', $sales->metadata['funnel']);
        $this->assertSame('performance', $sales->metadata['template']);

        // System definitions do not accept custom options; their options are system-locked.
        $this->assertFalse($this->definition('request.status')->allows_custom_options);
        $this->assertTrue($this->platformOption('request.status', 'new')->is_system);
        // Non-system definitions accept custom options.
        $this->assertTrue($this->definition('client.industry')->allows_custom_options);
    }

    public function test_seed_is_idempotent(): void
    {
        $definitions = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)->count();
        $options = TaxonomyOption::withoutGlobalScope(TenantScope::class)->count();

        $this->seed(TaxonomyEngineSeeder::class);

        $this->assertSame($definitions, TaxonomyDefinition::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($options, TaxonomyOption::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_options_returns_platform_union_tenant(): void
    {
        $custom = $this->service()->createOption('client.industry', [
            'key' => 'gaming', 'label_ar' => 'الألعاب', 'label_en' => 'Gaming',
        ]);

        $keys = $this->service()->options('client.industry')->pluck('key')->all();

        $this->assertContains('e_commerce', $keys); // platform
        $this->assertContains('gaming', $keys);     // tenant
        $this->assertSame($this->tenant->id, $custom->tenant_id);
    }

    public function test_a_tenant_cannot_see_another_tenants_custom_option(): void
    {
        // Tenant B adds a private option.
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        $this->context()->setTenantId($tenantB->id);
        $this->service()->createOption('client.industry', ['key' => 'bsecret', 'label_ar' => 'سري', 'label_en' => 'Secret']);

        // Tenant A must never see it.
        $this->context()->setTenantId($this->tenant->id);
        $keys = $this->service()->options('client.industry')->pluck('key')->all();
        $this->assertNotContains('bsecret', $keys);
        $this->assertContains('e_commerce', $keys); // but still sees platform options
    }

    public function test_create_option_respects_allows_custom_options(): void
    {
        $this->expectException(TaxonomyException::class);
        // request.status is a system definition — custom options are refused.
        $this->service()->createOption('request.status', ['key' => 'my_status', 'label_ar' => 'حالة', 'label_en' => 'My status']);
    }

    public function test_create_option_requires_permission(): void
    {
        $viewer = $this->userWith(['taxonomies.view']);
        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/taxonomies/client.industry/options', ['key' => 'x', 'label_ar' => 'x', 'label_en' => 'x'])
            ->assertForbidden();

        $editor = $this->userWith(['taxonomies.view', 'options.create']);
        $this->actingAs($editor, 'sanctum')
            ->postJson('/api/v1/taxonomies/client.industry/options', ['key' => 'newco', 'label_ar' => 'جديد', 'label_en' => 'New'])
            ->assertCreated();
    }

    public function test_updating_a_system_option_cannot_change_key_or_system_flag_but_can_change_labels(): void
    {
        $new = $this->platformOption('request.status', 'new');

        $updated = $this->service()->updateOption($new, [
            'key' => 'hacked',          // ignored (immutable)
            'is_system' => false,        // ignored (immutable)
            'label_ar' => 'جديد معدل',   // allowed
            'label_en' => 'New edited',  // allowed
            'color' => '#123456',        // allowed
        ]);

        $this->assertSame('new', $updated->key);
        $this->assertTrue($updated->is_system);
        $this->assertSame('New edited', $updated->label_en);
        $this->assertSame('#123456', $updated->color);
    }

    public function test_deactivate_hides_an_option_from_the_effective_set(): void
    {
        $before = $this->service()->options('client.industry')->pluck('key')->all();
        $this->assertContains('events', $before);

        $this->service()->deactivate($this->platformOption('client.industry', 'events'));

        $after = $this->service()->options('client.industry')->pluck('key')->all();
        $this->assertNotContains('events', $after);
    }

    public function test_a_used_option_cannot_be_hard_deleted(): void
    {
        // Register a usage source: records referencing this option live in permissions.key (seeded, so real rows).
        TaxonomyService::registerUsageSource('client.industry', 'permissions', 'key');

        $used = $this->service()->createOption('client.industry', [
            'key' => 'billing.view', // matches a seeded permission key → usage = 1
            'label_ar' => 'مستخدم', 'label_en' => 'Used',
        ]);
        $unused = $this->service()->createOption('client.industry', [
            'key' => 'no.such.permission', 'label_ar' => 'غير مستخدم', 'label_en' => 'Unused',
        ]);

        $this->assertSame(1, $this->service()->usage($used));
        $this->assertSame(0, $this->service()->usage($unused));

        // The used option is delete-protected — merge/reassign/deactivate required.
        try {
            $this->service()->deleteOption($used);
            $this->fail('Expected a used option to be delete-protected.');
        } catch (TaxonomyException $e) {
            $this->assertSame(409, $e->status());
        }
        $this->assertDatabaseHas('taxonomy_options', ['id' => $used->id]);

        // Deactivate is the sanctioned alternative and succeeds.
        $this->service()->deactivate($used);
        $this->assertFalse($used->refresh()->is_active);

        // An unused, non-system option can be hard-deleted.
        $this->service()->deleteOption($unused);
        $this->assertDatabaseMissing('taxonomy_options', ['id' => $unused->id]);
    }

    public function test_a_system_option_cannot_be_deleted(): void
    {
        $this->expectException(TaxonomyException::class);
        $this->service()->deleteOption($this->platformOption('request.status', 'new'));
    }

    public function test_reorder_persists_sort_order(): void
    {
        $high = $this->platformOption('request.priority', 'high');
        $low = $this->platformOption('request.priority', 'low');
        $medium = $this->platformOption('request.priority', 'medium');

        $this->service()->reorder([$low->id, $medium->id, $high->id]);

        $this->assertSame(0, $low->refresh()->sort_order);
        $this->assertSame(1, $medium->refresh()->sort_order);
        $this->assertSame(2, $high->refresh()->sort_order);
    }

    public function test_set_default_sets_exactly_one_default(): void
    {
        $high = $this->platformOption('request.priority', 'high');
        $this->service()->setDefault($high);

        $this->assertTrue($high->refresh()->is_default);

        $defaults = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $this->definition('request.priority')->id)
            ->whereNull('tenant_id')->where('is_default', true)->count();

        $this->assertSame(1, $defaults);
    }

    public function test_merge_reassigns_retires_and_audits(): void
    {
        // Two tenant custom options under a tenant-manageable definition.
        $from = $this->service()->createOption('campaign.regions', ['key' => 'riyadh', 'label_ar' => 'الرياض', 'label_en' => 'Riyadh']);
        $into = $this->service()->createOption('campaign.regions', ['key' => 'central', 'label_ar' => 'الوسطى', 'label_en' => 'Central']);

        // A pluggable reassignment resolver stands in for the adoption phase (reports 4 records moved).
        TaxonomyService::registerReassignmentResolver('campaign.regions', fn (TaxonomyOption $f, TaxonomyOption $t): int => 4);

        $result = $this->service()->merge($from->id, $into->id);

        $this->assertSame(4, $result['reassigned']);
        $this->assertFalse($from->refresh()->is_active); // soft-retired
        $this->assertTrue($into->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'taxonomy.option.merged', 'entity_id' => $from->id]);
    }

    public function test_reassign_moves_records_without_retiring_and_audits(): void
    {
        $from = $this->service()->createOption('campaign.regions', ['key' => 'jeddah', 'label_ar' => 'جدة', 'label_en' => 'Jeddah']);
        $into = $this->service()->createOption('campaign.regions', ['key' => 'western', 'label_ar' => 'الغربية', 'label_en' => 'Western']);

        TaxonomyService::registerReassignmentResolver('campaign.regions', fn (TaxonomyOption $f, TaxonomyOption $t): int => 7);

        $result = $this->service()->reassign($from->id, $into->id);

        $this->assertSame(7, $result['reassigned']);
        $this->assertTrue($from->refresh()->is_active); // NOT retired
        $this->assertDatabaseHas('audit_logs', ['action' => 'taxonomy.option.reassigned', 'entity_id' => $from->id]);
    }

    public function test_create_option_writes_an_audit_entry(): void
    {
        $option = $this->service()->createOption('client.industry', ['key' => 'logistics', 'label_ar' => 'الخدمات اللوجستية', 'label_en' => 'Logistics']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'taxonomy.option.created', 'entity_id' => $option->id]);
    }

    public function test_index_and_options_endpoints_require_view_permission(): void
    {
        $none = $this->userWith([]);
        $this->actingAs($none, 'sanctum')->getJson('/api/v1/taxonomies')->assertForbidden();

        $viewer = $this->userWith(['taxonomies.view']);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/taxonomies')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/taxonomies/request.status/options')->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_legacy_request_catalog_enums_are_untouched(): void
    {
        // The taxonomy engine is additive: the legacy request status/type catalog is a separate table and is
        // neither read nor written by the taxonomy seed.
        $this->seed(RequestCatalogSeeder::class);

        $this->assertDatabaseHas('request_statuses', ['key' => 'under_review']);
        $this->assertDatabaseHas('request_types', ['key' => 'paid_campaign_launch']);

        // Re-seeding the taxonomy leaves the legacy catalog counts unchanged.
        $legacyStatuses = DB::table('request_statuses')->count();
        $this->seed(TaxonomyEngineSeeder::class);
        $this->assertSame($legacyStatuses, DB::table('request_statuses')->count());
    }
}
