<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SEC-CLUSTER-2 — every stored metric row belongs to the project the account is ACTIVELY bound to
 * at the moment it is written, and a re-bind never rewrites the old project's history.
 *
 * The rule, in one place: the writer asks `AccountAssignment::projectIdFor()` (the same resolver the
 * run row, the structure sync and the sweeps use), never the campaign / ad-set / ad row it happens to
 * find, because those rows are only re-filed when the structure sync succeeds. A row that is still
 * filed under another project is skipped and reported on the run, not stamped.
 *
 * And the natural key of `daily_metrics` / `entity_daily_metrics` includes the project, so a window
 * the old project already holds is a DIFFERENT row from the same window under the new project.
 */
final class MetricsWriteFollowsActiveBindingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    private ExternalAdSet $adSet;

    private ExternalAd $ad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'default_currency' => 'USD',
        ]);
        $this->projectA = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Meta', 'status' => 'active',
            'currency' => 'USD', 'timezone' => 'Asia/Riyadh', 'discovered_at' => Carbon::now(),
        ]);

        $this->bind($this->projectA);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
        $this->adSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_campaign_id' => $this->campaign->id,
            'provider' => 'meta', 'external_id' => 'adset-1', 'name' => 'Set', 'status' => 'active',
        ]);
        $this->ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_ad_set_id' => $this->adSet->id,
            'external_campaign_id' => $this->campaign->id, 'provider' => 'meta', 'external_id' => 'ad-1', 'name' => 'Ad', 'status' => 'active',
        ]);
    }

    public function test_a_sync_after_a_rebind_but_before_the_refile_stamps_nothing_with_the_old_project(): void
    {
        $this->moveBindingTo($this->projectB, refile: false);
        $this->fakeMeta(1200);

        $run = $this->sync();

        $this->assertSame($this->projectB->id, $run->project_id);
        $this->assertSame(
            0,
            DB::table('daily_metrics')->count(),
            'rows were written under '.json_encode(DB::table('daily_metrics')->pluck('project_id')->unique()->values()).' while the account is bound to B',
        );
        $this->assertSame(0, DB::table('entity_daily_metrics')->count());
        $this->assertNotSame('success', $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status, 'a run that stored nothing must not read as a success');
    }

    public function test_entity_rows_follow_the_binding_not_a_stale_ad_set_row(): void
    {
        // The campaign was re-filed, the ad set and the ad were not (a partial structure sync).
        $this->moveBindingTo($this->projectB, refile: false);
        ExternalCampaign::withoutGlobalScopes()->whereKey($this->campaign->id)->update(['project_id' => $this->projectB->id]);
        $this->fakeMeta(1200);

        $this->sync();

        $this->assertSame(0, DB::table('entity_daily_metrics')->count(), 'ad-set / ad rows were stamped with the project the account left');
        $this->assertGreaterThan(0, DB::table('daily_metrics')->where('project_id', $this->projectB->id)->count());
    }

    public function test_a_resync_after_a_rebind_keeps_the_old_history_and_gives_the_new_project_its_own_rows(): void
    {
        $this->fakeMeta(1200);
        $this->sync();
        $this->assertGreaterThan(0, DB::table('daily_metrics')->where('project_id', $this->projectA->id)->count());
        $this->assertGreaterThan(0, DB::table('entity_daily_metrics')->where('project_id', $this->projectA->id)->count());
        $oldRun = DB::table('daily_metrics')->where('project_id', $this->projectA->id)->value('sync_run_id');

        $this->moveBindingTo($this->projectB, refile: true);
        $this->fakeMeta(700);
        $this->sync();

        // A's rows: untouched — same figure, same run, still A.
        $aSpend = DB::table('daily_metrics')->where('project_id', $this->projectA->id)->where('metric_key', 'spend')->get();
        $this->assertCount(1, $aSpend);
        $this->assertEqualsWithDelta(1200, (float) $aSpend[0]->value, 0.01, "the old project's history was overwritten by the new project's sync");
        $this->assertSame($oldRun, $aSpend[0]->sync_run_id);

        // B's rows: its own, for the very same date.
        $bSpend = DB::table('daily_metrics')->where('project_id', $this->projectB->id)->where('metric_key', 'spend')->get();
        $this->assertCount(1, $bSpend, 'the new project received nothing for a date the old project already held');
        $this->assertEqualsWithDelta(700, (float) $bSpend[0]->value, 0.01);

        // Same on the ad-set / ad grain.
        $this->assertSame(1, DB::table('entity_daily_metrics')->where('project_id', $this->projectA->id)->where('entity_type', 'ad_set')->count());
        $this->assertSame(1, DB::table('entity_daily_metrics')->where('project_id', $this->projectB->id)->where('entity_type', 'ad_set')->count());
        $this->assertEqualsWithDelta(1200, (float) DB::table('entity_daily_metrics')->where('project_id', $this->projectA->id)->where('entity_type', 'ad_set')->value('spend'), 0.01);
        $this->assertEqualsWithDelta(700, (float) DB::table('entity_daily_metrics')->where('project_id', $this->projectB->id)->where('entity_type', 'ad_set')->value('spend'), 0.01);
    }

    public function test_an_ordinary_resync_still_updates_in_place(): void
    {
        $this->fakeMeta(1200);
        $this->sync();
        $this->fakeMeta(1500);
        $this->sync();

        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->count());
        $this->assertEqualsWithDelta(1500, (float) DB::table('daily_metrics')->where('metric_key', 'spend')->value('value'), 0.01);
        $this->assertSame(1, DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->count());
    }

    public function test_creative_metrics_land_in_the_creative_of_the_project_the_account_is_bound_to(): void
    {
        $creativeA = $this->creative($this->projectA);
        $creativeB = $this->creative($this->projectB);
        $this->moveBindingTo($this->projectB, refile: true);

        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 9.0, 'impressions' => 100],
        ]);

        $this->assertSame(0, $out['ambiguous'], 'a moved account made its own creative id ambiguous');
        $this->assertSame(1, $out['upserted']);
        $this->assertSame(1, DB::table('creative_daily_metrics')->where('creative_id', $creativeB->id)->count());
        $this->assertSame(0, DB::table('creative_daily_metrics')->where('creative_id', $creativeA->id)->count(), 'the project the account left received new creative figures');
    }

    public function test_creative_metrics_are_not_written_into_a_creative_filed_under_the_project_the_account_left(): void
    {
        $creativeA = $this->creative($this->projectA);
        $this->moveBindingTo($this->projectB, refile: true);

        $out = app(UpsertCreativeDailyMetrics::class)->execute($this->account, [
            ['campaign_id' => 'cr-1', 'date' => '2026-08-01', 'spend' => 9.0],
        ]);

        $this->assertSame(0, $out['upserted']);
        $this->assertSame(0, DB::table('creative_daily_metrics')->where('creative_id', $creativeA->id)->count());
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function sync(): MetricSyncRun
    {
        return app(AccountMetricsSyncer::class)->sync($this->account->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));
    }

    private function bind(Project $project): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'purpose' => 'advertising',
            'is_active' => true, 'campaign_management_enabled' => true,
        ]);
    }

    private function moveBindingTo(Project $project, bool $refile): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);
        $this->bind($project);

        if ($refile) {
            foreach ([ExternalCampaign::class, ExternalAdSet::class, ExternalAd::class] as $model) {
                $model::withoutGlobalScopes()->update(['project_id' => $project->id]);
            }
        }
    }

    private function creative(Project $project): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->account->id,
            'external_campaign_id' => $this->campaign->id, 'provider' => 'meta',
            'external_creative_id' => 'cr-1', 'name' => 'Creative', 'format' => 'image',
        ]);
    }

    private ?float $spend = null;

    private function fakeMeta(float $spend): void
    {
        // Http::fake() merges stubs and the FIRST match wins, so the closure reads a property instead
        // of being registered again.
        $first = $this->spend === null;
        $this->spend = $spend;
        if (! $first) {
            return;
        }

        Http::fake(function ($request) {
            $spend = $this->spend;
            $url = $request->url();
            if (! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }
            $row = ['campaign_id' => 'cmp-1', 'date_start' => '2026-08-01', 'spend' => (string) $spend, 'impressions' => '40000', 'clicks' => '800'];
            if (str_contains($url, 'level=adset')) {
                return Http::response(['data' => [[...$row, 'adset_id' => 'adset-1']]], 200);
            }
            if (str_contains($url, 'level=ad&') || str_contains($url, 'level=ad%')) {
                return Http::response(['data' => [[...$row, 'ad_id' => 'ad-1', 'adset_id' => 'adset-1']]], 200);
            }

            return Http::response(['data' => [$row]], 200);
        });
    }
}
