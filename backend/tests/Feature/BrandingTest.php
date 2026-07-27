<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\BrandingSpec;
use App\Domains\Branding\Models\BrandingAsset;
use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The Branding Center is honest by construction: only SVG/PNG within 2 MB may land, every slot
 * (scope, scope_id, kind, theme) is unique (a re-upload upserts, never piles up), light/dark variants coexist,
 * effective assets resolve client → tenant → platform, and a tenant can never see another tenant's assets.
 *
 * These tests drive the SERVICE + models directly (routing is wired separately by the orchestrator).
 */
final class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function branding(): BrandingService
    {
        return app(BrandingService::class);
    }

    private function png(int $w = 1200, int $h = 300): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', $w, $h);
    }

    public function test_a_valid_png_within_limits_stores_and_upserts_its_slot(): void
    {
        $asset = $this->branding()->storeAsset('tenant', null, 'primary_horizontal', 'light', $this->png(1200, 300));

        $this->assertSame($this->tenant->id, $asset->tenant_id);
        $this->assertSame('image/png', $asset->mime);
        $this->assertSame(1200, $asset->width);
        $this->assertSame(300, $asset->height);
        $this->assertNotSame('', (string) $asset->checksum);

        // The bytes live on the private disk, and the pristine original is preserved alongside.
        Storage::disk('local')->assertExists($asset->path);
        Storage::disk('local')->assertExists($asset->original_path);

        // A second upload to the same slot upserts (no second row) and repoints to fresh bytes.
        $firstPath = $asset->path;
        $again = $this->branding()->storeAsset('tenant', null, 'primary_horizontal', 'light', $this->png(1200, 300));

        $this->assertSame($asset->id, $again->id);
        $this->assertSame(1, BrandingAsset::count());
        $this->assertNotSame($firstPath, $again->path);
        Storage::disk('local')->assertMissing($firstPath); // superseded bytes are cleaned up
    }

    public function test_oversize_or_wrong_mime_is_rejected_by_the_spec(): void
    {
        // Oversize (> 2 MB) — rejected.
        $tooBig = BrandingSpec::validateUpload('primary_horizontal', 'image/png', BrandingSpec::MAX_BYTES + 1);
        $this->assertFalse($tooBig['ok']);

        // Wrong MIME — rejected.
        $wrongMime = BrandingSpec::validateUpload('square_icon', 'application/pdf', 1024);
        $this->assertFalse($wrongMime['ok']);

        // A valid PNG within limits — accepted.
        $ok = BrandingSpec::validateUpload('square_icon', 'image/png', 1024);
        $this->assertTrue($ok['ok']);

        // The service refuses a non-PNG/SVG upload (spec-enforced at the boundary).
        $this->expectException(InvalidArgumentException::class);
        $this->branding()->storeAsset('tenant', null, 'square_icon', 'any', UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'));
    }

    public function test_light_and_dark_variants_coexist_in_the_same_slot_family(): void
    {
        $light = $this->branding()->storeAsset('tenant', null, 'primary_horizontal', 'light', $this->png());
        $dark = $this->branding()->storeAsset('tenant', null, 'primary_horizontal', 'dark', $this->png());

        $this->assertNotSame($light->id, $dark->id);
        $this->assertSame(2, BrandingAsset::where('kind', 'primary_horizontal')->count());

        // Each theme resolves to its own asset.
        $this->assertSame($light->id, $this->branding()->resolve('tenant', null, 'light')['primary_horizontal']->id);
        $this->assertSame($dark->id, $this->branding()->resolve('tenant', null, 'dark')['primary_horizontal']->id);
    }

    public function test_resolve_falls_back_client_then_tenant_then_platform(): void
    {
        $clientId = (string) Str::uuid();

        // platform: report_logo only. tenant: square_icon. client: its own primary_horizontal.
        $platformReport = $this->branding()->storeAsset('platform', null, 'report_logo', 'any', $this->png(800, 240));
        $tenantIcon = $this->branding()->storeAsset('tenant', null, 'square_icon', 'any', $this->png(512, 512));
        $clientPrimary = $this->branding()->storeAsset('client', $clientId, 'primary_horizontal', 'any', $this->png());

        $resolved = $this->branding()->resolve('client', $clientId, 'any');

        // The client's own asset wins; the missing kinds fall back down the chain.
        $this->assertSame($clientPrimary->id, $resolved['primary_horizontal']->id);
        $this->assertSame($tenantIcon->id, $resolved['square_icon']->id);      // client → tenant
        $this->assertSame($platformReport->id, $resolved['report_logo']->id);  // client → tenant → platform
    }

    public function test_theme_falls_back_to_any_when_the_requested_theme_is_absent(): void
    {
        $any = $this->branding()->storeAsset('tenant', null, 'square_icon', 'any', $this->png(512, 512));

        // Requesting the dark theme finds no dark variant and falls back to the theme-agnostic asset.
        $this->assertSame($any->id, $this->branding()->resolve('tenant', null, 'dark')['square_icon']->id);
    }

    public function test_save_settings_upserts_and_records_white_label_verbatim(): void
    {
        $first = $this->branding()->saveSettings('tenant', null, [
            'colors' => ['primary' => '#0F172A'],
            'fonts' => ['body' => 'Inter'],
            'white_label' => true,
        ]);

        $this->assertTrue($first->white_label);
        $this->assertSame('#0F172A', $first->colors['primary']);

        $second = $this->branding()->saveSettings('tenant', null, ['white_label' => false]);

        $this->assertSame($first->id, $second->id);       // upsert on (tenant, scope, scope_id)
        $this->assertSame(1, BrandingSetting::count());
        $this->assertFalse($second->refresh()->white_label);
    }

    public function test_tenant_isolation_hides_another_tenants_assets(): void
    {
        // Tenant A stores a tenant-wide primary logo.
        $assetA = $this->branding()->storeAsset('tenant', null, 'primary_horizontal', 'any', $this->png());

        // Switch to a fresh tenant B.
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);

        // B sees none of A's assets, and resolve returns nothing for B.
        $this->assertSame(0, BrandingAsset::count());
        $this->assertArrayNotHasKey('primary_horizontal', $this->branding()->resolve('tenant', null, 'any'));

        // A's asset is untouched back in A's context.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertSame(1, BrandingAsset::count());
        $this->assertTrue(BrandingAsset::whereKey($assetA->id)->exists());
    }
}
