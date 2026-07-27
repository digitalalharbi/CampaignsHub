<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerifiesContact;
use Tests\TestCase;

/** The public portal resolves its tenant by host / default-portal flag — never a fragile env UUID. */
final class RequestPortalResolverTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        config(['requests.portal_tenant_id' => null]); // ignore any dev override
    }

    private function payload(): array
    {
        return $this->withVerifiedContact(['type' => 'consulting', 'contact_name' => 'Client Co', 'contact_email' => 'c@x.test', 'company_name' => 'Client Co']);
    }

    public function test_resolves_the_default_portal_tenant_without_env_or_uuid(): void
    {
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'a', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);

        $ref = $this->postJson('/api/v1/requests', $this->payload())->assertCreated()->json('data.reference');
        $this->assertEquals($tenant->id, ExternalRequest::where('reference', $ref)->value('tenant_id'));
    }

    public function test_resolves_by_matching_portal_domain(): void
    {
        Tenant::create(['name' => 'Default', 'slug' => 'd', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
        $byHost = Tenant::create(['name' => 'Host', 'slug' => 'h', 'status' => 'active', 'portal_domain' => 'portal.example', 'portal_enabled' => true]);

        // A request arriving on portal.example resolves to that tenant, overriding the default portal.
        $ref = $this->postJson('http://portal.example/api/v1/requests', $this->payload())
            ->assertCreated()->json('data.reference');
        $this->assertEquals($byHost->id, ExternalRequest::where('reference', $ref)->value('tenant_id'));
    }

    public function test_fails_closed_when_no_portal_is_configured(): void
    {
        // No default portal, no matching domain, no env override → 404 (never Tenant::first()).
        Tenant::create(['name' => 'NotAPortal', 'slug' => 'np', 'status' => 'active']);
        $this->postJson('/api/v1/requests', $this->payload())->assertNotFound();
    }

    public function test_disabled_portal_is_not_resolved(): void
    {
        Tenant::create(['name' => 'Disabled', 'slug' => 'dis', 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => false]);
        $this->postJson('/api/v1/requests', $this->payload())->assertNotFound();
    }
}
