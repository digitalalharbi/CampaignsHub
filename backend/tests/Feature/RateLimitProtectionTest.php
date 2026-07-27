<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ConditionalThrottle;
use Tests\TestCase;

/**
 * Rate limiting is a production/staging security control. It may be relaxed ONLY for a local E2E run, via the
 * explicit E2E_RELAX_RATE_LIMITS flag — and even a mis-set flag can never disable the limits in production or
 * staging. These tests lock that invariant.
 */
final class RateLimitProtectionTest extends TestCase
{
    public function test_production_never_relaxes_even_with_the_flag_on(): void
    {
        $this->app['env'] = 'production';
        config()->set('security.relax_rate_limits', true); // mis-set on purpose
        $this->assertFalse(ConditionalThrottle::relaxationAllowed(), 'Production must always enforce throttling.');
    }

    public function test_staging_never_relaxes_even_with_the_flag_on(): void
    {
        $this->app['env'] = 'staging';
        config()->set('security.relax_rate_limits', true); // mis-set on purpose
        $this->assertFalse(ConditionalThrottle::relaxationAllowed(), 'Staging must always enforce throttling.');
    }

    public function test_local_relaxes_only_when_explicitly_enabled(): void
    {
        $this->app['env'] = 'local';

        config()->set('security.relax_rate_limits', false);
        $this->assertFalse(ConditionalThrottle::relaxationAllowed(), 'Default (flag off) keeps limits on.');

        config()->set('security.relax_rate_limits', true);
        $this->assertTrue(ConditionalThrottle::relaxationAllowed(), 'Local E2E with the explicit flag relaxes.');
    }

    public function test_testing_env_keeps_limits_active_even_with_the_flag_on(): void
    {
        // PHPUnit runs as `testing`; limits stay active (and testable) regardless of the flag.
        $this->app['env'] = 'testing';
        config()->set('security.relax_rate_limits', true);
        $this->assertFalse(ConditionalThrottle::relaxationAllowed());
    }
}
