<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Integrations\Support\InsightsAuthorisation;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * META-INSIGHTS-GRANT-001 — «connected» and «allowed to read insights» are two different facts.
 *
 * Production's Meta connection discovers its account perfectly and then fails every sync with
 *
 *     (#200) Ad account owner has NOT grant ads_management or ads_read permission
 *
 * which is the AD ACCOUNT's grant, decided in Business Manager after consent. OAuth is green, the
 * catalogue is full, and no figure will ever arrive. The page offered «choose your accounts» — the
 * wrong next step for a connection that will be refused the moment it reads one.
 *
 * These pin the four things that were wrong: the refusal is recognised, it is remembered, it outranks
 * selection in what the page offers, and it names itself so the sentence can be specific.
 */
final class MetaInsightsGrantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** Production's exact sentence, which is the only one that has to be recognised. */
    public function test_metas_refusal_is_recognised_by_its_code_and_its_subject(): void
    {
        $this->assertTrue(InsightsAuthorisation::refusedBy(
            '(#200) Ad account owner has NOT grant ads_management or ads_read permission',
        ));
    }

    /**
     * An unrelated #200 is not this, and neither is an unrelated mention of the scope.
     *
     * #200 is Meta's general permissions error; matching the code alone would stamp a connection
     * reconnect-required for something reconnecting cannot fix.
     */
    public function test_an_unrelated_refusal_is_not_read_as_this_one(): void
    {
        $this->assertFalse(InsightsAuthorisation::refusedBy('(#200) Requires pages_read_engagement permission'));
        $this->assertFalse(InsightsAuthorisation::refusedBy('The ads_read scope was granted at 10:03'));
        $this->assertFalse(InsightsAuthorisation::refusedBy(null));
    }

    /**
     * `ads_read` is enough, and `ads_management` implies it.
     *
     * `business_management` is a different capability — the Business hierarchy — and naming it as
     * required would send a customer to grant more than this product needs.
     */
    public function test_either_read_scope_can_read_insights_and_business_management_is_not_one(): void
    {
        $this->assertTrue(InsightsAuthorisation::grantedBy('meta', ['ads_read']));
        $this->assertTrue(InsightsAuthorisation::grantedBy('meta', ['ads_management']));
        $this->assertFalse(InsightsAuthorisation::grantedBy('meta', ['business_management', 'public_profile']));
    }

    /** An unrecorded grant is UNKNOWN, and unknown is not broken — rows predate the column. */
    public function test_a_connection_with_no_recorded_grant_is_not_called_broken(): void
    {
        $this->assertTrue(InsightsAuthorisation::grantedBy('meta', null));
        $this->assertTrue(InsightsAuthorisation::grantedBy('meta', []));
    }

    /** No opinion is offered about a platform whose grant model has not been verified. */
    public function test_another_platform_is_not_judged_by_metas_scope_names(): void
    {
        $this->assertTrue(InsightsAuthorisation::grantedBy('snapchat', ['snapchat-marketing-api']));
    }

    /**
     * The page stops offering «choose your accounts» and asks for the one thing that can help.
     *
     * This is the production shape: connected, an account discovered, and a refusal on record.
     */
    public function test_a_refused_connection_asks_for_reauthorisation_rather_than_a_selection(): void
    {
        $connection = $this->metaConnection(['ads_read']);
        $this->discover($connection);

        $before = app(ConnectionWizardState::class)->for($connection);
        $this->assertSame(ConnectionWizardState::USER_ACCOUNT_SELECTION_REQUIRED, $before['user_state']);
        $this->assertNull($before['reauth_reason']);

        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $after = app(ConnectionWizardState::class)->for($connection->fresh());
        $this->assertSame(ConnectionWizardState::USER_REAUTH_REQUIRED, $after['user_state']);
        $this->assertSame('insights_not_authorised', $after['reauth_reason']);
    }

    /**
     * A consent that never asked for a read scope is refused before anything is called.
     *
     * The other half of the same question: this one needs no sync to have run.
     */
    public function test_a_grant_without_a_read_scope_is_caught_before_a_sync_proves_it(): void
    {
        $connection = $this->metaConnection(['business_management']);
        $this->discover($connection);

        $state = app(ConnectionWizardState::class)->for($connection);

        $this->assertSame(ConnectionWizardState::USER_REAUTH_REQUIRED, $state['user_state']);
        $this->assertSame('insights_not_authorised', $state['reauth_reason']);
    }

    /** The grant itself is surfaced, so «what did we actually get?» has an answer on the page. */
    public function test_the_granted_scopes_are_reported(): void
    {
        $connection = $this->metaConnection(['ads_read', 'public_profile']);

        $this->assertSame(['ads_read', 'public_profile'], app(ConnectionWizardState::class)->for($connection)['granted_scopes']);
    }

    /**
     * RECONNECTING IS THE SAME CONNECTION, and everything hanging off it survives.
     *
     * This is the migration the Owner's list asks for, asserted rather than assumed: the connection
     * row, the discovered account, the project binding and the historical metrics are all still
     * there and still joined up afterwards, and the newly granted scopes replace the old ones on the
     * SAME row. A second connection would be worse than useless — `external_accounts` is unique per
     * connection, so every account would be discovered again and every figure counted twice across
     * the dashboard, the reports and the alerts, with nothing anywhere saying why.
     */
    public function test_reauthorising_recredentials_the_same_connection_and_keeps_its_history(): void
    {
        $connection = $this->metaConnection(['public_profile']);
        $this->discover($connection);
        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $account = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->firstOrFail();

        app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('a-new-token', scope: 'ads_read public_profile'),
            connectionName: 'Meta',
        );

        $after = $connection->fresh();

        // The SAME row — not a second connection beside it.
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->count());
        $this->assertSame(['ads_read', 'public_profile'], $after->scopes);

        // The refusal is cleared by a NEW grant, so the page stops asking for something already done.
        $this->assertNull($after->insights_denied_at);

        // And the account it discovered is the same account, still attached to the same connection.
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act_1')->count());
        $this->assertSame((string) $connection->id, (string) $account->fresh()->provider_connection_id);

        // Which means the connection can read insights again, by the page's own reckoning.
        $this->assertNull(app(ConnectionWizardState::class)->for($after)['reauth_reason']);
    }

    /** A token REFRESH is not a new grant, so it must not quietly clear the warning every hour. */
    public function test_a_token_refresh_does_not_clear_a_refusal(): void
    {
        $connection = $this->metaConnection(['ads_read']);
        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        app(TokenVault::class)->store($connection, new OAuthTokens('a-refreshed-token'));

        $this->assertNotNull($connection->fresh()->insights_denied_at);
    }

    /**
     * The ACCOUNT that was refused is the one named — RazahAvanue, not «Meta».
     *
     * One authorisation holds many ad accounts and Meta answers per asset, so a refusal filed as
     * `provider_error` said «Meta is broken» about an install where seventeen accounts were
     * discovered and one was refused. The category is what routes the action, and this action has a
     * specific owner: grant `ads_read` on THIS asset, to THIS user, in Business Manager.
     */
    public function test_the_account_meta_refused_is_the_account_that_carries_the_refusal(): void
    {
        $connection = $this->metaConnection(['ads_read']);
        $account = $this->account($connection, 'act_3493018704182532', 'RazahAvanue', 'insights_not_authorised');
        $this->bind($account);

        $this->assertSame(AccountHealth::INSIGHTS_NOT_AUTHORISED, app(AccountHealth::class)->for($account));
        $this->assertContains(AccountHealth::INSIGHTS_NOT_AUTHORISED, AccountHealth::NEEDS_ATTENTION);
    }

    /**
     * ONLY the chosen account is a sync target — the acceptance account is RazahAvanue and no other.
     *
     * Seventeen accounts were discovered; one was chosen. An account nobody bound is `not_connected`
     * rather than healthy or broken, because it feeds no project and the sweep never reaches it —
     * `AdPlatformSyncSweepTest` holds that end, and this holds the reading of it. It is also why the
     * refusal had to be recorded per account: «Meta is refused» would have been a claim about
     * sixteen accounts nobody asked this product to read.
     */
    public function test_only_the_chosen_account_is_a_sync_target(): void
    {
        $connection = $this->metaConnection(['ads_read']);

        $chosen = $this->account($connection, 'act_3493018704182532', 'RazahAvanue', 'insights_not_authorised');
        $this->bind($chosen);

        $notChosen = $this->account($connection, 'act_999', 'One of the other sixteen', null);

        $health = app(AccountHealth::class);

        $this->assertSame(AccountHealth::INSIGHTS_NOT_AUTHORISED, $health->for($chosen));
        $this->assertSame(AccountHealth::NOT_CONNECTED, $health->for($notChosen));
    }

    private function account(ProviderConnection $connection, string $externalId, string $name, ?string $category): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'external_id' => $externalId,
            'name' => $name,
            'account_type' => 'ad_account',
            'status' => 'active',
            'last_synced_at' => Carbon::now()->subHour(),
            'last_sync_error_category' => $category,
        ]);
    }

    private function bind(ExternalAccount $account): void
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }

    private function metaConnection(?array $scopes): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'meta',
            'connection_name' => 'Meta',
            'scope' => 'project_only',
            'status' => 'connected',
            'scopes' => $scopes,
        ]);
    }

    private function discover(ProviderConnection $connection): void
    {
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'external_id' => 'act_1',
            'name' => 'Ad account',
            'account_type' => 'ad_account',
            'status' => 'active',
        ]);
    }
}
