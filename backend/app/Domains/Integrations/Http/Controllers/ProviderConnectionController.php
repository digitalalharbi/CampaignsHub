<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tenant-level provider connection management (list, revoke). */
final class ProviderConnectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connections = ProviderConnection::withCount('externalAccounts')->latest()->get()
            ->map(fn (ProviderConnection $c) => [
                'id' => $c->id,
                'name' => $c->connection_name,
                'provider' => $c->provider,
                'scope' => $c->scope,
                'status' => $c->status,
                'accounts' => $c->external_accounts_count,
                'last_health_check_at' => optional($c->last_health_check_at)->toIso8601String(),
            ]);

        return ApiResponse::success($connections, 'Connections retrieved.');
    }

    /**
     * Revoke the OAuth connection. This disables EVERY project binding that uses any of its
     * discovered accounts (across all projects), and marks the connection revoked.
     */
    public function revoke(Request $request, ProviderConnection $connection, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.disconnect'), 403);

        DB::transaction(function () use ($connection, $audit): void {
            $accountIds = ExternalAccount::where('provider_connection_id', $connection->id)->pluck('id');

            // Disable bindings across ALL projects (bypass project scope on purpose).
            $affected = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
                ->whereIn('external_account_id', $accountIds)
                ->update(['is_active' => false]);

            $connection->update(['status' => 'revoked', 'last_error' => 'Revoked by user']);

            $audit->log(
                action: 'integration.connection.revoked',
                entityType: ProviderConnection::class,
                entityId: (string) $connection->id,
                after: ['disabled_bindings' => $affected],
            );
        });

        return ApiResponse::success(
            ['status' => 'revoked'],
            'Connection revoked; all its project bindings were disabled.',
        );
    }
}
