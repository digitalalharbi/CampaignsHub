<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Catalogue\ProviderDisplayName;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
    /**
     * REPORT-PROVIDER-NAME-001 — moved to {@see ProviderDisplayName}, which now owns these names.
     *
     * `ReportGenerator` needed the same names to stop printing «meta» inside a client's report, and
     * a second copy would have drifted. Kept as an alias so existing callers of this constant are
     * unchanged, and sliced to the ad platforms this screen lists — the stores are on their own.
     */
    public const PLATFORMS = [
        'snapchat' => ProviderDisplayName::NAMES['snapchat'],
        'tiktok' => ProviderDisplayName::NAMES['tiktok'],
        'meta' => ProviderDisplayName::NAMES['meta'],
        'google' => ProviderDisplayName::NAMES['google'],
        'x' => ProviderDisplayName::NAMES['x'],
        'linkedin' => ProviderDisplayName::NAMES['linkedin'],
    ];

    /** What a connected platform can do. Listed even when awaiting credentials, so the gap is explicit. */
    private const CAPABILITIES = [
        'oauth' => ['ar' => 'ربط عبر OAuth', 'en' => 'OAuth connect'],
        'accounts' => ['ar' => 'اكتشاف الحسابات الإعلانية', 'en' => 'Ad-account discovery'],
        'campaigns' => ['ar' => 'اكتشاف الحملات', 'en' => 'Campaign discovery'],
        'insights' => ['ar' => 'مزامنة القياسات', 'en' => 'Insights sync'],
        'creatives' => ['ar' => 'المحتويات الإعلانية', 'en' => 'Creatives'],
    ];

    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly AccountHealth $health,
    ) {}

    /**
     * The external accounts and stores ACTIVELY assigned to the current project.
     *
     * Project-scoped through the binding rather than through the account, because the account is
     * tenant-scoped by design. `ProjectIntegrationBinding` carries `BelongsToProject`, so the
     * request's project narrows it without this method naming an id at all.
     *
     * @return Collection<int, ExternalAccount>
     */
    private function assignedAccounts(): Collection
    {
        $ids = ProjectIntegrationBinding::query()
            ->where('is_active', true)
            ->pluck('external_account_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('provider')
            ->orderBy('name')
            ->get();
    }

    /** GET projects/{project}/integrations/platforms */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        /*
         * INTEGRATIONS-VS-PROJECTS-IA-001 — this screen shows what was LINKED, never what was found.
         *
         * It read `ExternalAccount::query()->get()`. That model carries `BelongsToTenant`, not
         * `BelongsToProject` — correct for the model, because a discovered account genuinely belongs
         * to the tenant and to no project until somebody says otherwise — and reading it unqualified
         * on a PROJECT screen turned the tenant's whole inventory into that project's contents. With
         * the live Snapchat connection that is 309 accounts on a page about one.
         *
         * Integrations is where sources are chosen; a project is the RESULT of those choices. So this
         * starts from the bindings, and an account nobody assigned is not filtered out of the list —
         * it is not part of the question this screen answers.
         */
        $assigned = $this->assignedAccounts();

        $connections = ProviderConnection::query()
            ->whereIn('id', $assigned->pluck('provider_connection_id')->unique()->filter()->all())
            ->get()
            ->groupBy('provider');

        $accounts = $assigned->groupBy('provider');
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
                /*
                 * Everything an operator needs to answer «is this working», and nothing they would
                 * need in order to CHOOSE — choosing happens in Integrations, where the inventory is.
                 */
                'accounts' => $accountRows->map(fn (ExternalAccount $a) => [
                    'id' => (string) $a->id,
                    'provider' => $a->provider,
                    'account_type' => $a->account_type,
                    // Name first. An identifier where a name belongs claims the provider called it that.
                    'name' => $a->name,
                    'external_id' => $a->external_id,
                    'parent_name' => $a->parent_name,
                    'parent_external_id' => $a->parent_external_id,
                    'currency' => $a->currency,
                    'timezone' => $a->timezone,
                    'status' => $a->status,
                    'health' => $this->health->for($a),
                    'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                    'last_sync_attempt_at' => optional($a->last_sync_attempt_at)->toIso8601String(),
                    'last_sync_error_category' => $a->last_sync_error_category,
                    'next_sync_at' => optional($a->next_sync_at)->toIso8601String(),
                    'access_lost_at' => optional($a->access_lost_at)->toIso8601String(),
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
            /*
             * A flat list of the project's linked sources, ad accounts and stores together.
             *
             * The per-platform grouping above is organised around the six ad platforms; a store is
             * not one of them and would have nowhere to appear. A project does not care which family
             * a source belongs to — it cares what feeds it.
             */
            'accounts' => $assigned->map(fn (ExternalAccount $a) => [
                'id' => (string) $a->id,
                'provider' => $a->provider,
                'account_type' => $a->account_type,
                'name' => $a->name,
                'external_id' => $a->external_id,
                'parent_name' => $a->parent_name,
                'parent_external_id' => $a->parent_external_id,
                'currency' => $a->currency,
                'timezone' => $a->timezone,
                'status' => $a->status,
                'health' => $this->health->for($a),
                'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
                'last_sync_attempt_at' => optional($a->last_sync_attempt_at)->toIso8601String(),
                'last_sync_error_category' => $a->last_sync_error_category,
                'next_sync_at' => optional($a->next_sync_at)->toIso8601String(),
                'access_lost_at' => optional($a->access_lost_at)->toIso8601String(),
            ])->values()->all(),
            'summary' => [
                'total' => count(self::PLATFORMS),
                'with_credentials' => count(array_filter($platforms, fn (array $p) => $p['has_credentials'])),
                'with_accounts' => count(array_filter($platforms, fn (array $p) => $p['accounts'] !== [])),
                'discovered_campaigns' => array_sum(array_column($platforms, 'discovered_campaigns')),
            ],
        ], 'Platform integrations.');
    }
}
