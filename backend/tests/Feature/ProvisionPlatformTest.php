<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PROD-PROVISION-001 — a migrated production database must not be an empty shop.
 *
 * ## What was actually wrong, observed on the live site
 *
 * `scripts/deploy-production.sh` ran `migrate --force` and nothing else, because the deployment
 * checklist says never to seed a production database — correct advice about DEMO data that had
 * quietly been applied to reference data too. The result, on campaignshub.io:
 *
 * ```
 * GET /api/v1/plans                                → {"plans": []}
 * GET /api/v1/public/catalog/paid-media-services   → {"categories": [], "services": []}
 * ```
 *
 * So the homepage showed no services, `/services` said «لا توجد خدمة مطابقة», and sign-up failed at
 * plan selection with «تعذّر». None of those screens was broken — each was faithfully rendering an
 * empty catalogue, which is why staring at the frontend would never have found it.
 *
 * ## What these tests hold
 *
 * That the command fills the catalogue, that it creates nothing customer-shaped while doing so, and
 * that running it twice — which every deploy now does — changes nothing the second time.
 */
final class ProvisionPlatformTest extends TestCase
{
    use RefreshDatabase;

    /** **The defect, pinned.** A migrated-only database has no plans and no services. */
    public function test_a_migrated_database_starts_with_an_empty_catalogue(): void
    {
        $this->assertSame(0, SubscriptionPlan::count(), 'the fixture must start from the state production was in');
        $this->assertSame(0, TaxonomyOption::withoutGlobalScopes()->count());

        // Which is exactly what the public endpoints answered on the live site.
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonPath('data.plans', []);
        $this->getJson('/api/v1/public/catalog/paid-media-services')
            ->assertOk()
            ->assertJsonPath('data.services', []);
    }

    /** And after provisioning, the two endpoints the homepage and sign-up read are populated. */
    public function test_provisioning_fills_the_plans_and_the_service_catalogue(): void
    {
        Artisan::call('platform:provision');

        $this->assertGreaterThan(0, SubscriptionPlan::count(), 'sign-up cannot offer a plan that does not exist');

        $plans = $this->getJson('/api/v1/plans')->assertOk()->json('data.plans');
        $this->assertNotEmpty($plans, 'the public plan list is what the sign-up step reads');

        $catalog = $this->getJson('/api/v1/public/catalog/paid-media-services')->assertOk()->json('data');
        $this->assertNotEmpty($catalog['categories'], 'the homepage services section reads the categories');
        $this->assertNotEmpty($catalog['services'], 'and /services reads the services');
    }

    /**
     * It creates no tenant and no user. That is the whole difference from `db:seed`.
     *
     * A provisioning step that quietly made a demo workspace on a production box would be worse than
     * the defect it fixes, so this is asserted rather than assumed from the seeder list.
     */
    public function test_provisioning_creates_no_tenant_and_no_customer(): void
    {
        Artisan::call('platform:provision');

        $this->assertSame(0, Tenant::withoutGlobalScopes()->count(), 'reference data has no tenant in it');
        $this->assertSame(0, User::count(), 'and no account');
    }

    /** Idempotent, because every deploy runs it — the second run must be a no-op. */
    public function test_running_it_twice_changes_nothing(): void
    {
        Artisan::call('platform:provision');

        $plans = SubscriptionPlan::orderBy('code')->pluck('id', 'code')->all();
        $options = TaxonomyOption::withoutGlobalScopes()->count();

        Artisan::call('platform:provision');

        $this->assertSame($plans, SubscriptionPlan::orderBy('code')->pluck('id', 'code')->all(), 'a plan must keep its identity across a deploy');
        $this->assertSame($options, TaxonomyOption::withoutGlobalScopes()->count());
    }

    /** `--pretend` reports and writes nothing, so an operator can look before running it. */
    public function test_pretend_changes_nothing(): void
    {
        Artisan::call('platform:provision', ['--pretend' => true]);

        $this->assertSame(0, SubscriptionPlan::count());
        $this->assertSame(0, TaxonomyOption::withoutGlobalScopes()->count());
    }
}
