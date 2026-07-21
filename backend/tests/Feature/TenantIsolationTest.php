<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the core multi-tenant invariant: a tenant can never read or write another tenant's data.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        $this->tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
    }

    public function test_tenant_id_is_autofilled_from_context_on_create(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $workspace = Workspace::create(['name' => 'WS-A', 'slug' => 'ws-a']);

        $this->assertSame($this->tenantA->id, $workspace->tenant_id);
    }

    public function test_queries_only_return_current_tenant_rows(): void
    {
        $this->context->setTenantId($this->tenantA->id);
        Workspace::create(['name' => 'WS-A', 'slug' => 'ws-a']);

        $this->context->setTenantId($this->tenantB->id);
        Workspace::create(['name' => 'WS-B', 'slug' => 'ws-b']);

        // As tenant B, only B's workspace is visible.
        $this->assertSame(1, Workspace::count());
        $this->assertSame('WS-B', Workspace::first()->name);

        // Switch to tenant A: only A's workspace is visible.
        $this->context->setTenantId($this->tenantA->id);
        $this->assertSame(1, Workspace::count());
        $this->assertSame('WS-A', Workspace::first()->name);
    }

    public function test_cannot_fetch_other_tenants_row_by_id(): void
    {
        $this->context->setTenantId($this->tenantA->id);
        $a = Workspace::create(['name' => 'WS-A', 'slug' => 'ws-a']);

        $this->context->setTenantId($this->tenantB->id);
        $this->assertNull(Workspace::find($a->getKey()));
    }

    public function test_fails_closed_when_no_tenant_is_resolved(): void
    {
        $this->context->setTenantId($this->tenantA->id);
        Workspace::create(['name' => 'WS-A', 'slug' => 'ws-a']);

        // No tenant in context => must return nothing rather than everything.
        $this->context->forget();
        $this->assertSame(0, Workspace::count());
    }

    public function test_platform_scope_can_read_across_tenants(): void
    {
        $this->context->setTenantId($this->tenantA->id);
        Workspace::create(['name' => 'WS-A', 'slug' => 'ws-a']);
        $this->context->setTenantId($this->tenantB->id);
        Workspace::create(['name' => 'WS-B', 'slug' => 'ws-b']);

        $this->context->enterPlatformScope();
        $this->assertSame(2, Workspace::count());
    }
}
