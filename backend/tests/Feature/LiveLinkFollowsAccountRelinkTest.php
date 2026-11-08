<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * LIVE-LINK-ACCOUNT-IDENTITY-001 (Owner defect 156) — a live link names an ad ACCOUNT, and re-entering that account's key
 * does not make it a different account.
 *
 * Production 2026-11-08: the project's ChatGPT Ads key was entered again from the project. That opened a new connection, a new
 * `external_accounts` row for the SAME provider ad account, bound it and deactivated the old row's binding. The Owner's live link
 * stored the OLD row's id as its ceiling, so its ChatGPT creatives (and their campaigns) fell outside it — the old rows by the
 * binding rule, the new rows by the ceiling — and 11 cards left the report with nothing deleted anywhere.
 */
final class LiveLinkFollowsAccountRelinkTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'rl-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 'rl-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $this->tenant, Portal::Agency);
        $owner->assignRole($role);
        $this->actingAs($owner, 'sanctum');

        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active']);
        $this->account = $this->accountRow('adacct-1');
        $this->bind($this->account, $this->project, true);
    }

    private function accountRow(string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'openai_ads', tokens: new OAuthTokens('K'.Str::random(6), null, null), connectionName: 'gpt', externalOwnerId: Str::random(8), credentialType: 'api_key');

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'openai_ads', 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'currency' => 'USD', 'status' => 'active', 'last_synced_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'openai_ads', 'purpose' => 'reporting', 'is_active' => $active, 'is_primary' => true,
        ]);
    }

    /** A campaign under the account with one creative that delivered yesterday. */
    private function runCreative(ExternalAccount $account, string $name, ?Project $filedUnder = null): ExternalCreative
    {
        $project = $filedUnder ?? $this->project;
        $unified = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'Camp '.$name, 'status' => 'active', 'objective' => 'traffic']);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => 'openai_ads',
            'external_id' => 'ec-'.Str::random(6), 'name' => 'Camp '.$name, 'status' => 'active', 'unified_campaign_id' => $unified->id,
        ]);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => $external->id, 'unified_campaign_id' => $unified->id, 'provider' => 'openai_ads', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(), 'value' => 5,
        ]);
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'provider' => 'openai_ads', 'external_account_id' => $account->id,
            'external_campaign_id' => $external->id, 'campaign_id' => $unified->id, 'external_creative_id' => 'ad_'.Str::random(8), 'name' => $name,
            'format' => 'chat_card', 'status' => 'active',
        ]);
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'creative_id' => $creative->id,
            'campaign_id' => $unified->id, 'metric_date' => Carbon::now()->subDay()->toDateString(), 'spend' => 5, 'impressions' => 10, 'clicks' => 1,
            'conversions' => 0, 'revenue' => 0, 'video_views' => 0, 'video_completions' => 0, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $creative;
    }

    /** @return array<string, mixed> */
    private function liveRoster(string $token): array
    {
        $payload = $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk()->json('data');

        return array_column($payload['ads_roster'] ?? [], 'name');
    }

    private function createLink(): string
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/reports/live", [
            'name' => 'Riwaq', 'account_ids' => [(string) $this->account->id], 'metrics' => ['spend', 'impressions', 'clicks'],
            'from' => Carbon::now()->subDays(6)->toDateString(), 'to' => Carbon::now()->toDateString(), 'period_kind' => 'custom', 'period_mode' => 'rolling',
            'creatives' => ['creatives' => true],
        ])->assertCreated()->json('data.token');
    }

    /** The key is entered again from the project: a new connection and row for the same provider account, bound; the old binding goes. */
    private function relink(): ExternalAccount
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);
        $fresh = $this->accountRow('adacct-1');
        $this->bind($fresh, $this->project, true);

        return $fresh;
    }

    public function test_the_link_keeps_its_creatives_when_the_account_is_linked_again_under_a_new_connection(): void
    {
        $this->runCreative($this->account, 'Card before');
        $token = $this->createLink();
        $this->assertContains('Card before', $this->liveRoster($token));

        $fresh = $this->relink();
        $this->runCreative($fresh, 'Card after');

        $roster = $this->liveRoster($token);
        $this->assertContains('Card after', $roster, 'the same ad account, linked again, left the link: its content vanished from the report');
        $this->assertNotContains('Card before', $roster, 'the deactivated row is still history, not this project\'s content');
    }

    public function test_the_stored_ceiling_is_not_rewritten_and_the_link_never_widens_to_another_account_or_project(): void
    {
        $token = $this->createLink();
        $stored = ReportShare::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $before = DB::table('report_shares')->where('id', $stored->id)->value('scope');

        $fresh = $this->relink();

        // A different ad account of the same project is not the link's account.
        $other = $this->accountRow('adacct-2');
        $this->bind($other, $this->project, true);
        $this->runCreative($other, 'Other account card');

        // The same provider account bound to ANOTHER project never joins this project's link.
        $elsewhere = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Elsewhere', 'status' => 'active']);
        $twin = $this->accountRow('adacct-1');
        $this->bind($twin, $elsewhere, true);
        $this->runCreative($twin, 'Twin elsewhere card', $elsewhere);

        $this->runCreative($fresh, 'Followed card');

        $roster = $this->liveRoster($token);
        $this->assertContains('Followed card', $roster);
        $this->assertNotContains('Other account card', $roster);
        $this->assertNotContains('Twin elsewhere card', $roster);
        $this->assertSame($before, DB::table('report_shares')->where('id', $stored->id)->value('scope'), 'reading a link never rewrites what the operator granted');
    }
}
