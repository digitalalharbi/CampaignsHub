<?php

namespace Tests;

use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\GrantsMemberships;

abstract class TestCase extends BaseTestCase
{
    use GrantsMemberships;

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

    /**
     * Tests run in the application's OWN default language (I18N-001).
     *
     * Symfony's `Request::create()` — which every `getJson`/`postJson` goes through — supplies
     * `Accept-Language: en-us,en;q=0.5` whether or not the test asked for it. So the whole suite was
     * silently exercising the English path, and would have gone on reporting green over an Arabic
     * default nobody had ever run: this was caught only because a message that should have changed
     * did not.
     *
     * Clearing it makes an unstated language mean the product default, which is what a webhook or a
     * curl actually sends. A test that cares about a specific language still says so with a header,
     * and that is then a real statement rather than an accident of the test harness.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => '']);
    }

    /**
     * Exercise the influencers & UGC sub-system, which ships switched off (INFL-OFF-001).
     *
     * The sub-system is intact — every table, service, controller and one of these tests. What the
     * flag withdraws is the OFFER, and a test of the roster or the nomination queue is not a test of
     * whether the platform is currently selling it. So those tests turn it on and go on proving the
     * thing they were written to prove, which is exactly what makes turning it back on a decision
     * rather than a rebuild: the day the flag flips, this suite already says whether it works.
     *
     * Tests that assert the sub-system is CLOSED deliberately do not call this.
     */
    protected function withInfluencersEnabled(): void
    {
        config()->set('brand.features.influencers_ugc_enabled', true);
    }

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
