<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Platform\Services\ProductionReadiness;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PAY-MOYASAR-ACTIVATION-001 — `production:check` states each Moyasar variable as Configured or Missing, and the shape problems an
 * operator can make while pasting them, without ever printing a value.
 *
 * It used to say nothing about Moyasar while the gateway was `sandbox` — and nothing either when all three were present and valid —
 * so silence could not tell «missing» from «fine».
 */
final class MoyasarCredentialStatusTest extends TestCase
{
    // Assembled at runtime: a literal shaped like a live key is refused by GitHub's secret scanning, rightly.
    private const BODY = 'placeholder-key-body-for-tests-only-0000';

    private const SECRET = 'sk_'.'live_'.self::BODY;

    private const PUBLISHABLE = 'pk_'.'live_'.self::BODY;

    private function production(array $over = []): array
    {
        config(array_merge([
            'app.env' => 'production', 'subscriptions.default' => 'sandbox',
            'services.moyasar.secret_key' => null, 'services.moyasar.publishable_key' => null, 'services.moyasar.webhook_token' => null,
        ], $over));

        return (new ProductionReadiness)->run();
    }

    /** @return array<string, string> */
    private function statuses(array $report): array
    {
        return array_column($report['credentials'], 'status', 'key');
    }

    /** @return list<string> */
    private function warnedKeys(array $report): array
    {
        return array_values(array_map(fn (array $f) => $f['key'], array_filter($report['findings'], fn (array $f) => $f['level'] === 'warn')));
    }

    public function test_each_variable_is_reported_missing_while_nothing_is_entered(): void
    {
        $this->assertSame([
            'SUBSCRIPTION_PROVIDER' => 'sandbox',
            'MOYASAR_PUBLISHABLE_KEY' => 'missing',
            'MOYASAR_SECRET_KEY' => 'missing',
            'MOYASAR_WEBHOOK_TOKEN' => 'missing',
        ], $this->statuses($this->production()));
    }

    public function test_a_complete_live_set_is_configured_and_raises_no_shape_warning(): void
    {
        $report = $this->production([
            'subscriptions.default' => 'moyasar', 'services.moyasar.secret_key' => self::SECRET,
            'services.moyasar.publishable_key' => self::PUBLISHABLE, 'services.moyasar.webhook_token' => 'Campaigns-Hub-2026-Webhook-Token',
        ]);

        $this->assertSame(['SUBSCRIPTION_PROVIDER' => 'moyasar', 'MOYASAR_PUBLISHABLE_KEY' => 'configured', 'MOYASAR_SECRET_KEY' => 'configured', 'MOYASAR_WEBHOOK_TOKEN' => 'configured'], $this->statuses($report));
        $this->assertSame([], array_values(array_filter($this->warnedKeys($report), fn (string $k) => str_starts_with($k, 'services.moyasar'))));
    }

    public function test_a_truncated_key_and_a_non_ascii_token_are_named_without_their_values(): void
    {
        $report = $this->production([
            'subscriptions.default' => 'moyasar', 'services.moyasar.secret_key' => 'sk_'.'live_short123',
            'services.moyasar.publishable_key' => self::PUBLISHABLE, 'services.moyasar.webhook_token' => 'Token^٧٥Hub-2026-long-enough',
        ]);

        $this->assertContains('services.moyasar.secret_key.format', $this->warnedKeys($report));
        $this->assertContains('services.moyasar.webhook_token.format', $this->warnedKeys($report));
        $this->assertStringNotContainsString('sk_'.'live_short123', json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('٧٥', json_encode($report, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_key_without_moyasars_prefix_is_named(): void
    {
        $report = $this->production(['services.moyasar.secret_key' => 'live_'.str_repeat('x', 40), 'services.moyasar.publishable_key' => self::PUBLISHABLE, 'services.moyasar.webhook_token' => 'Campaigns-Hub-2026-Webhook-Token']);

        $this->assertContains('services.moyasar.secret_key.format', $this->warnedKeys($report));
    }

    public function test_the_command_prints_the_statuses_and_never_a_value(): void
    {
        config(['app.env' => 'production', 'subscriptions.default' => 'moyasar', 'services.moyasar.secret_key' => self::SECRET,
            'services.moyasar.publishable_key' => self::PUBLISHABLE, 'services.moyasar.webhook_token' => 'Campaigns-Hub-2026-Webhook-Token']);

        Artisan::call('production:check');
        $out = Artisan::output();

        $this->assertStringContainsString('MOYASAR_SECRET_KEY', $out);
        $this->assertStringContainsString('configured', $out);
        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString(self::PUBLISHABLE, $out);
        $this->assertStringNotContainsString('Campaigns-Hub-2026-Webhook-Token', $out);
    }
}
