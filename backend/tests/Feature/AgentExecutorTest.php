<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Models\ActionLog;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AGENTS-EXEC-META-001 — an approved agent proposal is carried out by the campaign write path, as its
 * approver, and the platform's answer is what the log records.
 */
final class AgentExecutorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $approver;

    private Project $project;

    private ExternalCampaign $campaign;

    private ExternalAdSet $adSet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->approver = $this->member('a@w.test', $role);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Launch', 'objective' => 'sales', 'status' => 'active']);

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT-meta', 'RT', Carbon::now()->addDay()), connectionName: 'meta '.uniqid());
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_1', 'name' => 'Meta account', 'status' => 'active', 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => true]);

        $this->campaign = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $unified->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => '120001', 'name' => 'Spring', 'status' => 'active', 'currency' => 'SAR', 'daily_budget' => 100,
        ]);
        $this->adSet = ExternalAdSet::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $this->campaign->id, 'unified_campaign_id' => $unified->id,
            'provider' => 'meta', 'external_id' => '230001', 'name' => 'Set', 'status' => 'active', 'currency' => 'SAR', 'daily_budget' => 50,
        ]);
    }

    private function proposeAndApprove(array $body): string
    {
        $row = app(ProposalService::class)->propose($this->project, 'campaign', null, $body + [
            'provider' => 'meta', 'external_account_id' => (string) $this->campaign->external_account_id, 'reason' => 'CPA three times the target',
        ]);
        $this->assertSame('pending', $row->status, json_encode($row->violations));

        return (string) $this->actingAs($this->approver)
            ->postJson("/api/v1/projects/{$this->project->id}/agent-proposals/{$row->getKey()}/approve")
            ->assertOk()->json('data.status');
    }

    public function test_an_approved_pause_reaches_meta_once_and_the_mirror_follows(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $status = $this->proposeAndApprove(['target_type' => 'campaign', 'target_external_id' => '120001', 'action' => 'pause']);

        $this->assertSame('executed', $status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/120001') && $r['status'] === 'PAUSED');
        $this->assertSame('paused', $this->campaign->fresh()->status);
        $this->assertSame(['ok' => true, 'mirror' => ['status' => 'paused'], 'request_id' => null], array_intersect_key(
            ActionLog::withoutGlobalScopes()->where('phase', 'executed')->sole()->api_response, array_flip(['ok', 'mirror', 'request_id']),
        ));
    }

    public function test_an_approved_ad_set_budget_is_sent_in_minor_units(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $status = $this->proposeAndApprove(['target_type' => 'ad_set', 'target_external_id' => '230001', 'action' => 'set_daily_budget', 'payload' => ['daily_budget' => 55]]);

        $this->assertSame('executed', $status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/230001') && $r['daily_budget'] === 5500);
        $this->assertSame(55.0, (float) $this->adSet->fresh()->daily_budget);
    }

    public function test_a_platform_refusal_ends_failed_with_its_words_and_changes_nothing_locally(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#100) Budget is set at the ad set level', 'code' => 100]], 400)]);

        $status = $this->proposeAndApprove(['target_type' => 'campaign', 'target_external_id' => '120001', 'action' => 'set_daily_budget', 'payload' => ['daily_budget' => 110]]);

        $this->assertSame('failed', $status);
        $this->assertSame(100.0, (float) $this->campaign->fresh()->daily_budget);
        $this->assertStringContainsString('provider_refused', (string) ActionLog::withoutGlobalScopes()->where('phase', 'failed')->sole()->api_response['error']);
    }

    public function test_a_provider_not_enabled_for_execution_is_blocked_before_any_call(): void
    {
        config()->set('agents.execute_providers', ['google']);
        Http::fake();

        $status = $this->proposeAndApprove(['target_type' => 'campaign', 'target_external_id' => '120001', 'action' => 'pause']);

        $this->assertSame('blocked', $status);
        Http::assertNothingSent();
    }

    private function member(string $email, Role $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }
}
