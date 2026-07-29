<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * DEMO-001 — give the demo tenant the integration chain it was missing.
 *
 * The demo analytics already wrote 90 days of metrics, but there were no `external_accounts` or
 * `external_campaigns` rows behind them. The consequence was visible everywhere: every campaign showed
 * "not linked to any ad platform", the platforms tab had nothing to draw, and the campaign sync log was
 * permanently empty even though sync runs existed — they just belonged to no account.
 *
 * This seeder builds the real chain — credential → connection → ad account → external campaign — and
 * attaches the existing sync runs to those accounts, so the integration surfaces can be reviewed live.
 *
 * Every row it creates is explicitly DEMO: connections are named «(بيانات تجريبية)», accounts carry
 * `metadata.is_demo`, and the sync runs keep `is_demo = true`. Nothing here is presented as a real
 * platform connection, and no credential value is fabricated — the stored payload is a labelled
 * placeholder, so these connections cannot be mistaken for working production credentials.
 */
final class DemoIntegrationsSeeder extends Seeder
{
    private const NS = 'campaignshub-demo-integrations';

    /** provider => [account external id, display name, currency] */
    private const ACCOUNTS = [
        'meta' => ['act_1000000001', 'Meta Ads — متجر تجريبي', 'SAR'],
        'google' => ['123-456-7890', 'Google Ads — متجر تجريبي', 'SAR'],
        'tiktok' => ['tt_7000000001', 'TikTok Ads — متجر تجريبي', 'SAR'],
        'snapchat' => ['snap_900000001', 'Snapchat Ads — متجر تجريبي', 'SAR'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-agency')->first();
        if ($tenant === null) {
            return; // no demo tenant → nothing to attach
        }

        app(TenantContext::class)->setTenantId($tenant->id);

        // The demo tenant has several projects; the integration chain belongs to the one the analytics
        // demo actually populated — the project holding the most campaigns. Picking "the first project"
        // silently attached everything to an empty journey project.
        $projectId = UnifiedCampaign::withoutGlobalScopes()
            ->join('projects', 'projects.id', '=', 'unified_campaigns.project_id')
            ->where('projects.tenant_id', $tenant->id)
            ->groupBy('unified_campaigns.project_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('unified_campaigns.project_id');

        $project = $projectId ? Project::withoutGlobalScopes()->find($projectId) : null;
        if ($project === null) {
            app(TenantContext::class)->forget();

            return;
        }

        $campaigns = UnifiedCampaign::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->get(['id', 'name', 'client_workspace_id', 'status', 'objective', 'total_budget', 'budget_currency', 'meta']);

        // Re-running after a fix must not leave the previous (wrong) links behind.
        ExternalCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('project_id', '!=', $project->id)
            ->whereRaw("raw->>'source' = 'demo-seeder'")
            ->delete();

        $accountsByProvider = [];

        foreach (self::ACCOUNTS as $provider => [$externalId, $name, $currency]) {
            $credential = IntegrationCredential::withoutGlobalScopes()->firstOrNew(['id' => $this->uuid("cred:{$provider}")]);
            if (! $credential->exists) {
                $credential->forceFill([
                    'id' => $this->uuid("cred:{$provider}"),
                    'tenant_id' => $tenant->id,
                    'provider' => $provider,
                    'credential_scope' => 'project_only',
                    'credential_type' => 'oauth',
                    'status' => 'active',
                ]);
                // A labelled placeholder, never a plausible-looking token.
                $credential->setPayload('DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN');
                $credential->save();
            }

            $connection = ProviderConnection::withoutGlobalScopes()->updateOrCreate(
                ['id' => $this->uuid("conn:{$provider}")],
                [
                    'tenant_id' => $tenant->id,
                    'credential_id' => $credential->id,
                    'provider' => $provider,
                    'connection_name' => ucfirst($provider).' — بيانات تجريبية',
                    'scope' => 'project_only',
                    'status' => 'connected',
                    'last_health_check_at' => Carbon::now()->subHours(2),
                    'last_successful_sync_at' => $provider === 'meta' ? Carbon::now()->subDays(1) : Carbon::now()->subHours(3),
                    'last_error' => $provider === 'meta' ? 'Token expired — reconnect required.' : null,
                ],
            );

            $account = ExternalAccount::withoutGlobalScopes()->updateOrCreate(
                ['id' => $this->uuid("acct:{$provider}")],
                [
                    'tenant_id' => $tenant->id,
                    'client_workspace_id' => $campaigns->first()?->client_workspace_id,
                    'provider_connection_id' => $connection->id,
                    'provider' => $provider,
                    'account_type' => 'ad_account',
                    'external_id' => $externalId,
                    'name' => $name,
                    'currency' => $currency,
                    'timezone' => 'Asia/Riyadh',
                    'status' => 'active',
                    'metadata' => ['is_demo' => true],
                    'last_synced_at' => Carbon::now()->subHours(3),
                ],
            );

            $accountsByProvider[$provider] = $account;
        }

        // Link each demo campaign to the ad account of the platform its name announces.
        foreach ($campaigns as $campaign) {
            $provider = $this->providerFor($campaign);
            $account = $accountsByProvider[$provider] ?? null;
            if ($account === null) {
                continue;
            }

            ExternalCampaign::withoutGlobalScopes()->updateOrCreate(
                ['id' => $this->uuid("extcamp:{$campaign->id}")],
                [
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'client_workspace_id' => $campaign->client_workspace_id,
                    'unified_campaign_id' => $campaign->id,
                    'external_account_id' => $account->id,
                    'provider' => $provider,
                    'external_id' => $this->externalCampaignId($provider, $campaign->name),
                    'name' => $campaign->name,
                    'status' => $campaign->status === 'paused' ? 'paused' : 'active',
                    'objective' => $campaign->objective,
                    'currency' => $campaign->budget_currency ?: 'SAR',
                    'linked_at' => Carbon::now()->subDays(30),
                    'last_synced_at' => Carbon::now()->subHours(3),
                    'raw' => ['is_demo' => true, 'source' => 'demo-seeder'],
                ],
            );
        }

        $this->seedStructure($tenant->id, $project->id);

        // The demo sync runs already existed but belonged to no account, which is why the campaign sync
        // log was empty. Attach each run to its provider's account so the log has something real to show.
        foreach ($accountsByProvider as $provider => $account) {
            MetricSyncRun::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('provider', $provider)
                ->whereNull('external_account_id')
                ->update(['external_account_id' => $account->id]);
        }

        app(TenantContext::class)->forget();
    }

    /** The demo campaign names carry their platform ("Meta — …", "Google Search — …"). */
    private function providerFor(UnifiedCampaign $campaign): string
    {
        $name = mb_strtolower($campaign->name);

        return match (true) {
            str_contains($name, 'meta') => 'meta',
            str_contains($name, 'google') => 'google',
            str_contains($name, 'tiktok') => 'tiktok',
            str_contains($name, 'snap') => 'snapchat',
            default => (string) (($campaign->meta['primary_platform'] ?? null) ?: 'meta'),
        };
    }

    private function externalCampaignId(string $provider, string $name): string
    {
        $slug = substr(md5($name), 0, 10);

        return match ($provider) {
            'meta' => '2380'.substr($slug, 0, 8),
            'google' => '9'.substr($slug, 0, 9),
            'tiktok' => '17'.substr($slug, 0, 8),
            default => 'snap-'.$slug,
        };
    }

    private function uuid(string $label): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, self::NS.':'.$label);
    }

    /**
     * Ad sets and ads for every linked demo campaign, so the campaign structure tab has a real hierarchy
     * to render. Deliberately varied — a paused ad set, a rejected ad — because a demo where everything
     * is green teaches nothing. Every row is marked `source_type = demo` and `is_demo`.
     */
    private function seedStructure(string $tenantId, string $projectId): void
    {
        $externals = ExternalCampaign::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNotNull('unified_campaign_id')
            ->get(['id', 'provider', 'unified_campaign_id', 'name', 'currency']);

        // Three shapes of ad set, cycled so the demo shows more than one configuration.
        $shapes = [
            ['الجمهور الأساسي', 'conversions', 'lowest_cost', 1200.0, 'active', ['countries' => ['SA'], 'age' => '25-44', 'interests' => ['تسوق إلكتروني']]],
            ['إعادة الاستهداف', 'conversions', 'cost_cap', 800.0, 'active', ['countries' => ['SA'], 'audience' => 'زوار آخر ٣٠ يومًا']],
            ['توسيع الجمهور', 'link_clicks', 'lowest_cost', 400.0, 'paused', ['countries' => ['SA', 'AE'], 'age' => '18-34']],
        ];

        foreach ($externals as $i => $external) {
            foreach ($shapes as $j => [$label, $goal, $bid, $budget, $status, $targeting]) {
                // Two ad sets per campaign is enough to show the hierarchy without flooding the demo.
                if ($j >= 2 && $i % 3 !== 0) {
                    continue;
                }

                $adSet = ExternalAdSet::withoutGlobalScopes()->updateOrCreate(
                    ['id' => $this->uuid("adset:{$external->id}:{$j}")],
                    [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'external_campaign_id' => $external->id,
                        'unified_campaign_id' => $external->unified_campaign_id,
                        'provider' => $external->provider,
                        'external_id' => $this->platformId($external->provider, "as{$j}", $external->id),
                        'name' => $label,
                        'status' => $status,
                        'optimization_goal' => $goal,
                        'bid_strategy' => $bid,
                        'daily_budget' => $budget,
                        'currency' => $external->currency ?: 'SAR',
                        'targeting' => $targeting,
                        'source_type' => 'demo',
                        'is_demo' => true,
                        'last_synced_at' => Carbon::now()->subHours(3),
                    ],
                );

                foreach ([['إعلان صورة', 'approved', 'active'], ['إعلان فيديو', 'pending', 'active'], ['إعلان كاروسيل', 'rejected', 'paused']] as $k => [$adName, $review, $adStatus]) {
                    if ($k === 2 && $j !== 0) {
                        continue; // only the first ad set carries the rejected example
                    }

                    ExternalAd::withoutGlobalScopes()->updateOrCreate(
                        ['id' => $this->uuid("ad:{$adSet->id}:{$k}")],
                        [
                            'tenant_id' => $tenantId,
                            'project_id' => $projectId,
                            'external_ad_set_id' => $adSet->id,
                            'external_campaign_id' => $external->id,
                            'unified_campaign_id' => $external->unified_campaign_id,
                            'provider' => $external->provider,
                            'external_id' => $this->platformId($external->provider, "ad{$j}{$k}", $external->id),
                            'name' => $adName.' — '.$label,
                            'status' => $adStatus,
                            'review_status' => $review,
                            'destination_url' => 'https://example.test/landing',
                            'source_type' => 'demo',
                            'is_demo' => true,
                            'last_synced_at' => Carbon::now()->subHours(3),
                        ],
                    );
                }
            }
        }
    }

    private function platformId(string $provider, string $suffix, string $seed): string
    {
        $slug = substr(md5($seed.$suffix), 0, 8);

        return match ($provider) {
            'meta' => '2381'.$slug,
            'google' => '8'.$slug,
            'tiktok' => '18'.$slug,
            default => 'snap-'.$slug,
        };
    }
}
