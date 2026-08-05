<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;
use App\Domains\Integrations\ValueObjects\HealthResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared contract every advertising connector must satisfy. Runs against ALL registered connectors
 * so a new provider is automatically held to the same rules.
 *
 * It boots the framework (INTEG-OAUTH-001) because the six real connectors now read
 * `config/ad_platforms.php` to decide whether they may call out at all. That is not test scaffolding
 * leaking into production code — it IS the production rule: a platform is configured or it is not,
 * and configuration is the only place that answer can honestly live. Without the container this
 * suite asserted the contract against connectors that could not read their own configuration.
 */
final class ConnectorContractTest extends TestCase
{
    /** @return array<string, array{0: AdvertisingConnector}> */
    public static function connectors(): array
    {
        $registry = new AdvertisingConnectorRegistry(includeSandbox: true);

        return array_map(fn (AdvertisingConnector $c) => [$c], $registry->all());
    }

    #[DataProvider('connectors')]
    public function test_connector_exposes_identity(AdvertisingConnector $connector): void
    {
        $this->assertNotEmpty($connector->key());
        $this->assertNotEmpty($connector->label());
        $this->assertInstanceOf(ConnectorStatus::class, $connector->status());
        $this->assertInstanceOf(HealthResult::class, $connector->healthCheck());
    }

    #[DataProvider('connectors')]
    public function test_awaiting_connectors_do_not_fabricate_data(AdvertisingConnector $connector): void
    {
        if ($connector->status() !== ConnectorStatus::AwaitingCredentials) {
            $this->assertTrue(true); // not applicable to connected connectors

            return;
        }

        $this->assertSame([], $connector->listAdAccounts());
        $this->assertFalse($connector->syncCampaigns('acc')->success);
        $this->assertFalse($connector->healthCheck()->healthy);
    }

    public function test_sandbox_returns_deterministic_labelled_data(): void
    {
        $sandbox = new SandboxAdvertisingConnector;

        $this->assertSame(ConnectorStatus::Connected, $sandbox->status());
        $accounts = $sandbox->listAdAccounts();
        $this->assertNotEmpty($accounts);
        $this->assertTrue($accounts[0]['sandbox']);

        $sync = $sandbox->syncCampaigns('sandbox-act-1');
        $this->assertTrue($sync->success);
        $this->assertSame(2, $sync->count);
        foreach ($sync->records as $record) {
            $this->assertTrue($record['sandbox'], 'sandbox records must be clearly marked');
        }
    }
}
