<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRANDING-HIERARCHY-001 — the last link of the fallback chain was not a link.
 *
 * Every surface documents client → agency → **CampaignsHub**, and `BrandingSpec::SCOPES` opens with
 * `platform`. But `BrandingAsset` uses `BelongsToTenant`, so a platform-scope row lived inside ONE
 * tenant and could never answer for another: the final fallback was unreachable by construction for
 * everybody except whoever happened to hold the row. And since any tenant with `branding.manage`
 * could write one, the scope meant «CampaignsHub's brand» in the documentation and «mine, invisibly»
 * in the database.
 *
 * The production shared link this was found on returns `logo_source: "none"` — honestly, because
 * that install has configured no logo at any layer. This test is about the layer that could not have
 * answered even if it had.
 */
final class PlatformBrandLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug.'-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        return $tenant;
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 1200, 300);
    }

    /** The product's own mark carries no tenant, because it belongs to none. */
    public function test_a_platform_asset_belongs_to_no_tenant(): void
    {
        $this->tenant('operator');

        $asset = app(BrandingService::class)->storeAsset('platform', null, 'primary_horizontal', 'any', $this->png());

        $this->assertNull($asset->tenant_id, 'the platform mark was stored under a customer');
        $this->assertSame('platform', $asset->scope);
    }

    /**
     * And it answers for a tenant that has none of its own — which is the whole point.
     *
     * Before this, `resolve()` read every layer through the tenant scope, so the platform layer
     * returned nothing for anybody but its accidental owner and the chain simply ended one link
     * early.
     */
    public function test_the_platform_mark_answers_for_a_tenant_that_has_no_brand(): void
    {
        $this->tenant('operator');
        app(BrandingService::class)->storeAsset('platform', null, 'primary_horizontal', 'any', $this->png());

        $other = $this->tenant('agency');

        $resolved = app(BrandingService::class)->resolve('tenant', null);

        $this->assertArrayHasKey('primary_horizontal', $resolved);
        $this->assertNull($resolved['primary_horizontal']->tenant_id);
        $this->assertSame('platform', $resolved['primary_horizontal']->scope);
        $this->assertNotSame($other->id, $resolved['primary_horizontal']->tenant_id);
    }

    /**
     * A tenant's own mark still wins. The platform layer is a FALLBACK, not an override — an agency
     * that has uploaded its logo must never find the product's on its client's report.
     */
    public function test_a_tenants_own_brand_still_wins_over_the_platform(): void
    {
        $this->tenant('operator');
        app(BrandingService::class)->storeAsset('platform', null, 'primary_horizontal', 'any', $this->png());

        $agency = $this->tenant('agency');
        app(BrandingService::class)->storeAsset('tenant', null, 'primary_horizontal', 'any', $this->png());

        $resolved = app(BrandingService::class)->resolve('tenant', null);

        $this->assertSame($agency->id, $resolved['primary_horizontal']->tenant_id);
        $this->assertSame('tenant', $resolved['primary_horizontal']->scope);
    }

    /**
     * The platform layer is read outside the tenant scope, and that must not become a hole.
     *
     * A blunt `withoutGlobalScopes()` would have let one tenant's CLIENT-scoped asset answer for
     * another, which is a far worse defect than the one being fixed. The query names
     * `tenant_id IS NULL` and `scope = platform` explicitly, and this is what holds it to that.
     */
    public function test_reading_the_platform_layer_does_not_expose_another_tenants_brand(): void
    {
        $first = $this->tenant('first');
        app(BrandingService::class)->storeAsset('tenant', null, 'primary_horizontal', 'any', $this->png());

        $this->tenant('second');

        $this->assertSame([], app(BrandingService::class)->resolve('tenant', null));

        // And the first tenant's row is still there — it was hidden, not missing.
        $this->assertSame(1, BrandingAsset::withoutGlobalScopes()->where('tenant_id', $first->id)->count());
    }

    /**
     * One slot, one file — still, now that the tenant column can be NULL.
     *
     * Postgres treats NULLs as distinct in a unique index, so the existing
     * `(tenant_id, scope, scope_id, kind, theme)` constraint stops constraining the moment
     * `tenant_id` is NULL. Without the partial index the migration adds, «one file per slot» would
     * silently become «as many as you like», and `resolve()` would start picking whichever row the
     * database happened to return first.
     */
    public function test_the_platform_slot_still_holds_one_file(): void
    {
        $this->tenant('operator');
        $service = app(BrandingService::class);

        $first = $service->storeAsset('platform', null, 'primary_horizontal', 'any', $this->png());
        $second = $service->storeAsset('platform', null, 'primary_horizontal', 'any', $this->png());

        $this->assertSame($first->id, $second->id, 'the slot was duplicated instead of upserted');
        $this->assertSame(1, BrandingAsset::withoutGlobalScopes()->where('scope', 'platform')->count());
    }
}
