<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportScopeHierarchy;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * REPORT-BOUND-ACCOUNTS-001 — every active, authorised account a project BINDS is offered to its reports, whether or not a campaign or a
 * figure has arrived yet.
 *
 * The Owner's NASEL case: bound to TikTok, Meta, Google and ChatGPT Ads, and the report builder listed TikTok alone because only TikTok had
 * synced campaigns. Ownership is the binding; what has arrived is a state to LABEL, never a reason to hide the account.
 */
final class ReportBoundAccountsTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $nasel;

    private Project $razzah;

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ba-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 'ba-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $this->tenant, Portal::Agency);
        $owner->assignRole($role);
        $this->actingAs($owner, 'sanctum');

        $mk = fn (string $n): Project => Project::create([
            'tenant_id' => $this->tenant->id, 'name' => $n, 'status' => 'active',
            'client_workspace_id' => ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $n, 'slug' => $n.uniqid(), 'mode' => 'managed', 'status' => 'active'])->id,
        ]);
        $this->nasel = $mk('NASEL');
        $this->razzah = $mk('razzah');

        $vault = app(TokenVault::class);
        foreach (['tiktok', 'meta', 'google', 'openai_ads'] as $provider) {
            $connection = $vault->open(tenantId: (string) $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider);
            $this->accounts[$provider] = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider, 'account_type' => 'ad_account',
                'external_id' => $provider.'-1', 'name' => 'Nasl '.$provider, 'currency' => 'USD', 'status' => 'active',
                'last_synced_at' => $provider === 'openai_ads' ? null : Carbon::now(),
            ]);
            $this->bind($this->nasel, $this->accounts[$provider], $provider);
        }
    }

    private function bind(Project $project, ExternalAccount $account, string $provider, bool $active = true): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'reporting', 'is_active' => $active, 'is_primary' => true,
        ]);
    }

    public function test_the_builder_offers_every_bound_account_with_no_data_clearly_labelled(): void
    {
        $res = $this->getJson("/api/v1/projects/{$this->nasel->id}/reports/scope/options")->assertOk();

        $byProvider = collect($res->json('data.accounts'))->keyBy('provider');
        $this->assertEqualsCanonicalizing(['google', 'meta', 'openai_ads', 'tiktok'], $byProvider->keys()->all());
        $this->assertSame('no_data', $byProvider['meta']['state'], 'synced, nothing for the period yet');
        $this->assertSame('unsynced', $byProvider['openai_ads']['state'], 'never synced');
        $this->assertEqualsCanonicalizing(['google', 'meta', 'openai_ads', 'tiktok'], $res->json('data.providers'));
    }

    public function test_a_deactivated_or_foreign_account_is_not_offered(): void
    {
        $this->bind($this->razzah, $this->accounts['meta'], 'meta');
        ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->nasel->id)->where('provider', 'google')->update(['is_active' => false]);

        $res = $this->getJson("/api/v1/projects/{$this->nasel->id}/reports/scope/options")->assertOk();
        $this->assertEqualsCanonicalizing(['meta', 'openai_ads', 'tiktok'], collect($res->json('data.accounts'))->pluck('provider')->all());

        $razzah = $this->getJson("/api/v1/projects/{$this->razzah->id}/reports/scope/options")->assertOk();
        $this->assertSame([(string) $this->accounts['meta']->id], collect($razzah->json('data.accounts'))->pluck('id')->all());
    }

    public function test_a_bound_account_with_nothing_synced_can_be_chosen_but_an_unbound_one_cannot(): void
    {
        $chosen = ReportScopeHierarchy::campaignsByAccount((string) $this->nasel->id, [(string) $this->accounts['meta']->id]);
        $this->assertSame([], $chosen[(string) $this->accounts['meta']->id]);

        $this->expectException(HttpException::class);
        ReportScopeHierarchy::campaignsByAccount((string) $this->razzah->id, [(string) $this->accounts['meta']->id]);
    }
}
