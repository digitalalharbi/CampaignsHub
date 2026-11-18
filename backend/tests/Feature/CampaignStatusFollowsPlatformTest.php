<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\TikTokCampaignStatus;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TIKTOK-CAMPAIGN-STATUS-001 review — when a visible campaign follows its platform's status, and when it must not.
 */
final class CampaignStatusFollowsPlatformTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'act_'.$provider, 'name' => $provider, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param list<array<string,mixed>> $rows */
    private function import(ExternalAccount $account, array $rows): void
    {
        app(ImportExternalCampaigns::class)->execute($account, SyncResult::of($rows), (string) $this->project->id);
    }

    private function unifiedOf(string $externalId): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->findOrFail(
            ExternalCampaign::withoutGlobalScopes()->where('external_id', $externalId)->value('unified_campaign_id'));
    }

    public function test_a_non_tiktok_platform_is_followed_too(): void
    {
        $snap = $this->account('snapchat');
        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'ACTIVE']]);
        $this->assertSame('active', $this->unifiedOf('s1')->status);

        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'PAUSED']]);
        $this->assertSame('paused', $this->unifiedOf('s1')->status);
    }

    /** Several platform campaigns grouped under one visible campaign carry a person's decision: not followed. */
    public function test_a_group_of_platform_campaigns_is_not_followed(): void
    {
        $snap = $this->account('snapchat');
        $meta = $this->account('meta');
        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'ACTIVE']]);
        $this->import($meta, [['id' => 'm1', 'name' => 'Meta', 'status' => 'ACTIVE']]);
        $group = $this->unifiedOf('s1');
        ExternalCampaign::withoutGlobalScopes()->where('external_id', 'm1')->update(['unified_campaign_id' => $group->id]);

        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'PAUSED']]);

        $this->assertSame('active', $group->fresh()->status);
    }

    /** An answer that is not a state — undocumented, missing — never overwrites a known status. */
    public function test_unknown_is_never_followed(): void
    {
        $snap = $this->account('snapchat');
        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'ACTIVE']]);
        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'SOMETHING_NEW']]);
        $this->assertSame('active', $this->unifiedOf('s1')->status);

        $this->import($snap, [['id' => 's1', 'name' => 'Snap']]);
        $this->assertSame('active', $this->unifiedOf('s1')->status);
    }

    /** A campaign the scope cleanup adopted is as much the platform's as one the sync adopted. */
    public function test_a_campaign_adopted_by_the_scope_cleanup_is_followed(): void
    {
        $snap = $this->account('snapchat');
        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'ACTIVE']]);
        $unified = $this->unifiedOf('s1');
        $unified->forceFill(['meta' => ['adopted_from' => 'scope-cleanup']])->save();

        $this->import($snap, [['id' => 's1', 'name' => 'Snap', 'status' => 'PAUSED']]);

        $this->assertSame('paused', $unified->fresh()->status);
    }

    /**
     * LinkedIn documents CANCELED («permanently canceled») and PENDING_DELETION («requested to be deleted»): neither is a
     * campaign waiting to run. Source: Microsoft Learn, LinkedIn Marketing API, «Create and Manage Campaigns», `status`.
     */
    public function test_linkedin_canceled_and_pending_deletion_are_not_pending_or_unknown(): void
    {
        $this->assertSame(CampaignStatus::Archived, CampaignStatus::fromProvider('canceled'));
        $this->assertSame(CampaignStatus::Archived, CampaignStatus::fromProvider('CANCELED'));
        $this->assertSame(CampaignStatus::Archived, CampaignStatus::fromProvider('pending_deletion'));
        $this->assertSame(CampaignStatus::Archived, CampaignStatus::fromProvider('REMOVED'));
        $this->assertSame(CampaignStatus::Pending, CampaignStatus::fromProvider('DRAFT'));
        // «inactive» contains «active»; it is not running.
        $this->assertSame(CampaignStatus::Paused, CampaignStatus::fromProvider('inactive'));
    }

    /**
     * TikTok's CAMPAIGN_STATUS_BUDGET_EXCEED is a daily budget spent — the campaign resumes tomorrow. Mapping it to «paused»
     * made a running campaign flap every day, closing its alerts at night and reopening them in the morning.
     */
    public function test_a_tiktok_daily_budget_spent_stays_running_and_its_alert_stays_open(): void
    {
        $this->assertSame('active', TikTokCampaignStatus::resolve('ENABLE', 'CAMPAIGN_STATUS_BUDGET_EXCEED')['status']);
        $this->assertSame('budget_exhausted', TikTokCampaignStatus::resolve('ENABLE', 'CAMPAIGN_STATUS_BUDGET_EXCEED')['signal']);

        /*
         * ALERT-DELIVERING-YESTERDAY-001 judges «yesterday» on the client's clock (Asia/Riyadh). The spend was written on the
         * SERVER's yesterday, which from 21:00 UTC is the client's day before yesterday — so after 21:00 UTC the campaign had
         * not delivered «yesterday» and no alert was raised (main went red at 21:18 UTC). The clock is pinned inside that
         * window, and the spend is written on the client's yesterday.
         */
        Carbon::setTestNow(Carbon::parse('2026-11-13 21:30:00', 'UTC'));

        $tiktok = $this->account('tiktok');
        $this->import($tiktok, [['id' => 't1', 'name' => 'TT', 'status' => TikTokCampaignStatus::resolve('ENABLE', 'CAMPAIGN_STATUS_ENABLE')['status'], 'objective' => 'WEB_CONVERSIONS']]);
        $campaign = $this->unifiedOf('t1');
        $campaign->forceFill(['objective' => 'sales'])->save();
        foreach (['spend' => 500, 'conversions' => 0] as $key => $value) {
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $tiktok->id, 'external_campaign_id' => ExternalCampaign::withoutGlobalScopes()->where('external_id', 't1')->value('id'),
                'unified_campaign_id' => $campaign->id, 'provider' => 'tiktok', 'metric_key' => $key,
                'metric_date' => Carbon::now('Asia/Riyadh')->subDay()->toDateString(), 'value' => $value, 'project_currency' => 'SAR',
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
        }
        $rule = AlertRule::create(['tenant_id' => $this->tenant->id, 'type' => 'no_results', 'name' => 'NR', 'cooldown_minutes' => 720,
            'channels' => ['in_app'], 'severity' => 'warning', 'active' => true, 'threshold' => ['days' => 3]]);
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));

        // The day's budget runs out: the campaign is still running, and its alert is neither closed nor raised again.
        $this->import($tiktok, [['id' => 't1', 'name' => 'TT', 'status' => TikTokCampaignStatus::resolve('ENABLE', 'CAMPAIGN_STATUS_BUDGET_EXCEED')['status'], 'objective' => 'WEB_CONVERSIONS']]);
        $this->assertSame('active', $campaign->fresh()->status);
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->sole();
        $this->assertSame('open', $event->status);
        $this->assertArrayNotHasKey('closed_reason', (array) $event->context);
    }

    /** An account TikTok suspended is not running, and says why — never a silent «paused». */
    public function test_a_suspended_tiktok_account_is_not_running_and_is_named(): void
    {
        $state = TikTokCampaignStatus::resolve('DISABLE', 'ADVERTISER_ACCOUNT_PUNISH');

        $this->assertSame('paused', $state['status']);
        $this->assertSame('account_suspended', $state['signal']);
        $this->assertNull(TikTokCampaignStatus::resolve('ENABLE', 'CAMPAIGN_STATUS_ENABLE')['signal']);
    }
}
