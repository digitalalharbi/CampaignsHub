<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * COMMERCE-001 — the tenant's view of its own connected stores.
 *
 * Every card answers the four questions a merchant actually asks: is it connected, when did data last
 * arrive, how much of it is here, and — when something is wrong — what to do about it. The states are
 * the same five the ad-platform board uses, because they are the same five situations and a customer
 * moving between the two boards should not have to learn a second vocabulary.
 *
 * Nothing here names a system credential. `awaiting_credentials` means «the platform operator has not
 * finished setting this up», stated without saying which key is missing, because a merchant cannot
 * obtain a Client Secret for our app and does not need to know the shape of our registration.
 */
final class StoreController extends Controller
{
    public function __construct(
        private readonly CommerceConnectorRegistry $registry,
        private readonly ProviderConfigurationService $settings,
    ) {}

    /** GET /commerce/stores — one card per commerce provider, plus the stores actually connected. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $data = [];

        foreach ($this->registry->all() as $key => $connector) {
            $creds = PlatformCredentials::for($key);

            $connection = ProviderConnection::query()
                ->where('provider', $key)
                ->whereIn('status', ['connected', 'error'])
                ->latest('updated_at')
                ->first();

            $stores = $connection === null
                ? collect()
                : ExternalAccount::query()
                    ->where('provider_connection_id', $connection->getKey())
                    ->where('account_type', 'store')
                    ->get();

            $syncing = $stores->isNotEmpty() && IntegrationSyncRun::query()
                ->where('type', 'commerce')
                ->where('provider_connection_id', $connection?->getKey())
                ->where('status', 'running')
                ->exists();

            $state = match (true) {
                // Ordered so an out-of-service provider reads as such even when its keys are complete,
                // otherwise a merchant is offered a connect button the OAuth start is going to refuse.
                ! $this->settings->isEnabled($key) => 'unavailable',
                ! $creds->isConfigured() => 'awaiting_credentials',
                $connection === null || $stores->isEmpty() => 'disconnected',
                $syncing => 'syncing',
                $connection->status === 'error' => 'error',
                default => 'connected',
            };

            $data[] = [
                'key' => $key,
                'label' => $connector->label(),
                'state' => $state,
                'connection_error' => $connection?->last_error,
                'token_expires_at' => $connection?->token_expires_at?->toIso8601String(),
                'stores' => $stores->map(fn (ExternalAccount $store) => $this->storeArray($store))->values()->all(),
                /*
                 * Abandoned carts, said per PROVIDER rather than left to look like zero.
                 *
                 * Zid publishes no abandoned-cart endpoint. A funnel that showed «0 سلة متروكة» for a
                 * Zid store would claim a perfect checkout rate for a merchant who is losing carts
                 * every day, so the absence is stated as a capability rather than a count.
                 */
                'supports_abandoned_carts' => $key !== 'zid',
            ];
        }

        return ApiResponse::success($data, 'Store connections retrieved.');
    }

    /**
     * POST /commerce/stores/{store}/sync — ask for this store now rather than waiting for the sweep.
     *
     * It QUEUES the same job the scheduler queues. Four paginated reads behind a button is how a page
     * hangs and then times out with the work half done, and the job is unique per (store, window) so
     * pressing twice costs nothing.
     */
    public function sync(Request $request, string $store): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        /** @var ExternalAccount|null $model */
        $model = ExternalAccount::query()->where('account_type', 'store')->find($store);
        abort_if($model === null, 404, 'Store not found.');

        $connection = ProviderConnection::query()->find($model->provider_connection_id);

        if ($connection === null || $connection->status !== 'connected') {
            return ApiResponse::error(
                'This store has no connected authorisation. Reconnect the platform first.',
                meta: ['queued' => 0],
                status: 422,
            );
        }

        $days = (int) $request->integer('days', 14);
        $from = Carbon::now()->subDays(max(1, min($days, 180)))->startOfDay();

        SyncStoreJob::dispatch(
            (string) $model->getKey(),
            $from->toDateString(),
            Carbon::now()->endOfDay()->toDateString(),
            ['source' => 'store_page'],
        );

        return ApiResponse::success(
            ['queued' => 1, 'window_start' => $from->toDateString()],
            'Store sync queued.',
            status: 202,
        );
    }

    /** @return array<string,mixed> */
    private function storeArray(ExternalAccount $store): array
    {
        $lastRun = IntegrationSyncRun::query()
            ->where('type', 'commerce')
            ->where('provider_connection_id', $store->provider_connection_id)
            ->latest('started_at')
            ->first();

        return [
            'id' => $store->getKey(),
            'external_id' => $store->external_id,
            'name' => $store->name,
            'domain' => $store->metadata['domain'] ?? null,
            'currency' => $store->currency,
            // Discovered and synced are different facts, and the card shows both.
            'last_synced_at' => $store->last_synced_at?->toIso8601String(),
            'counts' => [
                'products' => CommerceProduct::query()->where('external_account_id', $store->getKey())->count(),
                'orders' => CommerceOrder::query()->where('external_account_id', $store->getKey())->count(),
                'abandoned_carts' => CommerceAbandonedCart::query()->where('external_account_id', $store->getKey())->count(),
            ],
            'last_run' => $lastRun === null ? null : [
                'status' => $lastRun->status,
                'records' => $lastRun->records,
                'error' => $lastRun->error,
                'finished_at' => $lastRun->finished_at?->toIso8601String(),
            ],
        ];
    }
}
