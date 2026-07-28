<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\Integration;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IntegrationController extends Controller
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** List every advertising connector with its live status and this tenant's connection (if any). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connections = Integration::query()->get()->keyBy('connector_key');

        $data = [];
        foreach ($this->registry->all() as $key => $connector) {
            /** @var Integration|null $connection */
            $connection = $connections->get($key);
            $data[] = [
                'key' => $key,
                'label' => $connector->label(),
                'status' => $connection !== null ? $connection->status : $connector->status()->value,
                'ad_account_id' => $connection?->ad_account_id,
                'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
                'last_sync_error' => $connection?->last_sync_error,
            ];
        }

        return ApiResponse::success($data, 'Connectors retrieved.');
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
