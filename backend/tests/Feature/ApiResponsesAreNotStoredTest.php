<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-CACHE-NO-STORE-001 — a per-user answer must not be storable.
 *
 * `registration-onboarding.spec.ts` intermittently put a freshly-registered account on `/switch`
 * instead of the wizard, on Firefox only, on two unrelated pull requests. Reproduced locally — once in
 * twenty-one runs — and its own diagnostic named the mechanism: `/auth/memberships answered [GET 304,
 * GET 401, GET 401, GET 401]`. A 304 means the browser had STORED one user's membership list and
 * revalidated against it, and `/switch` then offered «you belong to more than one space» to an account
 * that belongs to one.
 *
 * Nothing had ever said those responses were not cacheable. Laravel's own default is `no-cache,
 * private`, and `no-cache` permits storing and revalidating — which is precisely the 304. Only
 * `no-store` forbids keeping the copy.
 *
 * The worst case is not a flaky test. It is one person's workspace list surviving in a cache that a
 * later response is served from.
 */
final class ApiResponsesAreNotStoredTest extends TestCase
{
    use RefreshDatabase;

    private function anOperator(): User
    {
        $tenant = Tenant::create(['name' => 'NS', 'slug' => 'ns-'.uniqid(), 'status' => 'active']);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@ns.local',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant);

        return $user;
    }

    /** The endpoint whose cached copy caused the failure. */
    public function test_the_memberships_answer_may_not_be_stored(): void
    {
        $response = $this->actingAs($this->anOperator(), 'sanctum')->getJson('/api/v1/auth/memberships');

        $response->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** And the identity it is read beside. */
    public function test_the_identity_answer_may_not_be_stored(): void
    {
        $response = $this->actingAs($this->anOperator(), 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * A REFUSAL must not be stored either.
     *
     * A cached 401 is the same defect facing the other way: a browser that keeps one would refuse a
     * session that is perfectly valid, and the reproduced failure showed three consecutive 401s after
     * a login that answered 200.
     */
    public function test_a_refusal_may_not_be_stored(): void
    {
        $response = $this->getJson('/api/v1/auth/memberships');

        $response->assertUnauthorized();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * `no-cache` on its own was never the rule, and asserting it would have passed while the defect
     * stood: it permits STORING the response and revalidating it, which is the 304 that was observed.
     */
    public function test_no_cache_alone_does_not_satisfy_this(): void
    {
        $header = (string) $this->actingAs($this->anOperator(), 'sanctum')
            ->getJson('/api/v1/auth/me')->headers->get('Cache-Control');

        self::assertNotSame('no-cache, private', $header);
        self::assertStringContainsString('no-store', $header);
    }

    /**
     * A response that means to be cached keeps its own mind.
     *
     * The public paid-media catalogue sets an ETag and a `Cache-Control` deliberately — it SHOULD be
     * cached and revalidated. This rule fills a silence; it does not overrule a decision.
     */
    public function test_a_deliberately_cacheable_public_answer_is_left_alone(): void
    {
        $response = $this->getJson('/api/v1/public/catalog/paid-media-services');

        $response->assertOk();
        self::assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
