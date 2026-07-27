<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Notifications\Providers\NullEmailProvider;
use App\Domains\Notifications\Providers\NullSmsProvider;
use App\Domains\Notifications\Providers\NullWhatsAppProvider;
use App\Domains\Notifications\Providers\ProviderRegistry;
use Tests\TestCase;

/**
 * The delivery-provider layer is honest by construction: default adapters report "not configured" and never
 * claim a send. A channel only reports "sent" when a configured provider returns success — proven by binding
 * a fake configured provider.
 */
final class ProviderAdaptersTest extends TestCase
{
    public function test_default_channels_are_the_null_adapters_and_report_not_configured(): void
    {
        $registry = app(ProviderRegistry::class);
        foreach (['email', 'whatsapp', 'sms'] as $channel) {
            $provider = $registry->for($channel);
            $this->assertSame($channel, $provider->channel());
            $this->assertFalse($provider->isConfigured(), "{$channel} must be unconfigured by default");

            $result = $provider->send('someone@example.test', ['body' => 'x']);
            $this->assertSame('awaiting_credentials', $result['status']);
            $this->assertNull($result['provider_message_id'] ?? null);
        }
    }

    public function test_config_defaults_point_at_null_adapters(): void
    {
        // A static guarantee: the shipped config never points a channel at a "sending" provider.
        $this->assertSame(NullEmailProvider::class, config('providers.channels.email'));
        $this->assertSame(config('providers.defaults'), [
            'email' => NullEmailProvider::class,
            'whatsapp' => NullWhatsAppProvider::class,
            'sms' => NullSmsProvider::class,
        ]);
    }

    public function test_a_configured_provider_reports_sent_with_a_documented_ack(): void
    {
        // Bind a fake *configured* provider for email to prove the registry surfaces real results.
        config()->set('providers.channels.email', ConfiguredFakeEmailProvider::class);

        $provider = app(ProviderRegistry::class)->for('email');
        $this->assertTrue($provider->isConfigured());
        $result = $provider->send('someone@example.test', ['body' => 'x']);
        $this->assertSame('sent', $result['status']);
        $this->assertSame('fake-ack-123', $result['provider_message_id']);
    }
}

/** A stand-in for a real, credentialed provider — used only to prove the honest-status mapping. */
final class ConfiguredFakeEmailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'fake-ack-123', 'error' => null];
    }
}
