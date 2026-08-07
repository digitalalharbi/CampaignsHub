<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Command-center per-campaign metrics: each campaign's summary returns ONLY its own numbers, and a
 * cross-project / unknown campaign id fails closed (404). This is the isolation the whole page rests on.
 */
final class CampaignMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    private UnifiedCampaign $campA1;

    private UnifiedCampaign $campA2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        // Scope dies with the request since ADR 0002; this test creates rows directly
        // between requests, so it holds its tenant for the whole test.
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);

        $this->campA1 = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'A1', 'objective' => 'sales', 'status' => 'active']);
        $this->campA2 = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'A2', 'objective' => 'sales', 'status' => 'active']);

        // Distinct metrics per campaign so a leak is unmistakable.
        $this->seedMetrics($this->campA1->id, spend: 1000, conv: 10, rev: 4000);
        $this->seedMetrics($this->campA2->id, spend: 500, conv: 4, rev: 1500);
    }

    private function seedMetrics(string $campaignId, float $spend, float $conv, float $rev): void
    {
        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s.$campaignId);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->tenant->id, projectId: $this->projectA->id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: 'meta', metricKey: $k, metricDate: Carbon::parse('2026-06-15'),
            value: $v, unifiedCampaignId: $campaignId,
        );
        app(UpsertDailyMetrics::class)->handle([
            $m('impressions', 10000), $m('clicks', 200), $m('conversions', $conv), $m('spend', $spend), $m('revenue', $rev),
        ]);
    }

    private function url(Project $p, string $campaignId, string $section = 'summary'): string
    {
        return "/api/v1/projects/{$p->id}/campaigns/{$campaignId}/{$section}?from=2026-06-01&to=2026-06-30";
    }

    public function test_each_campaign_summary_is_scoped_to_itself(): void
    {
        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id))->assertOk()->json('data.current');
        $a2 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA2->id))->assertOk()->json('data.current');

        $this->assertEqualsWithDelta(1000, $a1['spend'], 0.01);
        $this->assertEqualsWithDelta(10, $a1['conversions'], 0.01);
        $this->assertEqualsWithDelta(500, $a2['spend'], 0.01);
        $this->assertEqualsWithDelta(4, $a2['conversions'], 0.01);
        // ROAS is derived per campaign, not shared.
        $this->assertEqualsWithDelta(4.0, $a1['roas'], 0.01);
        $this->assertEqualsWithDelta(3.0, $a2['roas'], 0.01);
    }

    /**
     * The campaign funnel answers the SAME shape as the project funnel.
     *
     * `data` is the stage list; the spend those stages are derived from rides in `meta`. That is the
     * contract `MetricsController::funnel` set under UNIFIED-002, and the browser has one type for
     * both endpoints — so a second shape here is not a cosmetic difference. It crashed the campaign's
     * funnel tab outright (`stages.filter is not a function`) and replaced the whole page with a raw
     * stack trace, because a component cannot defend against a payload the type system told it was
     * a list.
     *
     * Asserted against the project endpoint rather than against a literal, so the two cannot drift
     * apart again by one of them being updated alone.
     */
    public function test_the_campaign_funnel_answers_the_same_shape_as_the_project_funnel(): void
    {
        $window = 'from=2026-06-01&to=2026-06-30';

        $campaign = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/funnel?{$window}")
            ->assertOk();

        $project = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/funnel?{$window}")
            ->assertOk();

        // A LIST, not an object wrapping one. `array_is_list` is the assertion that fails on the
        // previous code: `{stages: [...], spend: …}` is an array in PHP and is not a list.
        $stages = $campaign->json('data');
        $this->assertIsArray($stages);
        $this->assertTrue(array_is_list($stages), 'the campaign funnel must answer a stage list');
        $this->assertSame(array_keys($project->json('data')[0] ?? []), array_keys($stages[0] ?? []));

        // Every stage the funnel names, in order, and the spend beside them rather than among them.
        $this->assertSame(
            ['impressions', 'clicks', 'landing_page_views', 'add_to_cart', 'checkout', 'purchases'],
            array_column($stages, 'stage'),
        );
        $this->assertArrayNotHasKey('spend', $stages[0]);
        $this->assertEqualsWithDelta(1000, $campaign->json('meta.spend'), 0.01);
    }

    public function test_cross_project_campaign_id_fails_closed(): void
    {
        // campA1 belongs to project A; requesting it under project B must 404 (project scope).
        $this->actingAs($this->owner)
            ->getJson($this->url($this->projectB, $this->campA1->id))
            ->assertNotFound();

        // Unknown id → 404.
        $this->actingAs($this->owner)
            ->getJson($this->url($this->projectA, (string) Uuid::uuid4()))
            ->assertNotFound();
    }

    public function test_requires_view_permission(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => 's@a.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $this->tenant);
        $this->actingAs($stranger)
            ->getJson($this->url($this->projectA, $this->campA1->id))
            ->assertForbidden();
    }

    public function test_activity_timeline_is_scoped_to_the_campaign(): void
    {
        AuditLog::create([
            'tenant_id' => $this->tenant->id, 'action' => 'campaign.updated',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA1->id,
            'before' => ['total_budget' => 100], 'after' => ['total_budget' => 200],
        ]);
        AuditLog::create([
            'tenant_id' => $this->tenant->id, 'action' => 'campaign.paused',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA2->id,
        ]);

        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'activity'))->assertOk()->json('data');
        $this->assertCount(1, $a1);
        $this->assertSame('campaign.updated', $a1[0]['action']);

        // Cross-project campaign id → 404 (never another project's timeline).
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'activity'))->assertNotFound();
    }

    public function test_alerts_are_scoped_to_the_campaign(): void
    {
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'user_id' => $this->owner->id,
            'type' => 'alert', 'severity' => 'critical', 'title' => 'CPA high',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA1->id, 'status' => 'unread',
        ]);
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'user_id' => $this->owner->id,
            'type' => 'alert', 'severity' => 'info', 'title' => 'Other',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => (string) $this->campA2->id, 'status' => 'unread',
        ]);

        $a1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'alerts'))->assertOk()->json('data');
        $this->assertCount(1, $a1);
        $this->assertSame('CPA high', $a1[0]['title']);
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'alerts'))->assertNotFound();
    }

    public function test_reports_are_scoped_to_the_campaign(): void
    {
        Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'campaign_id' => (string) $this->campA1->id,
            'name' => 'Campaign monthly', 'type' => 'monthly', 'status' => 'completed', 'currency' => 'SAR', 'audience' => 'client',
        ]);
        Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'campaign_id' => (string) $this->campA2->id,
            'name' => 'Other campaign', 'type' => 'monthly', 'status' => 'completed', 'currency' => 'SAR', 'audience' => 'client',
        ]);

        $r1 = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'reports'))->assertOk()->json('data');
        $this->assertCount(1, $r1);
        $this->assertSame('Campaign monthly', $r1[0]['name']);
        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'reports'))->assertNotFound();
    }

    public function test_annotation_create_and_approval_workflow(): void
    {
        $base = "/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/annotations";

        // Create a recommendation (starts as draft).
        $id = $this->actingAs($this->owner)->postJson($base, [
            'kind' => 'recommendation', 'title' => 'Scale Google', 'evidence' => 'ROAS 8.4x vs 4.9x', 'priority' => 'high',
        ])->assertCreated()->json('data.id');
        $this->assertSame('draft', CampaignAnnotation::find($id)->status);

        // Approve it (owner has reports.approve).
        $this->actingAs($this->owner)->patchJson("{$base}/{$id}", ['status' => 'approved'])->assertOk();
        $this->assertSame('approved', CampaignAnnotation::find($id)->status);

        // A user without reports.approve cannot change status.
        $editor = User::create(['name' => 'E', 'email' => 'e@a.test', 'password' => 'secret123']);
        $this->grantMembership($editor, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ed', 'slug' => 'ed']);
        $role->givePermissionTo(...Permission::whereIn('key', ['campaigns.view', 'campaigns.update'])->pluck('key')->all());
        $editor->assignRole($role);
        $this->actingAs($editor)->patchJson("{$base}/{$id}", ['status' => 'rejected'])->assertForbidden();

        // Listing is campaign-scoped.
        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->projectB->id}/campaigns/{$this->campA1->id}/annotations")->assertNotFound();
    }

    public function test_creatives_are_scoped_and_ranked_by_objective(): void
    {
        // Two creatives on campA1 with distinct ROAS (sales objective ranks by ROAS).
        $this->creative($this->campA1, 'Winner', spend: 100, rev: 800, conv: 8);   // ROAS 8
        $this->creative($this->campA1, 'Loser', spend: 100, rev: 200, conv: 2);    // ROAS 2
        $this->creative($this->campA2, 'Other', spend: 100, rev: 500, conv: 5);

        $data = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'creatives'))->assertOk()->json('data');
        $this->assertCount(2, $data);                       // only campA1's creatives
        $this->assertSame('Winner', $data[0]['name']);       // ranked first by ROAS
        $this->assertSame('top_performing', $data[0]['classification']);

        $this->actingAs($this->owner)->getJson($this->url($this->projectB, $this->campA1->id, 'creatives'))->assertNotFound();
    }

    private function creative(UnifiedCampaign $campaign, string $name, float $spend, float $rev, float $conv): void
    {
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $campaign->project_id, 'campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_creative_id' => Str::uuid()->toString(), 'name' => $name, 'format' => 'image', 'status' => 'active',
        ]);
        // 30 days of identical metrics so impressions>100 (avoids insufficient_data).
        for ($d = 0; $d < 30; $d++) {
            DB::table('creative_daily_metrics')->insert([
                'id' => Str::uuid()->toString(), 'tenant_id' => $this->tenant->id, 'project_id' => $campaign->project_id,
                'creative_id' => $creative->id, 'campaign_id' => $campaign->id,
                'metric_date' => Carbon::parse('2026-06-01')->addDays($d)->toDateString(),
                'spend' => $spend, 'impressions' => 500, 'clicks' => 20, 'conversions' => $conv, 'revenue' => $rev,
                'video_views' => 0, 'video_completions' => 0, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * CAMPDET-010: the events section reports what was actually RECORDED — no zero-count rows padding
     * the list, and cost-per-event only when there was spend to divide.
     */
    public function test_events_returns_only_recorded_events_with_cost_per_event(): void
    {
        $uid = fn (string $x) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $x.$this->campA1->id);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->tenant->id, projectId: $this->projectA->id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: 'meta', metricKey: $k, metricDate: Carbon::parse('2026-06-15'),
            value: $v, unifiedCampaignId: $this->campA1->id,
        );
        app(UpsertDailyMetrics::class)->handle([$m('purchases', 8), $m('leads', 0)]);

        $res = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA1->id, 'events'))->assertOk();

        $keys = array_column($res->json('data.events'), 'key');
        $this->assertContains('purchases', $keys);
        $this->assertContains('conversions', $keys);
        $this->assertNotContains('leads', $keys, 'a zero-count event must not be listed');
        $this->assertNotContains('installs', $keys);

        $purchases = collect($res->json('data.events'))->firstWhere('key', 'purchases');
        $this->assertEquals(8.0, $purchases['count']);
        $this->assertEquals(125.0, $purchases['cost_per']);   // spend 1000 / 8 purchases

        // Campaign-scoped: campaign A2 never recorded purchases.
        $other = $this->actingAs($this->owner)->getJson($this->url($this->projectA, $this->campA2->id, 'events'))->assertOk();
        $this->assertNotContains('purchases', array_column($other->json('data.events'), 'key'));
    }

    /**
     * CAMPDET-010: the sync log is the audit trail behind "last synced". A campaign with no linked
     * external campaign has no sync history at all — it must say so rather than showing the project's runs.
     */
    public function test_sync_log_returns_runs_for_linked_accounts_only_and_surfaces_failures(): void
    {
        // A real account chain — the FK to external_accounts is what makes the sync log auditable.
        // Scope dies with the request since ADR 0002; this test creates rows directly
        // between requests, so it holds its tenant for the whole test.
        $this->holdingTenant((string) $this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token-meta');
        $credential->save();
        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta connection', 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Ad account', 'status' => 'active',
        ]);
        $accountId = $account->id;
        app(TenantContext::class)->forget();

        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'unified_campaign_id' => $this->campA1->id, 'external_account_id' => $accountId,
            'provider' => 'meta', 'external_id' => 'ext-1', 'name' => 'Ext', 'status' => 'active',
        ]);

        MetricSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_account_id' => $accountId, 'provider' => 'meta', 'status' => 'failed',
            'window_start' => '2026-06-01', 'window_end' => '2026-06-30',
            'started_at' => now(), 'finished_at' => now(), 'error' => 'token expired',
        ]);
        // A run for an account this campaign is NOT linked to must never appear.
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_account_id' => null, 'provider' => 'tiktok', 'status' => 'success',
            'window_start' => '2026-06-01', 'window_end' => '2026-06-30',
        ]);

        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/sync-log")
            ->assertOk();

        $runs = $res->json('data.runs');
        $this->assertCount(1, $runs);
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertSame('token expired', $runs[0]['error'], 'a failure must be shown, not hidden');
        $this->assertSame(1, $res->json('data.linked_accounts'));

        // Unlinked campaign → honest empty history.
        $empty = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA2->id}/sync-log")
            ->assertOk();
        $this->assertSame(0, $empty->json('data.linked_accounts'));
        $this->assertSame([], $empty->json('data.runs'));
    }

    /**
     * CAMPDET-010, extended by STRUCT-001: the structure endpoint must tell FOUR situations apart,
     * because "empty" for four different reasons is four different instructions to the user.
     *
     * The fourth — the platform holds no credentials on this install — used to be indistinguishable
     * from «never synced», which sent the reader to press a discovery button that could not possibly
     * have worked.
     */
    public function test_structure_distinguishes_not_linked_from_awaiting_credentials_from_not_synced_from_ready(): void
    {
        // 1) Nothing linked at all.
        $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_linked');

        // 2) Linked, but the hierarchy was never pulled.
        // Scope dies with the request since ADR 0002; this test creates rows directly
        // between requests, so it holds its tenant for the whole test.
        $this->holdingTenant((string) $this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'm', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_9', 'name' => 'A', 'status' => 'active',
        ]);
        $external = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'unified_campaign_id' => $this->campA1->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => 'c-9', 'name' => 'Ext', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        // 2a) Linked, but Meta holds no credentials here — so nothing could ever have been pulled,
        // and the answer is not «press sync».
        $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_credentials')
            ->assertJsonPath('data.awaiting_credentials', ['meta']);

        // 2b) With credentials in place, the same emptiness means the discovery has not run yet.
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_synced')
            ->assertJsonPath('data.awaiting_credentials', []);

        // 3) The hierarchy exists.
        $adSet = ExternalAdSet::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_campaign_id' => $external->id, 'unified_campaign_id' => $this->campA1->id,
            'provider' => 'meta', 'external_id' => 'as-1', 'name' => 'Core audience', 'status' => 'active',
            'optimization_goal' => 'conversions', 'daily_budget' => 500, 'currency' => 'SAR',
            'targeting' => ['countries' => ['SA']], 'source_type' => 'api',
        ]);
        ExternalAd::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_ad_set_id' => $adSet->id, 'external_campaign_id' => $external->id,
            'unified_campaign_id' => $this->campA1->id, 'provider' => 'meta',
            'external_id' => 'ad-1', 'name' => 'Ad one', 'status' => 'active', 'review_status' => 'rejected',
        ]);

        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'ready');

        $this->assertSame('Core audience', $res->json('data.ad_sets.0.name'));
        $this->assertSame(['SA'], $res->json('data.ad_sets.0.targeting.countries'));
        // A rejected ad is surfaced, not filtered out — that is exactly what an operator needs to see.
        $this->assertSame('rejected', $res->json('data.ad_sets.0.ads.0.review_status'));
        $this->assertFalse($res->json('data.ad_sets.0.is_demo'));

        // Another campaign's structure never leaks in.
        $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA2->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_linked');
    }

    /**
     * STRUCT-001 — an ad with no ad set is still an ad, and must not disappear.
     *
     * LinkedIn has no ad-set level, so its ads hang directly off the campaign. A reader that only
     * walked the ad sets would show a LinkedIn campaign as empty while its ads sat in the table —
     * which is the bug the nullable column would otherwise have introduced.
     */
    public function test_an_ad_with_no_ad_set_is_returned_beside_the_ad_sets_rather_than_hidden(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'linkedin', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'linkedin', 'connection_name' => 'l', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'linkedin',
            'account_type' => 'ad_account', 'external_id' => 'li_1', 'name' => 'L', 'status' => 'active',
        ]);
        $external = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'unified_campaign_id' => $this->campA1->id, 'external_account_id' => $account->id,
            'provider' => 'linkedin', 'external_id' => '771', 'name' => 'Leads', 'status' => 'active',
        ]);
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_campaign_id' => $external->id, 'provider' => 'linkedin',
            'external_creative_id' => '991', 'name' => 'Creative 991', 'format' => 'image', 'source_type' => 'api',
        ]);
        ExternalAd::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'external_ad_set_id' => null, 'external_campaign_id' => $external->id,
            'unified_campaign_id' => $this->campA1->id, 'creative_id' => $creative->id, 'provider' => 'linkedin',
            'external_id' => '991', 'name' => 'Creative 991', 'status' => 'active', 'source_type' => 'api',
        ]);
        app(TenantContext::class)->forget();

        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure")
            ->assertOk()
            ->assertJsonPath('data.state', 'ready');

        $this->assertSame([], $res->json('data.ad_sets'));
        $this->assertSame('991', $res->json('data.ads_without_ad_set.0.external_id'));
        $this->assertSame('image', $res->json('data.ads_without_ad_set.0.creative.format'));
        // The platform sent no thumbnail, so none was invented.
        $this->assertNull($res->json('data.ads_without_ad_set.0.creative.thumbnail_url'));
    }

    /**
     * STRUCT-001 — the discovery button QUEUES the same job the scheduler queues.
     *
     * It never fetches inline: a platform call behind a button is how a page hangs for thirty seconds
     * and then times out with the work half done.
     */
    public function test_asking_for_structure_now_queues_the_scheduled_job_and_refuses_an_unlinked_campaign(): void
    {
        Queue::fake();

        // An unlinked campaign has nothing to discover, and says so instead of queueing nothing.
        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA2->id}/structure/sync")
            ->assertStatus(422);
        Queue::assertNothingPushed();

        $this->holdingTenant((string) $this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'm', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_9', 'name' => 'A', 'status' => 'active',
        ]);
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id,
            'unified_campaign_id' => $this->campA1->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => 'c-9', 'name' => 'Ext', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Queue::assertPushed(SyncAccountStructureJob::class, 1);

        // A revoked authorisation is refused rather than queued into a guaranteed failure row.
        $this->holdingTenant((string) $this->tenant->id);
        $connection->update(['status' => 'revoked']);
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$this->campA1->id}/structure/sync")
            ->assertStatus(422);

        Queue::assertPushed(SyncAccountStructureJob::class, 1);
    }
}
