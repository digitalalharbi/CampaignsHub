<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GADS-STALE-PICKER-001 — a refused discovery is not a list of accounts to choose from.
 *
 * The owner's screen said two things at once: the Google card «0 ad accounts» beside a banner offering
 * «1 account available — Finish selecting accounts», and the button led to «Item not found».
 *
 * Both numbers were real readings of different queries. The card counted `withCount('externalAccounts')`
 * under the tenant scope; the banner counted ad accounts through `ConnectionWizardState` with
 * `withoutGlobalScopes()`. And neither asked the question that mattered — whether the LATEST discovery
 * had produced anything — because nothing recorded the answer.
 *
 * The account rows survive a refusal deliberately: a temporary failure must not unbind work an operator
 * already did. That is precisely why they cannot also serve as «what is selectable now».
 */
final class StaleDiscoveryNotOfferedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $this->workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->workspace->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        foreach (['client_id', 'client_secret'] as $key) {
            config()->set("ad_platforms.platforms.google.{$key}", "test-{$key}");
        }
    }

    private function connection(): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );
    }

    private function staleRow(ProviderConnection $connection): ExternalAccount
    {
        return ExternalAccount::create([
            'tenant_id' => $this->tenant->getKey(),
            'client_workspace_id' => $this->workspace->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'google',
            'account_type' => 'ad_account',
            'external_id' => '1234567890',
            'name' => 'Found when access still worked',
            'status' => 'active',
            'discovered_at' => Carbon::now()->subMonth(),
        ]);
    }

    private bool $granted = false;

    /**
     * A refusal that names the IDENTITY, not the project — the case the owner actually has.
     *
     * One stub for the whole timeline: it refuses until `$granted` is set, then answers. That is what
     * «Google grants the access and the next discovery succeeds» looks like from this side, and it keeps
     * both attempts hitting the same URL as they do in production.
     */
    private function refused(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'customers:listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1234567890']]);
            }

            if ($this->granted) {
                return Http::response([[
                    'results' => [['customer' => [
                        'id' => '1234567890', 'descriptiveName' => 'Real advertiser', 'manager' => false,
                        'status' => 'ENABLED', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh',
                    ]]],
                ]]);
            }

            return Http::response([
                'error' => [
                    'code' => 403, 'status' => 'PERMISSION_DENIED',
                    'message' => 'The caller does not have permission',
                    'details' => [[
                        'errors' => [['errorCode' => ['authorizationError' => 'USER_PERMISSION_DENIED']]],
                        'requestId' => 'REQ-1',
                    ]],
                ],
            ], 403);
        });
    }

    private function attempt(ProviderConnection $connection): void
    {
        try {
            app(AccountDiscovery::class)->refresh($connection);
        } catch (\Throwable) {
            // The refusal still propagates; what these cases read is what the connection now knows.
        }
    }

    #[Test]
    public function a_refused_discovery_is_recorded_with_its_reason(): void
    {
        $connection = $this->connection();
        $this->refused();
        $this->attempt($connection);

        $connection->refresh();

        $this->assertSame('provider_permission_denied', $connection->discovery_blocked_reason);
        $this->assertNotNull($connection->last_discovery_attempted_at);
        $this->assertNull($connection->last_discovery_succeeded_at, 'a refusal was recorded as a success');
    }

    #[Test]
    public function a_row_from_an_earlier_discovery_is_not_offered_as_currently_selectable(): void
    {
        $connection = $this->connection();
        $this->staleRow($connection);
        $this->refused();
        $this->attempt($connection);

        $state = app(ConnectionWizardState::class)->for($connection->refresh());

        $this->assertSame(0, $state['discovered'], 'a row the provider just declined to confirm was offered as available');
        $this->assertSame(1, $state['ever_discovered'], 'the row itself was lost, which would unbind real work');
        $this->assertFalse($state['resumable'], 'the wizard still invited somebody to finish selecting nothing');
    }

    /** The two surfaces that disagreed now read one number. */
    #[Test]
    public function the_card_and_the_wizard_agree_after_a_refusal(): void
    {
        $connection = $this->connection();
        $this->staleRow($connection);
        $this->refused();
        $this->attempt($connection);

        $state = app(ConnectionWizardState::class)->for($connection->refresh());

        /*
         * The card reads `discovered` from this same service now, so «0 vs 1» is not a disagreement it
         * can express. Asserted through the service rather than the endpoint because the endpoint needs a
         * permissioned user, and what is being pinned is that ONE definition feeds both.
         */
        $this->assertSame(0, $state['discovered']);
        $this->assertSame('provider_permission_denied', $state['discovery_blocked_reason']);
    }

    #[Test]
    public function a_binding_made_earlier_survives_the_refusal(): void
    {
        $connection = $this->connection();
        $account = $this->staleRow($connection);

        $binding = ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'google',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        $this->refused();
        $this->attempt($connection);

        $this->assertDatabaseHas('project_integration_bindings', ['id' => $binding->getKey(), 'is_active' => true]);
        $this->assertDatabaseHas('external_accounts', ['id' => $account->getKey()]);
    }

    /** When the provider answers again, the state clears itself with no click. */
    #[Test]
    public function a_later_successful_discovery_clears_the_block_and_offers_the_accounts(): void
    {
        $connection = $this->connection();
        $this->refused();
        $this->attempt($connection);

        $this->assertSame('provider_permission_denied', $connection->refresh()->discovery_blocked_reason);

        /*
         * The access is granted BETWEEN the two attempts, so one stub answers differently over time.
         *
         * A second `Http::fake([...])` does not replace the first — Laravel MERGES stub sets and the
         * earliest matching pattern wins — so re-faking the same URL left the refusal in place and this
         * case failed with the very error it was asserting recovery from. A flag the fake closes over
         * models the real timeline instead of fighting the fake.
         */
        $this->granted = true;

        $out = app(AccountDiscovery::class)->refresh($connection);
        $connection->refresh();

        $this->assertSame(1, $out['discovered']);
        $this->assertNull($connection->discovery_blocked_reason, 'the block survived a discovery that worked');
        $this->assertNotNull($connection->last_discovery_succeeded_at);

        $state = app(ConnectionWizardState::class)->for($connection);
        $this->assertSame(1, $state['discovered']);
    }
}
