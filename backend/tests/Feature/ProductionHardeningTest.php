<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Services\ContactVerificationService;
use Tests\TestCase;

/**
 * Production safety invariants. The dev-only testability hatches (OTP dev_code, portal dev_token, invitation
 * dev_link) MUST NOT be surfaceable in production — not even if a stray config/env flag is left enabled. This
 * locks the hard gate so it can never silently regress.
 */
final class ProductionHardeningTest extends TestCase
{
    public function test_dev_secrets_are_never_exposed_in_production_even_with_the_flag_on(): void
    {
        config()->set('requests.verification.expose_dev_code', true); // the escape hatch is explicitly ON
        $this->app['env'] = 'production';                              // …but we are in production

        $this->assertFalse(
            ContactVerificationService::exposeDevSecrets(),
            'Dev secrets must be hard-gated off in production regardless of config.',
        );
    }

    public function test_dev_secrets_may_be_exposed_off_production_when_the_flag_is_on(): void
    {
        config()->set('requests.verification.expose_dev_code', true);
        $this->app['env'] = 'local';

        $this->assertTrue(ContactVerificationService::exposeDevSecrets());
    }

    public function test_dev_secrets_stay_off_when_the_flag_is_off(): void
    {
        config()->set('requests.verification.expose_dev_code', false);
        $this->app['env'] = 'local';

        $this->assertFalse(ContactVerificationService::exposeDevSecrets());
    }
}
