<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The console's production evidence: schema, routes and state read from inside the install — and never a secret.
 */
final class PlatformConsoleEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function report(): array
    {
        Artisan::call('platform:console-evidence', ['--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_reports_each_requirement_with_its_routes_and_schema(): void
    {
        $report = $this->report();

        $this->assertTrue($report['requirements']['SUPERADMIN-WORKSPACE-001']['deployed']);
        $this->assertTrue($report['requirements']['SUPERADMIN-ACCESS-001']['deployed']);
        $this->assertTrue($report['requirements']['PAY-GATEWAY-CONSOLE-001']['deployed']);
        $this->assertContains(['route' => 'POST api/v1/admin/users/{user}/access', 'present' => true], $report['requirements']['SUPERADMIN-ACCESS-001']['checks']);
        $this->assertEqualsCanonicalizing(['primary', 'moyasar', 'tap', 'streampay', 'hyperpay', 'paytabs', 'stripe'], array_keys($report['state']['payment_gateways']));
    }

    public function test_a_stored_gateway_key_is_reported_as_a_state_never_as_its_value(): void
    {
        $secret = 'sk_'.'test_'.str_repeat('Q7', 16);
        app(PaymentGatewaySettings::class)->save('moyasar', ['secret_key' => $secret, 'webhook_token' => 'tok-'.str_repeat('Z', 20)], 'test', null);

        Artisan::call('platform:console-evidence', ['--json' => true]);
        $out = Artisan::output();

        $this->assertStringNotContainsString($secret, $out);
        $this->assertStringNotContainsString(str_repeat('Z', 20), $out);
        $moyasar = json_decode($out, true)['state']['payment_gateways']['moyasar'];
        $this->assertTrue($moyasar['configured']);
        $this->assertSame('console', $moyasar['source']);
        $this->assertFalse($moyasar['enabled']);
    }
}
