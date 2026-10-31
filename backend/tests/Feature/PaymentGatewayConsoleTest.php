<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAY-GATEWAY-CONSOLE-001 — payment gateways configured from the platform console, with no SSH and no restart.
 *
 * Saving keys, testing, enabling (live separately confirmed) and choosing the primary are separate acts; secrets are stored
 * encrypted and never returned; a gateway is enabled only after a passing test with exactly the keys and mode now stored; a switch
 * of primary sends NEW payments to the new gateway and moves nothing that exists.
 */
final class PaymentGatewayConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/integrations/payments';

    // Assembled at runtime: a literal shaped like a live key is refused by GitHub's secret scanning, rightly.
    private const BODY = 'placeholder-key-body-for-tests-only-QzX7';

    /** What Moyasar answers next; read at request time because `Http::fake` merges stubs and the first match wins. */
    private array $moyasarAnswer = [['id' => 'inv_test', 'url' => 'https://checkout.moyasar.com/i/inv_test', 'status' => 'initiated'], 201];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.moyasar.secret_key' => null, 'services.moyasar.publishable_key' => null, 'services.moyasar.webhook_token' => null,
            'services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null, 'services.tap.secret_key' => null,
            'subscriptions.default' => 'sandbox',
        ]);
        Http::fake(['api.moyasar.com/*' => fn () => Http::response(...$this->moyasarAnswer)]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner-'.uniqid().'@gw.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user;
    }

    private function moyasar(string $mode = 'test'): array
    {
        return ['secret_key' => 'sk_'.$mode.'_'.self::BODY, 'publishable_key' => 'pk_'.$mode.'_public-body-for-tests-only-PUBK', 'webhook_token' => 'Webhook-Token-0123456789', 'mode' => $mode];
    }

    private function describe(User $admin, string $gateway): array
    {
        return collect($this->actingAs($admin, 'sanctum')->getJson(self::URL)->assertOk()->json('data.providers'))->firstWhere('provider', $gateway);
    }

    public function test_only_a_platform_admin_can_store_enable_or_switch(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@gw.test', 'password' => 'secret1234']);

        $this->actingAs($user, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar())->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertForbidden();
        $this->actingAs($user, 'sanctum')->putJson(self::URL.'/primary', ['gateway' => 'moyasar', 'confirm' => true])->assertForbidden();
        $this->assertSame(0, ProviderConfiguration::query()->count());
    }

    public function test_keys_are_stored_encrypted_never_returned_and_audited_by_name_only(): void
    {
        $admin = $this->admin();
        $keys = $this->moyasar();

        $response = $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $keys)->assertOk();
        $listing = $this->actingAs($admin, 'sanctum')->getJson(self::URL)->assertOk();

        foreach ([$response->getContent(), $listing->getContent()] as $body) {
            $this->assertStringNotContainsString($keys['secret_key'], $body);
            $this->assertStringNotContainsString($keys['webhook_token'], $body);
            $this->assertStringNotContainsString(substr($keys['secret_key'], -4), $body);
        }

        $raw = (string) DB::table('provider_configurations')->where('provider', 'payment.moyasar')->value('credentials');
        $this->assertStringNotContainsString(self::BODY, $raw, 'encrypted at rest');

        $audit = AuditLog::query()->where('action', 'platform.payments.credentials_saved')->sole();
        $this->assertStringNotContainsString(self::BODY, json_encode($audit->getAttributes()));
        $this->assertSame(['secret_key', 'publishable_key', 'webhook_token'], $audit->after['fields']);

        $moyasar = $this->describe($admin, 'moyasar');
        $this->assertSame('needs_test', $moyasar['status']);
        $this->assertSame('console', $moyasar['source']);
        $this->assertNull(collect($moyasar['fields'])->firstWhere('key', 'secret_key')['value']);
        $this->assertTrue(collect($moyasar['fields'])->firstWhere('key', 'secret_key')['present']);
    }

    public function test_a_blank_field_keeps_the_stored_value_and_a_new_one_replaces_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar())->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', ['secret_key' => '', 'webhook_token' => 'Rotated-Token-9876543210'])->assertOk();

        $settings = app(PaymentGatewaySettings::class);
        $this->assertSame('sk_test_'.self::BODY, $settings->value('moyasar', 'secret_key'));
        $this->assertSame('Rotated-Token-9876543210', $settings->value('moyasar', 'webhook_token'));
    }

    public function test_a_key_that_contradicts_the_mode_is_refused(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::URL.'/moyasar', [...$this->moyasar('live'), 'mode' => 'test'])
            ->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_enabling_needs_a_passing_test_with_the_current_keys_and_a_rotation_makes_it_stale(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar())->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/test')->assertOk()->assertJsonPath('data.reachable', true);
        $this->assertTrue($this->describe($admin, 'moyasar')['test']['current']);

        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', ['webhook_token' => 'Another-Token-1122334455'])->assertOk();
        $this->assertFalse($this->describe($admin, 'moyasar')['test']['current'], 'a test passed on the old keys is not a test of the new ones');
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/test')->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertOk()
            ->assertJsonPath('data.provider.status', 'enabled');
    }

    public function test_a_failed_test_is_recorded_with_the_gateways_reason_and_enables_nothing(): void
    {
        $this->moyasarAnswer = [['message' => 'Invalid authorization credentials'], 401];
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar())->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/test')->assertOk()->assertJsonPath('data.reachable', false);

        $moyasar = $this->describe($admin, 'moyasar');
        $this->assertSame('failed', $moyasar['test']['status']);
        $this->assertNotEmpty($moyasar['test']['message']);
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertStatus(422);
    }

    public function test_live_mode_is_enabled_only_with_an_explicit_confirmation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar('live'))->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/test')->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_live');
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true, 'confirm_live' => true])->assertOk();
    }

    public function test_the_primary_is_an_enabled_gateway_and_switching_it_takes_effect_on_the_next_checkout_without_a_restart(): void
    {
        $admin = $this->admin();
        $registry = app(SubscriptionProviderRegistry::class);
        $this->assertSame('sandbox', $registry->defaultKey(), 'the environment decides until the console chooses');

        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/primary', ['gateway' => 'moyasar', 'confirm' => true])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', $this->moyasar())->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/test')->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => true])->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/primary', ['gateway' => 'moyasar', 'confirm' => true])->assertOk()
            ->assertJsonPath('data.primary', 'moyasar');

        $this->assertSame('moyasar', $registry->defaultKey());
        $this->assertTrue($registry->default()->isConfigured(), 'the adapter reads the stored keys on this very request');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.payments.primary_changed']);

        // The primary cannot be switched off from under new checkouts.
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/moyasar/activation', ['enabled' => false])->assertStatus(422);
    }

    public function test_an_environment_configured_install_keeps_working_until_a_key_is_saved(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_'.self::BODY, 'services.moyasar.webhook_token' => 'Env-Token-0123456789']);
        $admin = $this->admin();

        $moyasar = $this->describe($admin, 'moyasar');
        $this->assertSame('environment', $moyasar['source']);
        $this->assertTrue($moyasar['enabled']);
        $this->assertSame(0, ProviderConfiguration::query()->count(), 'reading the page writes nothing');

        // Once a key is saved, the row is the only source — the environment value is never mixed in.
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/moyasar', ['webhook_token' => 'Console-Token-0123456789'])->assertOk();
        $settings = app(PaymentGatewaySettings::class);
        $this->assertSame('console', $settings->source('moyasar'));
        $this->assertSame('sk_test_'.self::BODY, $settings->value('moyasar', 'secret_key'), 'carried over from the environment once, then stored');
        config(['services.moyasar.secret_key' => 'sk_test_changed_'.self::BODY]);
        $this->assertSame('sk_test_'.self::BODY, $settings->value('moyasar', 'secret_key'));
    }

    public function test_a_gateway_with_no_adapter_cannot_take_keys_or_be_tested(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/streampay', ['api_key' => 'x'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/streampay/test')->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/nope', [])->assertNotFound();
        $streampay = $this->describe($admin, 'streampay');
        $this->assertSame('not_implemented', $streampay['status']);
        $this->assertStringContainsString('product', $streampay['note']['en'], 'the reason is stated, not hidden');
    }

    /**
     * Keys alone do not make a gateway a place money can go. Found by the E2E gate: Stripe keys saved (not enabled) made Stripe the
     * «available» gateway the registration page showed, and a caller naming it would have opened a charge on it.
     */
    public function test_a_gateway_with_keys_but_not_enabled_is_neither_available_nor_chosen_at_checkout(): void
    {
        config(['subscriptions.default' => 'sandbox']);
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL.'/stripe', ['secret_key' => 'sk_test_'.self::BODY, 'webhook_secret' => 'whsec_'.self::BODY, 'mode' => 'test'])->assertOk();

        $stripe = collect($this->getJson('/api/v1/payments/providers')->assertOk()->json('data.providers'))->firstWhere('provider', 'stripe');
        $this->assertFalse($stripe['available'], 'stored keys, not enabled');

        $checkout = new \ReflectionMethod(SubscriptionCheckout::class, 'mayOpenOn');
        $this->assertFalse($checkout->invoke(app(SubscriptionCheckout::class), 'stripe'));
        $this->assertFalse($checkout->invoke(app(SubscriptionCheckout::class), 'no-such-gateway'));
    }
}
