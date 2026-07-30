<?php

namespace Tests;

use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Read persisted rows outside any request's tenant scope.
     *
     * Since ADR 0002 the tenant/membership context is request-scoped and torn down when the request
     * ends, so a test that issues an HTTP call and then queries a tenant-scoped model directly no
     * longer inherits that request's scope — the global scope filters everything out and the
     * assertion sees zero rows.
     *
     * That is the correct behaviour rather than a regression: scope belonging to a request is the
     * entire point, and a test relying on it leaking was asserting against state the application
     * itself would not have had. Call this when the assertion is about what was PERSISTED, rather
     * than about what one tenant is allowed to see.
     */
    protected bool $readsAcrossTenants = false;

    protected ?string $heldTenantId = null;

    protected function assertingAcrossTenants(): void
    {
        $this->readsAcrossTenants = true;
        app(TenantContext::class)->enterPlatformScope();
    }

    /**
     * Keep operating as one tenant for the whole test, across requests.
     *
     * Some tests both CREATE tenant-scoped rows directly (relying on BelongsToTenant to fill
     * `tenant_id` from the context) and issue HTTP calls. Platform scope is wrong for them — it
     * leaves no tenant to inherit — so they hold a specific tenant instead.
     */
    protected function holdingTenant(string $tenantId): void
    {
        $this->heldTenantId = $tenantId;
        app(TenantContext::class)->setTenantId($tenantId);
    }

    /**
     * Re-establish the cross-tenant read after each request, for tests that opted into it.
     *
     * The request teardown clears the context — correctly — so a single call in `setUp` would only
     * survive until the first HTTP call. This re-applies the test's own declaration rather than
     * weakening the teardown, so a test that did NOT opt in still gets the real behaviour and a
     * genuine scope bug still fails.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        if ($this->heldTenantId !== null) {
            app(TenantContext::class)->setTenantId($this->heldTenantId);
        } elseif ($this->readsAcrossTenants) {
            app(TenantContext::class)->enterPlatformScope();
        }

        return $response;
    }
}
