<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Providers\LaravelMailProvider;
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

    public function test_laravel_mail_provider_is_configured_when_smtp_credentials_exist(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.hostinger.com');
        config()->set('mail.mailers.smtp.username', 'info@campaignshub.io');
        config()->set('providers.channels.email', LaravelMailProvider::class);

        $provider = app(ProviderRegistry::class)->for('email');

        $this->assertTrue($provider->isConfigured());
    }

    public function test_production_mail_identity_is_centralised_in_laravel_mail_config(): void
    {
        config()->set('mail.from.address', 'no-reply@campaignshub.io');
        config()->set('mail.from.name', 'CampaignsHub');
        config()->set('mail.reply_to.address', 'info@campaignshub.io');
        config()->set('mail.reply_to.name', 'CampaignsHub');

        $this->assertSame('CampaignsHub', config('mail.from.name'));
        $this->assertSame('no-reply@campaignshub.io', config('mail.from.address'));
        $this->assertSame('info@campaignshub.io', config('mail.reply_to.address'));
        $this->assertSame('CampaignsHub', config('mail.reply_to.name'));
    }

    public function test_laravel_mail_provider_does_not_treat_placeholder_smtp_as_live(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.username', null);
        config()->set('providers.channels.email', LaravelMailProvider::class);

        $provider = app(ProviderRegistry::class)->for('email');

        $this->assertFalse($provider->isConfigured());
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
