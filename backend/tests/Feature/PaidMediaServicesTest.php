<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Services\BillingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

/**
 * Backend of the paid-media service vertical: the engine-managed `request.paid_service` catalog, the public
 * read-only catalog endpoint (fail-closed), server-side service validation + canonical persistence, and the
 * carry-through of selected services into quote/invoice line items.
 */
final class PaidMediaServicesTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    private const CATALOG_URL = '/api/v1/public/catalog/paid-media-services';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->seed(TaxonomyEngineSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        app(TenantContext::class)->forget();
    }

    /** The platform-scope request.paid_service definition (bypassing the tenant scope). */
    private function paidDefinition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::withoutGlobalScopes()
            ->whereNull('tenant_id')->where('key', 'request.paid_service')->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // Taxonomy structure
    // ---------------------------------------------------------------------

    public function test_paid_service_definition_is_hierarchical_multiselect_and_tenant_manageable(): void
    {
        $def = $this->paidDefinition();

        $this->assertFalse($def->is_system, 'request.paid_service must NOT be a system definition.');
        $this->assertTrue($def->allows_custom_options, 'request.paid_service must allow tenant custom options.');
        $this->assertSame('multi', $def->field_type);

        $options = TaxonomyOption::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('taxonomy_definition_id', $def->getKey())->get();
        $categories = $options->whereNull('parent_option_id');
        $services = $options->whereNotNull('parent_option_id');

        // 10 categories, exact keys.
        $this->assertSame([
            'launch_manage', 'optimization', 'audit_analysis', 'measurement_tracking', 'integrations',
            'strategy_planning', 'reporting_dashboards', 'creatives', 'objective_services', 'consulting_training',
        ], $categories->pluck('key')->values()->all());

        // ~90 services (94 seeded); each parented to a real category.
        $this->assertGreaterThanOrEqual(90, $services->count());
        $categoryIds = $categories->pluck('id')->all();
        foreach ($services as $service) {
            $this->assertContains($service->parent_option_id, $categoryIds, "service [{$service->key}] has no category parent");
        }

        // Expected children per category.
        $expected = [
            'launch_manage' => 8, 'optimization' => 9, 'audit_analysis' => 9, 'measurement_tracking' => 14,
            'integrations' => 12, 'strategy_planning' => 10, 'reporting_dashboards' => 8, 'creatives' => 8,
            'objective_services' => 9, 'consulting_training' => 7,
        ];
        foreach ($expected as $categoryKey => $count) {
            $parentId = $categories->firstWhere('key', $categoryKey)->id;
            $this->assertSame($count, $services->where('parent_option_id', $parentId)->count(), "category [{$categoryKey}] child count");
        }

        // Exactly the 8 popular flags.
        $popular = $services->filter(fn (TaxonomyOption $o): bool => ($o->metadata['popular'] ?? false) === true)->pluck('key')->values()->all();
        sort($popular);
        $expectedPopular = ['ad_account_audit', 'campaign_performance_analysis', 'existing_management', 'ga4', 'improve_performance', 'meta_pixel', 'new_campaign', 'weekly_report'];
        sort($expectedPopular);
        $this->assertSame($expectedPopular, $popular);

        // Every option in the catalog is published (is_public) so the public endpoint can serve it.
        $this->assertSame($options->count(), $options->where('is_public', true)->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = TaxonomyOption::withoutGlobalScopes()->count();
        $this->seed(TaxonomyEngineSeeder::class);
        $this->assertSame($before, TaxonomyOption::withoutGlobalScopes()->count(), 'Re-running the seeder must not add options.');
    }

    // ---------------------------------------------------------------------
    // Public catalog endpoint
    // ---------------------------------------------------------------------

    public function test_public_catalog_is_anonymous_and_has_expected_shape(): void
    {
        $res = $this->getJson(self::CATALOG_URL)->assertOk();

        $res->assertJsonPath('success', true)
            ->assertJsonCount(10, 'data.categories')
            ->assertJsonCount(94, 'data.services')
            ->assertJsonStructure(['data' => [
                'version',
                'categories' => [['key', 'label_ar', 'label_en', 'icon', 'sort_order']],
                'services' => [['key', 'category_key', 'label_ar', 'label_en', 'description_ar', 'description_en', 'icon', 'sort_order', 'required_field_rules']],
            ]]);

        // Deterministic ordering: sort_order is 1..N in payload order for both lists.
        $catOrders = array_column($res->json('data.categories'), 'sort_order');
        $this->assertSame(range(1, 10), $catOrders);
        $svcOrders = array_column($res->json('data.services'), 'sort_order');
        $this->assertSame(range(1, 94), $svcOrders);

        // A known service carries its category_key + required_field_rules.
        $new = collect($res->json('data.services'))->firstWhere('key', 'new_campaign');
        $this->assertSame('launch_manage', $new['category_key']);
        $this->assertContains('budget', $new['required_field_rules']);
    }

    public function test_public_catalog_never_exposes_internal_fields(): void
    {
        $body = $this->getJson(self::CATALOG_URL)->assertOk()->getContent();

        foreach (['is_public', 'is_system', 'tenant_id', 'popular', 'metadata', 'usage', 'parent_option_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "public catalog must not expose [{$forbidden}]");
        }
    }

    public function test_public_catalog_sets_etag_and_returns_304_when_matched(): void
    {
        $res = $this->getJson(self::CATALOG_URL)->assertOk();
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertNotEmpty($res->headers->get('Cache-Control'));

        // A conditional GET with the same ETag → 304, no body.
        $this->getJson(self::CATALOG_URL, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_public_catalog_fails_closed_on_tenant_inactive_private_and_other_definitions(): void
    {
        $def = $this->paidDefinition();

        // (1) a TENANT-scoped custom option (public + active) — must never leak.
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $def->getKey(), 'tenant_id' => $this->tenant->id,
            'key' => 'tenant_secret_service', 'label_ar' => 'سري', 'label_en' => 'Tenant secret',
            'sort_order' => 900, 'is_active' => true, 'is_public' => true, 'is_system' => false,
        ]);

        // (2) a platform INACTIVE option.
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $def->getKey(), 'tenant_id' => null,
            'key' => 'inactive_service', 'label_ar' => 'غير نشط', 'label_en' => 'Inactive',
            'parent_option_id' => null, 'sort_order' => 901, 'is_active' => false, 'is_public' => true, 'is_system' => false,
        ]);

        // (3) a platform PRIVATE option (is_public = false).
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $def->getKey(), 'tenant_id' => null,
            'key' => 'private_service', 'label_ar' => 'خاص', 'label_en' => 'Private',
            'parent_option_id' => null, 'sort_order' => 902, 'is_active' => true, 'is_public' => false, 'is_system' => false,
        ]);

        // (4) a PUBLIC option under a DIFFERENT definition — must never appear here.
        $other = TaxonomyDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'request.source')->firstOrFail();
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $other->getKey(), 'tenant_id' => null,
            'key' => 'other_def_service', 'label_ar' => 'أخرى', 'label_en' => 'Other def',
            'sort_order' => 903, 'is_active' => true, 'is_public' => true, 'is_system' => false,
        ]);

        $body = $this->getJson(self::CATALOG_URL)->assertOk()->getContent();

        foreach (['tenant_secret_service', 'inactive_service', 'private_service', 'other_def_service'] as $key) {
            $this->assertStringNotContainsString($key, $body, "leaked [{$key}] into the public catalog");
        }
    }

    // ---------------------------------------------------------------------
    // Intake persistence + server-side validation
    // ---------------------------------------------------------------------

    private function intakePayload(array $overrides = []): array
    {
        return $this->withVerifiedContact(array_merge([
            'type' => 'paid_campaign_launch',
            'contact_name' => 'Sara Ali',
            'contact_email' => 'sara@example.com',
            'company_name' => 'Sara Store',
        ], $overrides));
    }

    public function test_intake_persists_valid_services_to_request_services_and_round_trips(): void
    {
        $res = $this->postJson('/api/v1/requests', $this->intakePayload([
            'services' => ['new_campaign', 'ga4', 'meta_pixel'],
            'service_details' => ['ga4' => ['site_url' => 'https://shop.test']],
        ]))->assertCreated();

        $req = ExternalRequest::where('reference', $res->json('data.reference'))->firstOrFail();

        // Canonical rows exist with resolved category_key + details.
        $this->assertDatabaseHas('request_services', ['request_id' => $req->id, 'service_key' => 'new_campaign', 'category_key' => 'launch_manage']);
        $this->assertDatabaseHas('request_services', ['request_id' => $req->id, 'service_key' => 'ga4', 'category_key' => 'measurement_tracking']);
        $this->assertSame(3, $req->requestServices()->count());
        $this->assertSame(['site_url' => 'https://shop.test'], $req->requestServices()->where('service_key', 'ga4')->first()->details);

        // Denormalized mirror.
        $this->assertEqualsCanonicalizing(['new_campaign', 'ga4', 'meta_pixel'], $req->fresh()->services);

        // Round-trips (resolved) on the public tracking view.
        $this->getJson("/api/v1/requests/track/{$res->json('data.tracking_token')}")
            ->assertOk()
            ->assertJsonPath('data.services.0.key', 'new_campaign')
            ->assertJsonPath('data.services.0.label_ar', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_intake_rejects_forged_unknown_inactive_and_private_service_keys(): void
    {
        // Unknown / forged key.
        $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['new_campaign', 'not_a_real_service']]))
            ->assertStatus(422)->assertJsonValidationErrors('services.1');

        // Deactivate a real service → its key is no longer accepted.
        TaxonomyOption::withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'ga4')->update(['is_active' => false]);
        $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['ga4']]))
            ->assertStatus(422)->assertJsonValidationErrors('services.0');

        // Make a real service private → not accepted either.
        TaxonomyOption::withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'meta_pixel')->update(['is_public' => false]);
        $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['meta_pixel']]))
            ->assertStatus(422)->assertJsonValidationErrors('services.0');

        $this->assertSame(0, ExternalRequest::count(), 'no request should have been created for a rejected submit');
    }

    public function test_legacy_intake_without_services_still_succeeds(): void
    {
        $res = $this->postJson('/api/v1/requests', $this->intakePayload())->assertCreated();
        $req = ExternalRequest::where('reference', $res->json('data.reference'))->firstOrFail();

        $this->assertNull($req->services);
        $this->assertSame(0, $req->requestServices()->count());
    }

    public function test_second_tenant_cannot_see_first_tenants_request_services(): void
    {
        // Tenant A owns the default portal; submit a request WITH services to it.
        $ref = $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['new_campaign']]))
            ->assertCreated()->json('data.reference');
        $reqA = ExternalRequest::where('reference', $ref)->firstOrFail();
        $this->assertSame($this->tenant->id, $reqA->tenant_id);

        // A second tenant with a fully-permissioned user.
        $tenantB = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);
        $roleB = Role::create(['tenant_id' => $tenantB->id, 'name' => 'Owner', 'slug' => 'owner']);
        $roleB->givePermissionTo(...Permission::pluck('key')->all());
        $userB = User::create(['tenant_id' => $tenantB->id, 'name' => 'B', 'email' => 'b@other.test', 'password' => 'secret123']);
        $userB->assignRole($roleB);

        // Tenant B cannot see tenant A's request in the internal dashboard (scoped + 404 on direct id).
        $this->actingAs($userB, 'sanctum')->getJson('/api/v1/app/requests')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($userB, 'sanctum')->getJson("/api/v1/app/requests/{$reqA->id}")->assertNotFound();
    }

    public function test_internal_show_surfaces_resolved_services(): void
    {
        $ref = $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['new_campaign', 'ga4']]))
            ->assertCreated()->json('data.reference');
        $reqA = ExternalRequest::where('reference', $ref)->firstOrFail();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $owner->assignRole($role);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/app/requests/{$reqA->id}")
            ->assertOk()
            ->assertJsonPath('data.services', ['new_campaign', 'ga4'])
            ->assertJsonPath('data.services_resolved.0.key', 'new_campaign')
            ->assertJsonPath('data.services_resolved.0.category_key', 'launch_manage');
    }

    // ---------------------------------------------------------------------
    // Quote / invoice carry-through
    // ---------------------------------------------------------------------

    public function test_quote_and_invoice_built_from_request_carry_services_as_stable_line_items(): void
    {
        $ref = $this->postJson('/api/v1/requests', $this->intakePayload(['services' => ['new_campaign', 'ga4']]))
            ->assertCreated()->json('data.reference');
        $req = ExternalRequest::where('reference', $ref)->firstOrFail();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        /** @var BillingService $billing */
        $billing = app(BillingService::class);
        $quote = $billing->quoteFromRequest($req, ['client_workspace_id' => $client->id]);

        $quoteKeys = array_column($quote->line_items ?? [], 'key');
        $this->assertSame(['new_campaign', 'ga4'], $quoteKeys);
        $this->assertSame('Launch a new campaign', $quote->line_items[0]['label']);
        $this->assertNull($quote->line_items[0]['amount']); // priced later
        $this->assertSame($req->id, $quote->external_request_id);

        // Approving the quote issues an invoice that carries the SAME stable service keys.
        $invoice = $billing->approveQuote($quote);
        $this->assertSame(['new_campaign', 'ga4'], array_column($invoice->line_items ?? [], 'key'));
    }
}
