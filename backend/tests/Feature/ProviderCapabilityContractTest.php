<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Providers\ApiCommerceConnector;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Catalogue\ProviderAuth;
use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\ProviderField;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Catalogue\ResourceType;
use App\Domains\Integrations\Catalogue\WebhookVerification;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Contracts\ReadsIncrementally;
use App\Domains\Integrations\Exceptions\CapabilityNotDeclared;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\ReportsCreativeInsights;
use App\Domains\Integrations\Providers\ReportsEntityGrains;
use App\Domains\Integrations\Providers\ReportsPeriodReach;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Webhooks\WebhookSignature;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * INTEG-CAPABILITY-CONTRACT-001 §1.8 — every declaration held to the code it describes.
 *
 * Driven from `ProviderCatalogue::declared()`, so a provider added to the catalogue is in every case
 * below with no edit here, and the discovery cases then fail until it brings a fixture. No provider
 * is called: `Http::fake()` is the wire throughout, and the requests are what is asserted.
 *
 * A declaration is checked in BOTH directions where the code can answer both: claiming what is not
 * implemented, and implementing what was not claimed. The second is what keeps the syncers' remaining
 * `instanceof` checks in step with the catalogue until they are swapped.
 */
final class ProviderCapabilityContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
    }

    /** @return array<string, array{0: string}> */
    public static function providers(): array
    {
        $keys = array_keys(ProviderCatalogue::declared());

        return array_combine($keys, array_map(static fn (string $key): array => [$key], $keys));
    }

    public function test_the_registry_knows_every_provider_the_repository_integrates_with(): void
    {
        $this->assertEqualsCanonicalizing(
            ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'openai_ads', 'ga4', 'salla', 'zid', 'sandbox'],
            array_keys(ProviderCatalogue::declared()),
        );

        $registry = new AdvertisingConnectorRegistry(includeSandbox: true);
        $this->assertEqualsCanonicalizing(array_keys(ProviderCatalogue::declared()), array_keys($registry->adapters()));

        foreach (array_keys($registry->all()) as $connector) {
            $this->assertNotNull(ProviderCatalogue::declaration($connector), "the {$connector} connector is registered with nothing declaring it");
        }

        foreach (app(CommerceConnectorRegistry::class)->keys() as $connector) {
            $this->assertNotNull(ProviderCatalogue::declaration($connector), "the {$connector} store connector is registered with nothing declaring it");
        }
    }

    /** Declared in the code is not the same as present in this build. */
    public function test_a_production_build_has_no_sandbox_adapter_and_the_sandbox_stays_out_of_the_catalogue(): void
    {
        $production = new AdvertisingConnectorRegistry(includeSandbox: false);

        $this->assertNull($production->adapter('sandbox'));
        $this->assertArrayNotHasKey('sandbox', ProviderCatalogue::all());
        $this->assertFalse(ProviderCatalogue::has('sandbox'), 'the setup screens would list a fake provider');
    }

    /** Refused rather than assumed: nothing about an unnamed provider or an undeclared ability is guessed. */
    public function test_an_unknown_provider_answers_nothing(): void
    {
        $registry = app(AdvertisingConnectorRegistry::class);

        foreach (ProviderCapability::cases() as $capability) {
            $this->assertFalse(ProviderCatalogue::supports('not-a-provider', $capability));
            $this->assertFalse($registry->supports('not-a-provider', $capability));
        }

        $this->assertNull($registry->adapter('not-a-provider'));
        $this->assertNull($registry->forDiscovery('not-a-provider'));
        $this->assertNull($registry->periodReach('not-a-provider'));
    }

    /** GA4 and the stores join the contract without becoming something an ad caller can be handed. */
    public function test_the_ad_registry_still_hands_out_only_ad_connectors(): void
    {
        $registry = app(AdvertisingConnectorRegistry::class);

        foreach (['ga4', 'salla', 'zid'] as $key) {
            $this->assertNull($registry->get($key), "{$key} is not an ad platform");
            $this->assertArrayNotHasKey($key, $registry->all());
            $this->assertNotNull($registry->adapter($key));
        }
    }

    /** §5 gate 5: the contract grows only as a versioned change. */
    public function test_the_contract_surface_is_pinned_to_its_version(): void
    {
        $this->assertSame(1, ProviderAdapter::CONTRACT_VERSION);

        $this->assertEqualsCanonicalizing(
            ['key', 'definition', 'supports', 'label', 'status', 'healthCheck', 'discover', 'syncResource'],
            array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(ProviderAdapter::class))->getMethods()),
            'the contract changed: bump CONTRACT_VERSION and say what an existing adapter must now do',
        );
    }

    /** §1.5: the resource noun follows the category, and the stored spelling is the one the code stores. */
    #[DataProvider('providers')]
    public function test_a_category_has_its_own_resource_type(string $key): void
    {
        $definition = $this->definition($key);
        $declared = $definition->capabilities;

        [$category, $resource, $accountType] = match ($definition->kind) {
            ProviderKind::Advertising => ['ads', ResourceType::AdAccount, 'ad_account'],
            ProviderKind::Measurement => ['analytics', ResourceType::Property, 'ga4_property'],
            ProviderKind::Commerce => ['commerce', ResourceType::Store, 'store'],
        };

        $this->assertSame($category, $definition->kind->category());
        $this->assertSame($resource, $declared->resourceType);
        $this->assertSame($accountType, $declared->accountType);
    }

    /** An adapter exists for each provider and answers identity with none of its optional abilities needed. */
    #[DataProvider('providers')]
    public function test_every_adapter_answers_identity_without_any_optional_capability(string $key): void
    {
        $adapter = app(AdvertisingConnectorRegistry::class)->adapter($key);

        $this->assertInstanceOf(ProviderAdapter::class, $adapter);
        $this->assertSame($key, $adapter->key());
        $this->assertNotSame('', $adapter->label());
        $adapter->status();
        $adapter->healthCheck();
    }

    /** Declaring a grain means implementing its interface; implementing one means declaring it. */
    #[DataProvider('providers')]
    public function test_interface_capabilities_match_the_connector_in_both_directions(string $key): void
    {
        $connector = $this->connector($key);
        $definition = $this->definition($key);

        foreach ([
            [ProviderCapability::EntityGrains, ReportsEntityGrains::class],
            [ProviderCapability::CreativeInsights, ReportsCreativeInsights::class],
            [ProviderCapability::PeriodReach, ReportsPeriodReach::class],
            [ProviderCapability::IncrementalCursor, ReadsIncrementally::class],
        ] as [$capability, $interface]) {
            $this->assertSame(
                $definition->supports($capability),
                $connector instanceof $interface,
                "{$key}: the declaration of {$capability->value} disagrees with {$interface}",
            );
        }

        if ($definition->supports(ProviderCapability::PeriodReach)) {
            $this->assertNotSame([], app(AdvertisingConnectorRegistry::class)->periodReach($key)?->periodReachGrains());
        }
    }

    /** Discovery leaves rows of the provider's real resource type, and hierarchy is what those rows carry. */
    #[DataProvider('providers')]
    public function test_discovery_returns_the_real_resource_type_and_the_declared_hierarchy(string $key): void
    {
        $definition = $this->definition($key);
        $adapter = app(AdvertisingConnectorRegistry::class)->adapter($key);

        if (! $definition->supports(ProviderCapability::Discovery)) {
            $this->assertNull(app(AdvertisingConnectorRegistry::class)->forDiscovery($key));
            Http::fake();

            try {
                $adapter->discover(new ProviderConnection);
                $this->fail("{$key} declares no discovery and was not refused");
            } catch (CapabilityNotDeclared) {
                Http::assertNothingSent();
            }

            return;
        }

        $this->configure($key);
        Http::fake(fn (Request $request) => Http::response($this->listing($key, $request->url())));

        $found = $adapter->discover($this->connect($key));

        $this->assertGreaterThan(0, $found->resources->count(), "{$key} declares discovery and discovered nothing");
        $this->assertSame([$definition->capabilities->accountType], $found->accountTypes());
        $this->assertSame(
            $definition->supports(ProviderCapability::Hierarchy),
            $found->anyCarriesAParent(),
            "{$key}: the declared hierarchy disagrees with what discovery recorded",
        );
    }

    /** The contract's sync door keeps the family's gate: discovered is not selected, whichever family. */
    #[DataProvider('providers')]
    public function test_an_unbound_resource_is_refused_through_the_adapter_and_nothing_is_asked(string $key): void
    {
        $definition = $this->definition($key);

        if (! $definition->supports(ProviderCapability::Discovery)) {
            $this->assertFalse($definition->capabilities->discovery);

            return;
        }

        $this->configure($key);
        Http::fake(fn (Request $request) => Http::response($this->listing($key, $request->url())));

        $adapter = app(AdvertisingConnectorRegistry::class)->adapter($key);
        $resource = $adapter->discover($this->connect($key))->resources->firstOrFail();
        $asked = count(Http::recorded());

        $outcome = $adapter->syncResource($resource, Carbon::now()->subDays(2), Carbon::now());

        $this->assertSame(SyncRunStatus::AwaitingAssignment->value, $outcome->status, "{$key} synced a resource nobody selected");
        $this->assertSame(0, $outcome->records);
        $this->assertCount($asked, Http::recorded(), "{$key} called the provider for a resource nobody selected");
    }

    /** An adapter is one provider and one resource type; handing it another's row is refused. */
    public function test_an_adapter_refuses_another_providers_resource(): void
    {
        $this->configure('salla');
        Http::fake(fn (Request $request) => Http::response($this->listing('salla', $request->url())));

        $registry = app(AdvertisingConnectorRegistry::class);
        $store = $registry->adapter('salla')?->discover($this->connect('salla'))->resources->firstOrFail();
        $this->assertNotNull($store);

        foreach (['snapchat', 'ga4', 'zid'] as $other) {
            try {
                $registry->adapter($other)?->syncResource($store, Carbon::now()->subDay(), Carbon::now());
                $this->fail("the {$other} adapter accepted a Salla store");
            } catch (InvalidArgumentException) {
                $this->assertSame(0, IntegrationSyncRun::withoutGlobalScopes()->count());
            }
        }
    }

    /** The commerce adapter reports what `StoreSyncer` wrote on its own run, and nothing of its own. */
    public function test_the_commerce_adapter_reports_the_run_its_syncer_recorded(): void
    {
        $this->configure('salla');
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'store/info')
                ? Http::response($this->listing('salla', $request->url()))
                : Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]);
        });

        $adapter = app(AdvertisingConnectorRegistry::class)->adapter('salla');
        $store = $adapter?->discover($this->connect('salla'))->resources->firstOrFail();
        $this->assertNotNull($store);
        $this->bind($store, 'ecommerce');

        $outcome = $adapter->syncResource($store, Carbon::now()->subDay(), Carbon::now());
        $run = IntegrationSyncRun::withoutGlobalScopes()->where('type', 'commerce')->latest('created_at')->firstOrFail();

        $this->assertSame((string) $run->getRawOriginal('status'), $outcome->status);
        $this->assertSame((int) $run->records, $outcome->records);
        $this->assertSame(SyncRunStatus::NoData->value, $outcome->status);
    }

    /** GA4 joins the contract with its figures still in its own table, never the advertising one. */
    public function test_the_ga4_adapter_syncs_a_bound_property_through_its_own_service(): void
    {
        $this->configure('ga4');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'accountSummaries')) {
                return Http::response($this->listing('ga4', $request->url()));
            }

            return str_contains($request->url(), 'analyticsadmin.googleapis.com')
                ? Http::response(['displayName' => 'Site', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR'])
                : Http::response([
                    'metricHeaders' => [['name' => 'sessions']],
                    'rows' => [['dimensionValues' => [['value' => '20261007']], 'metricValues' => [['value' => '4100']]]],
                ]);
        });

        $adapter = app(AdvertisingConnectorRegistry::class)->adapter('ga4');
        $property = $adapter?->discover($this->connect('ga4'))->resources->firstOrFail();
        $this->assertNotNull($property);
        $this->bind($property, 'analytics');

        $outcome = $adapter->syncResource($property, Carbon::now()->subDays(2), Carbon::now());

        $this->assertSame(SyncRunStatus::Success->value, $outcome->status, (string) $outcome->error);
        $this->assertGreaterThan(0, $outcome->records);
        $this->assertSame($outcome->records, MeasurementDailyMetric::withoutGlobalScopes()->count());
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->count(), 'a GA4 figure reached the advertising table');
    }

    /** A wizard step needs a parent to show, and only the providers that always had one draw it. */
    #[DataProvider('providers')]
    public function test_the_wizard_parent_step_follows_the_declaration(string $key): void
    {
        $declared = $this->definition($key)->capabilities;

        if ($declared->parentStep) {
            $this->assertNotNull($declared->parentLevel);
        }

        $this->assertSame($declared->parentStep, ProviderHierarchy::hasParent($key));
        $this->assertSame($declared->parentStep ? $declared->parentLevel : null, ProviderHierarchy::parent($key));
        $this->assertSame(in_array($key, ['snapchat', 'google'], true), $declared->parentStep, 'a wizard step changed; that is a product decision');
    }

    /**
     * Declared refresh is a real `refresh_token` grant carrying the stored token. Undeclared is proved
     * by offering a stored refresh token anyway: only a protocol with no refresh at all may not use it.
     */
    #[DataProvider('providers')]
    public function test_refresh_is_implemented_exactly_where_it_is_declared(string $key): void
    {
        $definition = $this->definition($key);

        if ($this->hasNoTokenFlow($definition)) {
            $this->assertFalse($definition->supports(ProviderCapability::RefreshToken));
            $this->assertTrue($definition->auth === ProviderAuth::ApiKey || $key === 'sandbox');

            return;
        }

        $this->configure($key);
        Http::fake(['*' => Http::response(['code' => 0, 'access_token' => 'NEW', 'refresh_token' => 'RT2', 'expires_in' => 3600, 'authorization' => 'MGR'])]);

        $credentials = PlatformCredentials::for($key);
        $oauth = app(PlatformOAuth::class);
        $current = new OAuthTokens('AT', 'RT', Carbon::now()->subHour(), raw: ['authorization' => 'MGR']);

        if ($oauth->usesOAuth1($credentials)) {
            $this->assertFalse($definition->supports(ProviderCapability::RefreshToken), 'OAuth 1.0a has no refresh grant');

            return;
        }

        $renewed = $oauth->refresh($credentials, $current);

        if ($definition->supports(ProviderCapability::RefreshToken)) {
            $this->assertSame('NEW', $renewed->accessToken, "{$key} declares refresh and did not renew");
            Http::assertSent(static fn (Request $r): bool => $r->url() === $credentials->tokenUrl()
                && ($r->data()['grant_type'] ?? null) === 'refresh_token'
                && ($r->data()['refresh_token'] ?? null) === 'RT');

            return;
        }

        Http::assertNotSent(static fn (Request $r): bool => ($r->data()['grant_type'] ?? null) === 'refresh_token');
    }

    /** The exchange is its own claim: `fb_exchange_token` where declared, nowhere else. */
    #[DataProvider('providers')]
    public function test_token_exchange_is_implemented_exactly_where_it_is_declared(string $key): void
    {
        $definition = $this->definition($key);

        if ($this->hasNoTokenFlow($definition)) {
            $this->assertFalse($definition->supports(ProviderCapability::TokenExchange));

            return;
        }

        $this->configure($key);
        Http::fake(['*' => Http::response(['code' => 0, 'access_token' => 'NEW', 'expires_in' => 3600, 'authorization' => 'MGR'])]);

        $credentials = PlatformCredentials::for($key);

        if (! app(PlatformOAuth::class)->usesOAuth1($credentials)) {
            app(PlatformOAuth::class)->refresh($credentials, new OAuthTokens('AT', 'RT', Carbon::now()->subHour(), raw: ['authorization' => 'MGR']));
        }

        $exchange = static fn (Request $r): bool => ($r->data()['grant_type'] ?? null) === 'fb_exchange_token';

        $definition->supports(ProviderCapability::TokenExchange) ? Http::assertSent($exchange) : Http::assertNotSent($exchange);
    }

    /**
     * Expiry and renewal have to agree: a token that expires with no way to renew dies silently, and a
     * refresh grant with no expiry is never reached. A token that does not expire is left alone.
     */
    #[DataProvider('providers')]
    public function test_a_declared_expiry_has_a_renewal_and_no_expiry_needs_none(string $key): void
    {
        $definition = $this->definition($key);
        $expires = $definition->supports(ProviderCapability::TokenExpiry);
        $renews = $definition->supports(ProviderCapability::RefreshToken) || $definition->supports(ProviderCapability::TokenExchange);

        $this->assertSame($expires, $renews, "{$key}: token expiry and renewal disagree");

        if ($expires || $key === 'sandbox') {
            return;
        }

        $this->configure($key);
        Http::fake();

        $connection = $this->connect($key, new OAuthTokens('AT', raw: ['token_secret' => 'TS']));
        $this->assertSame('AT', app(TokenVault::class)->fresh($connection)->accessToken);
        Http::assertNothingSent();
    }

    /** A pasted key has no refresh to ask for, in the declaration, the form, or the vault. */
    #[DataProvider('providers')]
    public function test_an_api_key_provider_never_asks_for_a_refresh_token(string $key): void
    {
        $definition = $this->definition($key);

        if ($definition->auth !== ProviderAuth::ApiKey) {
            $this->assertTrue($definition->auth->redirectsToProvider());

            return;
        }

        $this->assertFalse($definition->supports(ProviderCapability::RefreshToken));
        $this->assertFalse($definition->supports(ProviderCapability::TokenExpiry));
        $this->assertFalse($definition->supports(ProviderCapability::TokenExchange));
        $this->assertSame([], $definition->scopes);
        $this->assertFalse($definition->hasRedirectUri());
        $this->assertSame(
            [],
            array_filter($definition->fields, static fn (ProviderField $f): bool => str_contains($f->key, 'refresh')),
        );

        $this->configure($key);
        Http::fake();
        $connection = $this->connect($key, new OAuthTokens('KEY'));

        $this->assertSame('KEY', app(TokenVault::class)->fresh($connection)->accessToken);
        $this->assertSame('connected', $connection->fresh()?->status, 'a key with no refresh token was treated as broken');
        Http::assertNothingSent();
    }

    /** A declared webhook endpoint has a working verifier for the scheme it names, and refuses otherwise. */
    #[DataProvider('providers')]
    public function test_declared_webhooks_have_a_verifier_and_undeclared_ones_are_refused(string $key): void
    {
        $definition = $this->definition($key);
        $scheme = $definition->capabilities->webhookVerification;

        if (! $definition->supports(ProviderCapability::Webhooks)) {
            $this->assertNull($scheme, "{$key} names a verification scheme for webhooks it does not have");
            $this->assertNull($definition->webhookSignatureHeader);
            $this->assertNull($definition->webhookUrl());

            if ($key !== 'sandbox') {
                $this->assertFalse(app(WebhookSignature::class)->check($key, '{}', 'anything')['verified']);
            }

            return;
        }

        $this->assertNotNull($scheme, "{$key} declares webhooks with no verifier");
        $this->assertNotNull($definition->webhookSignatureHeader);
        $this->assertNotNull($definition->webhookUrl());

        $verifier = app(WebhookSignature::class);
        $body = '{"event":"contract"}';

        // No credential configured: refused, whatever is presented. An endpoint that cannot verify is closed.
        $this->assertFalse($verifier->check($key, $body, 'anything')['verified'], "{$key} accepted a delivery with no secret configured");

        $file = $definition->kind->configFile();

        [$fields, $genuine, $forged] = match ($scheme) {
            WebhookVerification::HmacSha256 => [
                ['webhook_secret' => 'contract-secret'],
                hash_hmac('sha256', $body, 'contract-secret'),
                hash_hmac('sha256', $body, 'another-secret'),
            ],
            WebhookVerification::HttpBasic => [
                ['webhook_username' => 'contract-user', 'webhook_password' => 'contract-pass'],
                'Basic '.base64_encode('contract-user:contract-pass'),
                'Basic '.base64_encode('contract-user:wrong'),
            ],
        };

        foreach ($fields as $field => $value) {
            $this->assertNotNull($definition->field($field), "{$key}'s form cannot collect {$field}");
            config()->set("{$file}.platforms.{$key}.{$field}", $value);
        }

        $this->app->forgetScopedInstances();

        $verifier = app(WebhookSignature::class);
        $this->assertTrue($verifier->check($key, $body, $genuine)['verified'], "{$key}: the declared scheme does not verify a genuine delivery");
        $this->assertFalse($verifier->check($key, $body, $forged)['verified'], "{$key}: a forged delivery was accepted");
    }

    /** A declared throttle policy is the retry actually happening: refused once, answered on the second ask. */
    #[DataProvider('providers')]
    public function test_a_declared_rate_limit_policy_retries_a_throttled_call(string $key): void
    {
        $definition = $this->definition($key);

        if (! $definition->supports(ProviderCapability::RateLimitPolicy)) {
            $this->assertNull($definition->capabilities->rateLimit);
            $this->assertFalse($this->connector($key) instanceof ApiAdvertisingConnector, "{$key} calls out with no declared policy");

            return;
        }

        $this->configure($key);
        Sleep::fake();
        $throttled = false;

        Http::fake(function (Request $request) use ($key, &$throttled) {
            if (! $throttled) {
                $throttled = true;

                return Http::response(['error' => 'throttled'], 429, ['Retry-After' => '1']);
            }

            return Http::response($this->listing($key, $request->url()));
        });

        $found = app(AdvertisingConnectorRegistry::class)->adapter($key)?->discover($this->connect($key));

        $this->assertTrue($throttled);
        $this->assertGreaterThan(0, $found?->resources->count(), "{$key} declares a retry policy and gave up on a 429");
    }

    /** Carts are asked for only where they exist, so «0 carts» is never what an unasked platform says. */
    #[DataProvider('providers')]
    public function test_abandoned_carts_are_asked_for_exactly_where_declared(string $key): void
    {
        $definition = $this->definition($key);

        if ($definition->kind !== ProviderKind::Commerce) {
            $this->assertFalse($definition->supports(ProviderCapability::AbandonedCarts));

            return;
        }

        $this->configure($key);
        Http::fake(['*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]])]);

        $connector = $this->connector($key);
        $this->assertInstanceOf(ApiCommerceConnector::class, $connector);
        $connector->withConnection($this->connect($key))->syncAbandonedCarts('store', '2026-01-01', '2026-01-02');

        $askedForCarts = static fn (Request $r): bool => str_contains($r->url(), 'carts');

        $definition->supports(ProviderCapability::AbandonedCarts) ? Http::assertSent($askedForCarts) : Http::assertNotSent($askedForCarts);
    }

    /** No call site converted to the registry goes back to asking about one provider by name or class. */
    public function test_converted_call_sites_do_not_go_back(): void
    {
        foreach ([
            'app/Domains/Integrations/Services/AccountDiscovery.php' => 'instanceof ApiAdvertisingConnector',
            'app/Domains/Integrations/Console/DiscoveryRefreshCommand.php' => 'instanceof ApiAdvertisingConnector',
            'app/Domains/Commerce/Services/StoreDiscovery.php' => 'instanceof ApiCommerceConnector',
            'app/Domains/Metrics/Services/PeriodReachFetcher.php' => 'instanceof ReportsPeriodReach',
            'app/Domains/Commerce/Http/Controllers/StoreController.php' => "'zid'",
            'app/Domains/Commerce/Services/StoreFunnelService.php' => "'zid'",
            'app/Domains/Integrations/Http/Controllers/IntegrationController.php' => "'sandbox'",
        ] as $file => $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) file_get_contents(base_path($file)), "{$file} went back to {$forbidden}");
        }
    }

    /** The runbook's table is this registry, not somebody's memory of it. */
    public function test_the_runbook_table_is_the_registry(): void
    {
        $runbook = (string) file_get_contents(base_path('../docs/PRODUCTION_RUNBOOK.md'));

        foreach (ProviderCatalogue::declared() as $definition) {
            $row = '| '.implode(' | ', $definition->capabilityRow()).' |';
            $this->assertStringContainsString($row, $runbook, "the runbook's capability table is stale for {$definition->key}");
        }
    }

    /** Selected the way an operator selects it: one active binding on a project of this tenant. */
    private function bind(ExternalAccount $resource, string $purpose): void
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $resource->id,
            'provider' => $resource->provider,
            'purpose' => $purpose,
            'is_active' => true,
            'is_primary' => true,
        ]);
    }

    private function definition(string $key): ProviderDefinition
    {
        $definition = ProviderCatalogue::declaration($key);
        $this->assertNotNull($definition);

        return $definition;
    }

    /** The family's own connector, or null for GA4, which has none. */
    private function connector(string $key): ?object
    {
        return match ($this->definition($key)->kind) {
            ProviderKind::Advertising => app(AdvertisingConnectorRegistry::class)->get($key),
            ProviderKind::Commerce => app(CommerceConnectorRegistry::class)->get($key),
            ProviderKind::Measurement => null,
        };
    }

    private function hasNoTokenFlow(ProviderDefinition $definition): bool
    {
        return $definition->auth === ProviderAuth::ApiKey || $definition->key === 'sandbox';
    }

    /** Every credential the catalogue requires, so these cases test the capability and not readiness. */
    private function configure(string $key): void
    {
        $definition = $this->definition($key);

        foreach ($definition->requiredKeys() as $field) {
            config()->set("{$definition->kind->configFile()}.platforms.{$key}.{$field}", match (true) {
                $field === 'client_id' && in_array($key, ['google', 'ga4'], true) => '123456789012-abc.apps.googleusercontent.com',
                $field === 'version' => '202601',
                default => "contract-{$field}",
            });
        }

        $this->app->forgetScopedInstances();
    }

    private function connect(string $key, ?OAuthTokens $tokens = null): ProviderConnection
    {
        $apiKey = $this->definition($key)->auth === ProviderAuth::ApiKey;

        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: $key,
            tokens: $tokens ?? new OAuthTokens('AT', $apiKey ? null : 'RT', $apiKey ? null : Carbon::now()->addDay(), raw: ['authorization' => 'MGR', 'token_secret' => 'TS']),
            connectionName: $key,
            externalOwnerId: $apiKey ? 'contract-owner' : null,
            credentialType: $apiKey ? 'api_key' : 'oauth2',
        );
    }

    /**
     * What each provider's own listing endpoint answers, in its own shape.
     *
     * A new provider fails here until it adds its own, which is on purpose: «discovery returns the
     * real resource type» cannot be claimed about a call nobody has shown an answer to.
     *
     * @return array<string, mixed>
     */
    private function listing(string $key, string $url): array
    {
        return match ($key) {
            'snapchat' => ['request_status' => 'SUCCESS', 'organizations' => [['organization' => [
                'id' => 'org-1', 'name' => 'Org',
                'ad_accounts' => [['id' => 'snap-1', 'name' => 'Snap', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'ACTIVE']],
            ]]]],
            'tiktok' => ['code' => 0, 'data' => ['list' => [['advertiser_id' => 'tt-1', 'advertiser_name' => 'Tik', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh']]]],
            'meta' => match (true) {
                str_contains($url, 'me/adaccounts') => ['data' => [['id' => 'act_1', 'name' => 'Direct', 'currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1]]],
                str_contains($url, 'me/businesses') => ['data' => [['id' => 'biz-1', 'name' => 'Business']]],
                str_contains($url, 'owned_ad_accounts') => ['data' => [['id' => 'act_2', 'name' => 'Owned', 'currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1]]],
                default => ['data' => []],
            },
            'google' => match (true) {
                str_contains($url, 'listAccessibleCustomers') => ['resourceNames' => ['customers/9999999999']],
                default => [['results' => [
                    ['customer' => ['id' => '9999999999', 'descriptiveName' => 'Manager', 'manager' => true, 'status' => 'ENABLED', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh'],
                        'customerClient' => ['id' => '1111111111', 'descriptiveName' => 'Client', 'manager' => false, 'status' => 'ENABLED', 'level' => '1', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh']],
                ]]],
            },
            'x' => ['data' => [['id' => 'x-1', 'name' => 'X', 'timezone' => 'Asia/Riyadh', 'deleted' => false]]],
            'linkedin' => ['elements' => [['id' => 501, 'name' => 'Li', 'currency' => 'SAR', 'status' => 'ACTIVE']]],
            'openai_ads' => ['id' => 'oa-1', 'name' => 'OpenAI', 'currency_code' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'active'],
            'ga4' => ['accountSummaries' => [[
                'account' => 'accounts/9', 'displayName' => 'Analytics',
                'propertySummaries' => [['property' => 'properties/1', 'displayName' => 'Site']],
            ]]],
            'salla' => ['data' => ['id' => 77, 'name' => 'Salla store', 'currency' => 'SAR', 'status' => 'active']],
            'zid' => ['user' => ['store' => ['id' => 88, 'title' => 'Zid store', 'currency' => 'SAR']]],
            default => throw new RuntimeException("{$key} declares discovery and this suite has no listing fixture for it; add its real shape."),
        };
    }
}
