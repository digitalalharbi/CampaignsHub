<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-SCOPE-SELECTION-001 — the report's campaign picker names the AD ACCOUNT.
 *
 * ## The owner's objection, and why the platform filter did not finish it
 *
 * «والحملات يجيب تطوير اختيارات داخل اعدادات التقرير لانه تظهر جميع الحملات في الحسابات الاعلانية
 * جميعها وهذا غير منطق ابدا» — the picker offers every campaign in every ad account at once.
 *
 * The first answer was a platform filter, and it narrows the list without answering this: an agency
 * running two Meta ad accounts for one client has two accounts behind ONE platform pill, so the pill
 * hands back the flat list it was meant to replace. What tells those campaigns apart is the account
 * that paid for them, and nothing on the screen said which that was.
 *
 * So the account is served per campaign and offered as a filter of its own. These tests hold the
 * three things that make it trustworthy rather than decorative: it names the account the campaign's
 * own rows belong to, it lists ALL of them when a unified campaign gathers several, and it obeys
 * ACCOUNT-SCOPE-ISOLATION-001 — a deselected account is not this project's to name.
 */
final class ReportBuilderAdAccountPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private ExternalAccount $brandAccount;

    private ExternalAccount $retailAccount;

    private ExternalAccount $deselectedAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ap-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'ap-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create([
            'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        /*
         * TWO ad accounts on ONE provider — the case the platform filter cannot separate, and the
         * reason this test exists. A third is bound and then DESELECTED.
         */
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );

        $this->brandAccount = $this->account($connection->getKey(), 'Brand — Meta', 'meta-brand');
        $this->retailAccount = $this->account($connection->getKey(), 'Retail — Meta', 'meta-retail');
        $this->deselectedAccount = $this->account($connection->getKey(), 'Old agency — Meta', 'meta-old');

        $this->bind($this->brandAccount, true);
        $this->bind($this->retailAccount, true);
        $this->bind($this->deselectedAccount, false);

        app(TenantContext::class)->forget();
    }

    /** Each campaign names the account its own external rows belong to. */
    public function test_each_campaign_names_the_ad_account_it_came_from(): void
    {
        $brand = $this->campaignOn('Spring brand', [$this->brandAccount]);
        $retail = $this->campaignOn('Spring retail', [$this->retailAccount]);

        $rows = collect($this->builderOptions()['campaigns'])->keyBy('id');

        $this->assertSame(
            ['Brand — Meta'],
            array_column($rows[(string) $brand->id]['accounts'], 'name'),
        );
        $this->assertSame(
            ['Retail — Meta'],
            array_column($rows[(string) $retail->id]['accounts'], 'name'),
        );
    }

    /**
     * Two accounts on one platform are two choices, not one.
     *
     * This is the assertion the platform filter cannot make. Both campaigns return `meta` as their
     * platform, so a picker narrowed by platform alone still shows both — and the operator is back
     * where they started.
     */
    public function test_two_accounts_on_one_platform_are_offered_separately(): void
    {
        $this->campaignOn('Spring brand', [$this->brandAccount]);
        $this->campaignOn('Spring retail', [$this->retailAccount]);

        $offered = array_column($this->builderOptions()['ad_accounts'], 'name');

        // Ordered by name, so the control's order does not depend on which synced first.
        $this->assertSame(['Brand — Meta', 'Retail — Meta'], $offered);
    }

    /**
     * A unified campaign fed by two accounts names BOTH.
     *
     * Reducing it to one would have to pick a winner, and every rule for picking one misstates where
     * the spend came from — which is the whole thing this is being asked to clear up.
     */
    public function test_a_campaign_fed_by_two_accounts_names_both(): void
    {
        $shared = $this->campaignOn('Always on', [$this->brandAccount, $this->retailAccount]);

        $rows = collect($this->builderOptions()['campaigns'])->keyBy('id');

        $this->assertSame(
            ['Brand — Meta', 'Retail — Meta'],
            array_column($rows[(string) $shared->id]['accounts'], 'name'),
        );
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-001 — a DESELECTED account is not this project's to name.
     *
     * The campaign is still listed: it is the project's campaign and hiding it would silently drop it
     * from a report. What the picker may not do is attribute it to an account the operator has said
     * is not part of this project — and the deselected account may not appear in the filter either,
     * because a filter entry that matches nothing visible is a control that empties the list.
     */
    public function test_a_deselected_accounts_campaign_is_listed_without_naming_that_account(): void
    {
        $old = $this->campaignOn('Last year', [$this->deselectedAccount]);

        $options = $this->builderOptions();
        $rows = collect($options['campaigns'])->keyBy('id');

        $this->assertArrayHasKey((string) $old->id, $rows, 'the campaign itself must still be listed');
        $this->assertSame([], $rows[(string) $old->id]['accounts']);
        $this->assertNotContains('Old agency — Meta', array_column($options['ad_accounts'], 'name'));
    }

    /** A campaign with no external rows at all is listed with no account, never hidden. */
    public function test_a_campaign_with_no_external_rows_is_listed_with_no_account(): void
    {
        $manual = $this->campaignOn('Entered by hand', []);

        $rows = collect($this->builderOptions()['campaigns'])->keyBy('id');

        $this->assertArrayHasKey((string) $manual->id, $rows);
        $this->assertSame([], $rows[(string) $manual->id]['accounts']);
    }

    /**
     * An account the provider named with an empty string falls back to its id.
     *
     * «» in a filter is worse than a raw identifier: two of them are indistinguishable, which is the
     * exact failure this whole change exists to remove.
     */
    public function test_an_unnamed_account_falls_back_to_its_identifier(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $unnamed = $this->account(
            (string) $this->brandAccount->provider_connection_id, '', 'meta-unnamed',
        );
        $this->bind($unnamed, true);
        app(TenantContext::class)->forget();

        $campaign = $this->campaignOn('Nameless account', [$unnamed]);

        $rows = collect($this->builderOptions()['campaigns'])->keyBy('id');

        $this->assertSame(
            [(string) $unnamed->getKey()],
            array_column($rows[(string) $campaign->id]['accounts'], 'name'),
        );
    }

    /** @return array<string,mixed> */
    private function builderOptions(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/live/options")
            ->assertOk()
            ->json('data');
    }

    /** @param  list<ExternalAccount>  $accounts */
    private function campaignOn(string $name, array $accounts): UnifiedCampaign
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => 'sales', 'platforms' => ['meta'],
        ]);

        foreach ($accounts as $account) {
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $account->getKey(),
                'unified_campaign_id' => $campaign->id,
                'provider' => 'meta', 'external_id' => 'e-'.uniqid(), 'name' => $name, 'status' => 'active',
            ]);
        }

        app(TenantContext::class)->forget();

        return $campaign;
    }

    private function account(string $connectionId, string $name, string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connectionId,
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => $externalId.'-'.uniqid(), 'name' => $name,
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id, 'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }
}
