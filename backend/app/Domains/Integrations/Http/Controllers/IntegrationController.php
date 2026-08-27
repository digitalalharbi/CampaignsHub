<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
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
    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly CommerceConnectorRegistry $stores,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /** Every provider this product integrates with — advertising and stores — with its live status. */
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

            /*
             * INTEG-RUNTIME §2 — the eight, and only the eight, are offered to a customer.
             *
             * `AdvertisingConnectorRegistry` still carries the sandbox connector outside production,
             * because the end-to-end suite and the demo seeder need a connection to exercise without a
             * real platform credential. That is a development need; listing it here made it a NINTH
             * provider on the customer's own page, with a green chip, above the platforms they came
             * for. It is filtered at the surface rather than removed from the registry, so the two
             * facts stay separate: what this product integrates with, and what a test can drive.
             */
            if (! ProviderCatalogue::has($connector->key())) {
                continue;
            }

            $listed[] = $connector->key();

            /** @var Integration|null $connection */
            $connection = $connections->get($key);
            $data[] = [
                'key' => $key,
                'label' => $connector->label(),
                'kind' => 'advertising',
                'status' => $connection !== null ? $connection->status : $connector->status()->value,
                'ad_account_id' => $connection?->ad_account_id,
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_sync_error' => $connection?->last_sync_error,
                ...$this->adPlatformState($key),
            ];
        }

        /*
         * INTEG-STORES-001 — the store providers belong on this page too.
         *
         * The comment above says «the eight, and only the eight», and this loop walked the ADVERTISING
         * registry, which holds six. Salla and Zid are declared in the same `ProviderCatalogue`, carry
         * the same credential fields and the same webhook configuration — and appeared nowhere on the
         * Integration Center. They were reachable only through a separate Stores panel, so a customer
         * looking at «integrations» saw six of the eight things this product integrates with and had
         * no way to know the other two existed.
         *
         * They are appended rather than merged into the loop above because a store is not an ad
         * account: it has no `ad_account_id` and none of the five ad-platform states. Giving it those
         * keys as nulls would make it look like an ad platform that had failed to connect, which is a
         * different and worse lie than being absent.
         */
        foreach ($this->stores->all() as $key => $connector) {
            if (! ProviderCatalogue::has($key)) {
                continue;
            }

            $connection = $connections->get($key);

            $data[] = [
                'key' => $key,
                'label' => $connector->label(),
                'kind' => 'commerce',
                'status' => $connection !== null ? $connection->status : $connector->status()->value,
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_sync_error' => $connection?->last_sync_error,
            ];
        }

        return ApiResponse::success($data, 'Connectors retrieved.');
    }

    /**
     * The five states one of the six ad platforms can honestly be in, from a TENANT's point of view.
     *
     * They are answers to five different questions, which is why one status string could not carry
     * them, and each admits a different action:
     *
     * - **Unavailable** — the platform operator has taken this provider out of service. Nothing the
     *   customer does changes it and no button is offered. WHY it was taken out of service is not
     *   said here: that is the operator's business, recorded in the audit trail at `/admin`.
     * - **Awaiting Credentials** — this deployment has no app registered with the platform. Also not
     *   the customer's to fix, so also no button.
     * - **Error** — we WERE connected and the platform stopped accepting us; almost always a revoked
     *   authorisation. The customer fixes this by connecting again, so the reason is shown.
     * - **Syncing** — a run is open right now. Without it, a customer who presses sync sees a page
     *   that looks exactly as it did before and presses it again.
     * - **Connected** — authorised, with the number of ad accounts and when data last arrived.
     *
     * ## What this deliberately no longer tells a tenant (PROVCFG-001)
     *
     * Which SYSTEM credential is absent. `missing: ['developer_token']` used to travel with the
     * awaiting state, and it is an instruction for the console at `/admin` addressed to the wrong
     * reader — a customer cannot obtain a developer token for our OAuth app, and telling them the
     * shape of our provider registration is telling them about the platform's internals. The named
     * list still exists, on the one screen whose reader can act on it.
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
            // Ordered so an out-of-service provider reads as such even when its keys are complete —
            // otherwise a tenant would be offered a connect button the OAuth start is going to refuse.
            ! $this->settings->isEnabled($platform) => 'unavailable',
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
