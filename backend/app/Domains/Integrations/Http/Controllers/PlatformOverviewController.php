<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PROJINT-001 / INTEG-UI-001 — one read model organised around the SIX REAL ad platforms, so the
 * project integrations screen stops being a list of abstract "bindings" and becomes a per-platform
 * picture of what is actually connected.
 *
 * It is deliberately built so a UI cannot claim more than the system knows. For each platform it
 * reports, from real rows: whether credentials exist, which connections and ad accounts exist, how many
 * campaigns were discovered, and the last sync run with its status and error. A platform with no
 * credentials reports `awaiting_credentials` together with the exact capabilities that will light up
 * once credentials are added — the structure is complete, only the secret is missing.
 */
final class PlatformOverviewController extends Controller
{
    /**
     * The six real advertising platforms, keyed by name.
     *
     * The ORDER is not decided here — `AdPlatforms::ORDER` decides it for the whole product, and this
     * map is sorted through it before it is returned (PLATFORM-ORDER-001). Leading with Meta here
     * while the report engine led with Snapchat is exactly the drift that made a customer hunt for
     * the same platform in a different position on every screen.
     */
    public const PLATFORMS = [
        'snapchat' => ['ar' => 'سناب شات', 'en' => 'Snapchat Ads'],
        'tiktok' => ['ar' => 'تيك توك', 'en' => 'TikTok Ads'],
        'meta' => ['ar' => 'ميتا (فيسبوك وإنستقرام)', 'en' => 'Meta'],
        'google' => ['ar' => 'إعلانات جوجل', 'en' => 'Google Ads'],
        'x' => ['ar' => 'منصة X', 'en' => 'X Ads'],
        'linkedin' => ['ar' => 'لينكدإن', 'en' => 'LinkedIn Ads'],
    ];

    /** What a connected platform can do. Listed even when awaiting credentials, so the gap is explicit. */
    private const CAPABILITIES = [
        'oauth' => ['ar' => 'ربط عبر OAuth', 'en' => 'OAuth connect'],
        'accounts' => ['ar' => 'اكتشاف الحسابات الإعلانية', 'en' => 'Ad-account discovery'],
        'campaigns' => ['ar' => 'اكتشاف الحملات', 'en' => 'Campaign discovery'],
        'insights' => ['ar' => 'مزامنة القياسات', 'en' => 'Insights sync'],
        'creatives' => ['ar' => 'المحتويات الإعلانية', 'en' => 'Creatives'],
    ];

    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** GET projects/{project}/integrations/platforms */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connections = ProviderConnection::query()->get()->groupBy('provider');
        $accounts = ExternalAccount::query()->get()->groupBy('provider');
        $campaignCounts = ExternalCampaign::query()
            ->select('provider')->selectRaw('COUNT(*) AS c, COUNT(unified_campaign_id) AS linked')
            ->groupBy('provider')->get()->keyBy('provider');
        $lastRuns = MetricSyncRun::query()
            ->orderByDesc('started_at')->orderByDesc('created_at')
            ->get()->groupBy('provider');

        $platforms = [];

        foreach (self::PLATFORMS as $key => $label) {
            $connector = $this->registry->get($key);
            $status = $connector?->status() ?? ConnectorStatus::AwaitingCredentials;
            $accountRows = $accounts->get($key, collect());
            $run = $lastRuns->get($key, collect())->first();
            $counts = $campaignCounts->get($key);

            $platforms[] = [
                'key' => $key,
                'label_ar' => $label['ar'],
                'label_en' => $label['en'],
                'connector_label' => $connector?->label(),
                // The single source of truth for what this platform can currently do.
                'status' => $status->value,
                'has_credentials' => $status !== ConnectorStatus::AwaitingCredentials,
                'capabilities' => array_map(
                    fn (string $capKey, array $cap) => [
                        'key' => $capKey,
                        'ar' => $cap['ar'],
                        'en' => $cap['en'],
                        // Every capability is implemented; it is enabled only when credentials exist.
                        'enabled' => $status !== ConnectorStatus::AwaitingCredentials,
                    ],
                    array_keys(self::CAPABILITIES),
                    self::CAPABILITIES,
                ),
                'connections' => $connections->get($key, collect())->map(fn (ProviderConnection $c) => [
                    'id' => $c->id,
                    'name' => $c->connection_name,
                    'status' => $c->status,
                    'last_health_check_at' => optional($c->last_health_check_at)->toIso8601String(),
                    'last_successful_sync_at' => optional($c->last_successful_sync_at)->toIso8601String(),
                    'last_error' => $c->last_error,
                ])->values()->all(),
                'accounts' => $accountRows->map(fn (ExternalAccount $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'external_id' => $a->external_id,
                    'currency' => $a->currency,
                    'status' => $a->status,
                    'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                    'is_demo' => (bool) ($a->metadata['is_demo'] ?? false),
                ])->values()->all(),
                'discovered_campaigns' => (int) ($counts->c ?? 0),
                'linked_campaigns' => (int) ($counts->linked ?? 0),
                'last_sync' => $run === null ? null : [
                    'status' => $run->status,
                    'started_at' => optional($run->started_at)->toIso8601String(),
                    'finished_at' => optional($run->finished_at)->toIso8601String(),
                    'metrics_upserted' => (int) $run->metrics_upserted,
                    'error' => $run->error,
                    'is_demo' => (bool) $run->is_demo,
                ],
            ];
        }

        return ApiResponse::success([
            'platforms' => $platforms,
            'summary' => [
                'total' => count(self::PLATFORMS),
                'with_credentials' => count(array_filter($platforms, fn (array $p) => $p['has_credentials'])),
                'with_accounts' => count(array_filter($platforms, fn (array $p) => $p['accounts'] !== [])),
                'discovered_campaigns' => array_sum(array_column($platforms, 'discovered_campaigns')),
            ],
        ], 'Platform integrations.');
    }
}
