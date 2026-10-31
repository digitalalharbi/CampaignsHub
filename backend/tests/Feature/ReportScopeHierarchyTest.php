<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REPORT-SCOPE-HIERARCHY-001 — a live link covers exactly the accounts, campaigns and ads the
 * operator picked, and an empty choice is refused rather than widened to «everything in the project».
 *
 * The owner's case: one project holds Razzah's, hanka's and Saudi Surfing Federation's ad accounts.
 * A Razzah report must never carry the other two.
 */
final class ReportScopeHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $razzah;

    private string $razzah2;

    private string $hanka;

    private UnifiedCampaign $razzahA;

    private UnifiedCampaign $razzahB;

    private UnifiedCampaign $razzah2A;

    private UnifiedCampaign $hankaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->holdingTenant((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $tenant);
        $owner->assignRole($role);
        Sanctum::actingAs($owner);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project 1', 'status' => 'active']);

        $this->razzah = (string) Str::uuid();
        $this->razzah2 = (string) Str::uuid();
        $this->hanka = (string) Str::uuid();

        $mk = fn (string $n): UnifiedCampaign => UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $n, 'status' => 'active', 'objective' => 'sales']);
        $this->razzahA = $mk('Razzah A');
        $this->razzahB = $mk('Razzah B');
        $this->razzah2A = $mk('Razzah2 A');
        $this->hankaA = $mk('Hanka A');

        $this->metric($this->razzahA, $this->razzah, 100);
        $this->metric($this->razzahB, $this->razzah, 10);
        $this->metric($this->razzah2A, $this->razzah2, 1);
        $this->metric($this->hankaA, $this->hanka, 999);
    }

    private function metric(UnifiedCampaign $c, string $account, float $spend): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
            'external_account_id' => $account, 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => '2026-07-10', 'value' => $spend,
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    private function build(array $extra)
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/reports/live", $extra + [
            'name' => 'Razzah', 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]);
    }

    private function scope(): array
    {
        return (array) ReportShare::query()->latest('created_at')->firstOrFail()->scope;
    }

    public function test_an_empty_selection_is_refused_not_widened_to_the_whole_project(): void
    {
        $this->build([])->assertStatus(422);
        $this->assertSame(0, ReportShare::query()->count());
    }

    public function test_the_selected_accounts_are_stored_and_other_clients_accounts_are_not_in_the_scope(): void
    {
        $this->build(['account_ids' => [$this->razzah, $this->razzah2]])->assertCreated();

        $scope = $this->scope();
        $this->assertEqualsCanonicalizing([$this->razzah, $this->razzah2], $scope['account_ids']);
        // LIVE-LINK-BEFORE-CAMPAIGNS-001 — with no campaign narrowed the link FOLLOWS its accounts: no frozen list, resolved at read time
        // (the isolation itself is held by `test_the_live_link_figures_exclude_the_other_clients_accounts`).
        $this->assertTrue($scope['dynamic_campaigns']);
        $this->assertSame([], $scope['campaign_ids']);
        $this->assertNotContains((string) $this->hankaA->id, $scope['campaign_ids']);
    }

    public function test_a_chosen_campaign_subset_narrows_only_its_own_account(): void
    {
        $this->build([
            'account_ids' => [$this->razzah, $this->razzah2],
            'accounts' => [$this->razzah => ['campaign_ids' => [(string) $this->razzahA->id]]],
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            [(string) $this->razzahA->id, (string) $this->razzah2A->id],
            $this->scope()['campaign_ids'],
        );
    }

    public function test_an_account_that_is_not_the_projects_is_refused(): void
    {
        $this->build(['account_ids' => [(string) Str::uuid()]])->assertStatus(422);
    }

    public function test_a_campaign_outside_its_account_is_refused(): void
    {
        $this->build([
            'account_ids' => [$this->razzah],
            'accounts' => [$this->razzah => ['campaign_ids' => [(string) $this->hankaA->id]]],
        ])->assertStatus(422);
    }

    private function realAccount(string $id): void
    {
        if (ExternalAccount::withoutGlobalScopes()->whereKey($id)->exists()) {
            return;
        }
        $tenant = (string) $this->project->tenant_id;
        $connection = app(TokenVault::class)->open(
            tenantId: $tenant, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)), connectionName: 'meta'.$id,
        )->getKey();
        (new ExternalAccount)->forceFill([
            'id' => $id, 'tenant_id' => $tenant, 'provider_connection_id' => $connection,
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'acc-'.$id,
            'name' => 'Acc', 'currency' => 'SAR', 'status' => 'active',
        ])->save();
    }

    private function adUnder(UnifiedCampaign $c, string $name, ?string $account = null): string
    {
        $this->realAccount($account ?? $this->razzah);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $c->tenant_id, 'project_id' => $this->project->id, 'unified_campaign_id' => $c->id,
            'external_account_id' => $account ?? $this->razzah,
            'provider' => 'meta', 'external_id' => 'ec-'.Str::uuid(), 'name' => $c->name, 'status' => 'active',
        ]);

        return (string) ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $c->tenant_id, 'project_id' => $this->project->id, 'unified_campaign_id' => $c->id,
            'external_campaign_id' => $external->id, 'provider' => 'meta', 'external_id' => 'ad-'.Str::uuid(),
            'name' => $name, 'status' => 'active',
        ])->id;
    }

    public function test_chosen_ads_are_stored_and_an_ad_of_another_campaign_is_refused(): void
    {
        $mine = $this->adUnder($this->razzahA, 'Mine');
        $other = $this->adUnder($this->razzahB, 'Other');

        $this->build([
            'account_ids' => [$this->razzah],
            'accounts' => [$this->razzah => ['campaign_ids' => [(string) $this->razzahA->id], 'ad_ids' => [$mine]]],
        ])->assertCreated();
        $this->assertSame([$mine], $this->scope()['ad_ids']);

        $this->build([
            'account_ids' => [$this->razzah],
            'accounts' => [$this->razzah => ['campaign_ids' => [(string) $this->razzahA->id], 'ad_ids' => [$other]]],
        ])->assertStatus(422);
    }

    public function test_the_ads_endpoint_lists_only_the_asked_campaigns_ads(): void
    {
        $this->adUnder($this->razzahA, 'Razzah ad');
        $this->adUnder($this->hankaA, 'Hanka ad', $this->hanka);

        $names = collect($this->getJson("/api/v1/projects/{$this->project->id}/reports/live/options/ads?campaign_ids[]={$this->razzahA->id}")
            ->assertOk()->json('data.ads'))->pluck('name')->all();

        $this->assertSame(['Razzah ad'], $names);
    }

    public function test_the_live_link_figures_exclude_the_other_clients_accounts(): void
    {
        $token = $this->build(['account_ids' => [$this->razzah, $this->razzah2], 'hide_spend' => false, 'detail_level' => 'detailed'])
            ->assertCreated()->json('data.token');

        $spend = fn (string $t): float => (float) $this->getJson("/api/v1/reports/shared/{$t}/live")->assertOk()->json('data.totals.spend');

        // 100 + 10 + 1 — Razzah's two accounts; hanka's 999 is nowhere in it.
        $this->assertSame(111.0, $spend($token));

        $narrow = $this->build([
            'account_ids' => [$this->razzah], 'hide_spend' => false, 'detail_level' => 'detailed',
            'accounts' => [$this->razzah => ['campaign_ids' => [(string) $this->razzahA->id]]],
        ])->assertCreated()->json('data.token');
        $this->assertSame(100.0, $spend($narrow));
    }

    public function test_the_saved_report_picker_lists_every_account_with_figures_even_without_an_account_row(): void
    {
        $ids = collect($this->getJson("/api/v1/projects/{$this->project->id}/reports/scope/options")->assertOk()->json('data.accounts'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->razzah, $this->razzah2, $this->hanka], $ids);
    }

    public function test_the_link_builder_offers_the_accounts_that_have_figures_and_the_campaigns_behind_each(): void
    {
        $data = $this->getJson("/api/v1/projects/{$this->project->id}/reports/live/options")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$this->razzah, $this->razzah2, $this->hanka], array_column($data['ad_accounts'], 'id'));

        $razzahA = collect($data['campaigns'])->firstWhere('id', (string) $this->razzahA->id);
        $this->assertSame([$this->razzah], array_column($razzahA['accounts'], 'id'));
    }

    public function test_the_picker_narrows_campaigns_to_the_selected_accounts(): void
    {
        $url = "/api/v1/projects/{$this->project->id}/reports/scope/options?account_ids[]={$this->razzah}&account_ids[]={$this->razzah2}";
        $names = collect($this->getJson($url)->assertOk()->json('data.campaigns'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Razzah A', 'Razzah B', 'Razzah2 A'], $names);
    }

    public function test_the_saved_report_picker_lists_an_account_that_has_campaigns_but_no_figures(): void
    {
        $quiet = (string) Str::uuid();
        $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'Never ran', 'status' => 'active', 'objective' => 'sales']);
        $this->realAccount($quiet);
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $campaign->tenant_id, 'project_id' => $this->project->id, 'unified_campaign_id' => $campaign->id,
            'external_account_id' => $quiet, 'provider' => 'meta', 'external_id' => 'ec-'.Str::uuid(),
            'name' => 'Never ran', 'status' => 'active',
        ]);

        $json = $this->getJson("/api/v1/projects/{$this->project->id}/reports/scope/options")->assertOk()->json('data');
        $this->assertContains($quiet, array_column($json['accounts'], 'id'));

        $names = collect($this->getJson("/api/v1/projects/{$this->project->id}/reports/scope/options?account_ids[]={$quiet}")->json('data.campaigns'))->pluck('name')->all();
        $this->assertSame(['Never ran'], $names);
    }
}
