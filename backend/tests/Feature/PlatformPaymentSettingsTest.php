<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment gateways as an operator configures them (PAYSET-001).
 *
 * The surface answers one question — "what does this install actually support?" — and the tests below
 * are all about it answering honestly: an incomplete provider is shown as incomplete rather than as
 * an option somebody could select, sandbox keys are reported as sandbox, and no secret ever leaves
 * the server.
 */
final class PlatformPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private ?User $owner = null;

    /** Memoised: several tests read the page twice, and a second `create` on the same email throws. */
    private function owner(): User
    {
        if ($this->owner !== null) {
            return $this->owner;
        }

        $user = User::create(['name' => 'Owner', 'email' => 'owner@paysettings.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $this->owner = $user->refresh();
    }

    private function settings(): array
    {
        return $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments')
            ->assertOk()->json('data');
    }

    // ── Reach ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_page_is_the_platform_owners_alone(): void
    {
        $customer = User::create(['name' => 'C', 'email' => 'c@paysettings.test', 'password' => 'secret1234']);

        $this->getJson('/api/v1/admin/settings/integrations/payments')->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments')->assertForbidden();
    }

    // ── Honest states ─────────────────────────────────────────────────────────────────────────

    /**
     * With nothing configured, both gateways say so — and Moyasar is still named as the primary.
     *
     * Which provider is OFFICIAL is a product decision, not a consequence of which one somebody
     * happened to supply keys for first.
     */
    public function test_an_unconfigured_install_shows_both_gateways_as_awaiting_credentials(): void
    {
        config([
            'services.moyasar.secret_key' => null, 'services.moyasar.webhook_token' => null,
            'services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null,
        ]);

        $data = $this->settings();
        $providers = collect($data['providers']);

        $this->assertSame(['moyasar', 'stripe'], $providers->pluck('provider')->all());
        $this->assertSame('primary', $providers->firstWhere('provider', 'moyasar')['role']);
        $this->assertSame('alternative', $providers->firstWhere('provider', 'stripe')['role']);

        foreach ($providers as $provider) {
            $this->assertSame('awaiting_credentials', $provider['status']);
            $this->assertFalse($provider['available']);
            $this->assertSame('unset', $provider['environment']);
        }
    }

    /**
     * A HALF-configured provider is not available.
     *
     * A secret key with no webhook secret could open a checkout that nothing is able to confirm: the
     * customer is charged and no account ever activates. Showing that as usable is the worst kind of
     * dishonesty this page could commit.
     */
    public function test_a_provider_missing_its_webhook_secret_is_not_available(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_abc', 'services.moyasar.webhook_token' => null]);

        $moyasar = collect($this->settings()['providers'])->firstWhere('provider', 'moyasar');

        $this->assertSame('awaiting_credentials', $moyasar['status']);
        $this->assertFalse($moyasar['available']);

        // …and the page says exactly which piece is missing, rather than "not configured".
        $requirements = collect($moyasar['requires'])->pluck('present', 'key');
        $this->assertTrue($requirements['MOYASAR_SECRET_KEY']);
        $this->assertFalse($requirements['MOYASAR_WEBHOOK_TOKEN']);
    }

    /**
     * Sandbox or live is read from the KEY, never from a separate toggle.
     *
     * A toggle that could disagree with the key in use is how an operator ends up certain they are in
     * sandbox while taking real money.
     */
    public function test_the_environment_is_read_from_the_key_itself(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_abc', 'services.moyasar.webhook_token' => 'tok']);
        $this->assertSame('sandbox', collect($this->settings()['providers'])->firstWhere('provider', 'moyasar')['environment']);

        config(['services.moyasar.secret_key' => 'sk_live_abc']);
        $this->assertSame('live', collect($this->settings()['providers'])->firstWhere('provider', 'moyasar')['environment']);
    }

    /** No secret ever reaches the browser — only whether one is present. */
    public function test_no_secret_is_ever_returned(): void
    {
        config([
            'services.moyasar.secret_key' => 'sk_test_SUPERSECRET',
            'services.moyasar.webhook_token' => 'tok_SUPERSECRET',
            'services.stripe.secret_key' => 'sk_test_STRIPESECRET',
        ]);

        $body = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments')->getContent();

        $this->assertStringNotContainsString('SUPERSECRET', (string) $body);
        $this->assertStringNotContainsString('STRIPESECRET', (string) $body);
    }

    // ── Connection test ───────────────────────────────────────────────────────────────────────

    /**
     * Testing an unconfigured provider is refused rather than attempted.
     *
     * "We could not reach the gateway" and "you have not given us a key" are different problems with
     * different fixes, and collapsing them wastes an operator's afternoon.
     */
    public function test_testing_an_unconfigured_provider_says_so_instead_of_failing_to_connect(): void
    {
        config(['services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null]);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/payments/stripe/test')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'awaiting_credentials');
    }

    public function test_an_unknown_provider_is_not_found(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/payments/invented/test')
            ->assertNotFound();
    }

    // ── Webhook and rotation ──────────────────────────────────────────────────────────────────

    /** The operator is given the URL and the scheme — never the secret. */
    public function test_the_webhook_page_gives_the_url_and_the_scheme(): void
    {
        config(['services.moyasar.webhook_token' => 'tok_SUPERSECRET']);

        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments/moyasar/webhook')
            ->assertOk()->json('data');

        $this->assertStringEndsWith('/api/v1/payments/webhook/moyasar', $data['url']);
        $this->assertContains('payment_paid', $data['events']);
        $this->assertStringNotContainsString('tok_SUPERSECRET', json_encode($data));
    }

    /**
     * Rotation is an environment procedure, and the console says so rather than offering a button.
     *
     * A console able to change a gateway secret is a console whose compromise redirects every
     * customer payment.
     */
    public function test_rotation_is_documented_rather_than_performed(): void
    {
        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments/stripe/rotation')
            ->assertOk()->json('data');

        $this->assertContains('STRIPE_WEBHOOK_SECRET', $data['variables']);
        $this->assertCount(4, $data['steps']);
        $this->assertStringContainsString('never stored', $data['note']);

        // There is deliberately no write endpoint to find.
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/payments/stripe/rotation')
            ->assertStatus(405);
    }

    // ── Mail, because half a payment system is one that cannot tell anybody ───────────────────

    public function test_the_page_also_reports_whether_anybody_can_be_notified(): void
    {
        config(['mail.default' => 'log']);

        $this->assertSame('sandbox', $this->settings()['mail']['state']);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.username' => '']);
        $this->assertSame('awaiting_credentials', $this->settings()['mail']['state']);
    }

    // ── Whether renewals take themselves — PAY-TOKEN-003 ─────────────────────────────────────

    /**
     * Two facts, deliberately not collapsed into one.
     *
     * `ready` says the GATEWAY could charge a saved card. `saved_methods` says how many customers
     * actually have one — and it is the second number that tells an operator whether the capability
     * is doing anything at all. «Ready» beside a count of zero is the true state of a fresh install:
     * nothing is renewing itself yet, and a single boolean would have hidden that either way round.
     */
    public function test_the_page_says_whether_renewals_can_be_taken_and_how_many_cards_exist(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_token' => 'tok']);
        config(['subscriptions.default' => 'moyasar']);

        $recurring = $this->settings()['recurring'];

        $this->assertTrue($recurring['ready']);
        $this->assertSame('moyasar', $recurring['provider']);
        $this->assertSame(0, $recurring['saved_methods'], 'no customer has a card yet, and the page must say so');
    }

    /** With no gateway, the reason names the gateway rather than blaming a customer. */
    public function test_the_page_names_the_gateway_when_no_renewal_can_be_taken(): void
    {
        config(['services.moyasar.secret_key' => null, 'services.moyasar.webhook_token' => null]);
        config(['subscriptions.default' => 'moyasar']);

        $recurring = $this->settings()['recurring'];

        $this->assertFalse($recurring['ready']);
        $this->assertSame('no_gateway', $recurring['reason']);
    }
}
