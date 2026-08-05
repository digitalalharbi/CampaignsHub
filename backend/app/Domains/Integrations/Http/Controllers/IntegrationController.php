<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\Integration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class IntegrationController extends Controller
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** List every advertising connector with its live status and this tenant's connection (if any). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connections = Integration::query()->get()->keyBy('connector_key');

        $data = [];
        $listed = [];

        foreach ($this->registry->all() as $key => $connector) {
            /*
             * One card per CONNECTOR, not per key.
             *
             * The registry deliberately answers to several spellings of the same platform — Google is
             * registered as `google_ads` and aliased to `google`, because stored rows use both — and
             * iterating its keys therefore listed Google Ads twice, side by side, with identical
             * status. Found in live review; invisible from the API, where two entries look like two
             * platforms.
             */
            if (in_array($connector->key(), $listed, true)) {
                continue;
            }

            $listed[] = $connector->key();

            /** @var Integration|null $connection */
            $connection = $connections->get($key);
            $data[] = [
                'key' => $key,
                'label' => $connector->label(),
                'status' => $connection !== null ? $connection->status : $connector->status()->value,
                'ad_account_id' => $connection?->ad_account_id,
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_sync_error' => $connection?->last_sync_error,
                ...$this->adPlatformState($key),
            ];
        }

        return ApiResponse::success($data, 'Connectors retrieved.');
    }

    /**
     * The four states one of the six ad platforms can honestly be in (INTEG-UI-001).
     *
     * `Connected · Syncing · Error · Awaiting Credentials`, and they are answers to four different
     * questions, which is why one status string could not carry them:
     *
     * - **Awaiting Credentials** — this deployment has no app registered with the platform. Nothing
     *   the customer does fixes it; an operator has to provision keys. So `missing` travels with it,
     *   because «بانتظار بيانات الاعتماد» is not actionable and «ينقص: developer_token» is.
     * - **Error** — we WERE connected and the platform stopped accepting us; almost always a revoked
     *   authorisation. The customer fixes this by connecting again, so the reason is shown.
     * - **Syncing** — a run is open right now. Without it, a customer who presses sync sees a page
     *   that looks exactly as it did before and presses it again.
     * - **Connected** — authorised, with the number of ad accounts and when data last arrived.
     *
     * A platform with no entry in `config/ad_platforms.php` gets nothing here; this block belongs
     * only to the six, and the sandbox and analytics connectors keep their own simpler shape.
     *
     * @return array<string,mixed>
     */
    private function adPlatformState(string $key): array
    {
        $platform = AdPlatforms::canonical($key);

        if (! in_array($platform, AdPlatforms::ORDER, true)) {
            return [];
        }

        $creds = PlatformCredentials::for($platform);

        // Both spellings are in use across stored rows (`google` and `google_ads`), so a connection
        // must be looked up by either or a Google account appears unconnected while it is connected.
        $connection = ProviderConnection::query()
            ->whereIn('provider', array_unique([$platform, $key]))
            ->whereIn('status', ['connected', 'error'])
            ->latest('updated_at')
            ->first();

        $accountIds = $connection === null
            ? collect()
            : ExternalAccount::query()->where('provider_connection_id', $connection->getKey())->pluck('id');

        $syncing = $accountIds->isNotEmpty() && MetricSyncRun::query()
            ->whereIn('external_account_id', $accountIds)
            ->where('status', 'running')
            ->exists();

        $state = match (true) {
            ! $creds->isConfigured() => 'awaiting_credentials',
            $connection === null => 'disconnected',
            $syncing => 'syncing',
            $connection->status === 'error' => 'error',
            default => 'connected',
        };

        $lastSynced = $accountIds->isEmpty() ? null : ExternalAccount::query()
            ->whereIn('id', $accountIds)
            ->max('last_synced_at');

        return [
            'is_ad_platform' => true,
            'state' => $state,
            'missing' => $creds->missing(),
            'accounts' => $accountIds->count(),
            'connection_error' => $connection?->last_error,
            'token_expires_at' => $connection?->token_expires_at?->toIso8601String(),
            'data_last_synced_at' => $lastSynced === null ? null : Carbon::parse($lastSynced)->toIso8601String(),
        ];
    }

    public function health(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);
        $connector = $this->registry->get($key);
        abort_if($connector === null, 404, 'Unknown connector.');

        return ApiResponse::success($connector->healthCheck()->toArray(), 'Health checked.');
    }

    /**
     * Connect a connector. Sandbox connects immediately; real connectors that are awaiting
     * credentials return their honest status without fabricating a connection.
     */
    public function connect(Request $request, string $key, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        $connector = $this->registry->get($key);
        abort_if($connector === null, 404, 'Unknown connector.');

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return ApiResponse::error(
                $connector->label().' is awaiting credentials. See INTEGRATION_CREDENTIALS_CHECKLIST.md.',
                meta: ['status' => ConnectorStatus::AwaitingCredentials->value],
                status: 422,
            );
        }

        $accounts = $connector->listAdAccounts();
        $integration = Integration::updateOrCreate(
            ['connector_key' => $key],
            [
                'status' => ConnectorStatus::Connected->value,
                'ad_account_id' => $accounts[0]['id'] ?? null,
                'meta' => ['ad_accounts' => $accounts],
            ],
        );

        $audit->log(action: 'integration.connect', entityType: Integration::class, entityId: (string) $integration->id, after: ['connector' => $key]);

        return ApiResponse::success(
            ['key' => $key, 'status' => $integration->status, 'ad_account_id' => $integration->ad_account_id],
            'Connector connected.',
            status: 201,
        );
    }

    /** Trigger a campaign sync for a connected connector. */
    public function sync(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);
        $connector = $this->registry->get($key);
        abort_if($connector === null, 404, 'Unknown connector.');

        $integration = Integration::where('connector_key', $key)->first();
        abort_if($integration === null || $integration->ad_account_id === null, 422, 'Connector is not connected.');

        $result = $connector->syncCampaigns($integration->ad_account_id);

        $integration->update($result->success
            ? ['last_synced_at' => now(), 'last_sync_error' => null]
            : ['last_sync_error' => $result->message]);

        return ApiResponse::success(
            ['success' => $result->success, 'count' => $result->count, 'records' => $result->records],
            $result->message ?? 'Sync complete.',
        );
    }
}
