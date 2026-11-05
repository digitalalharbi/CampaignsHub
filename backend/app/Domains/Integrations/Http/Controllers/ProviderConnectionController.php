<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ConnectionRemoval;
use App\Domains\Integrations\Services\ConnectionWizardState;
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

        /*
         * GADS-STALE-PICKER-001 — the card counts what the banner counts.
         *
         * This read `withCount('externalAccounts')` — every account type, under the tenant scope — while
         * the unfinished-connection banner counted ad accounts through `ConnectionWizardState` with
         * `withoutGlobalScopes()`. Two queries of «how many accounts does this connection have» that
         * differ in both the type filter and the scoping, which is how one screen showed «0 ad accounts»
         * beside «1 account available».
         *
         * One source now. They cannot disagree because there is nothing left to disagree with.
         */
        $state = app(ConnectionWizardState::class);

        $connections = ProviderConnection::withCount('externalAccounts')->whereNull('removed_at')->latest()->get()
            ->map(fn (ProviderConnection $c) => [
                'id' => $c->id,
                'name' => $c->connection_name,
                'provider' => $c->provider,
                'scope' => $c->scope,
                'status' => $c->status,
                /*
                 * Currently SELECTABLE, from the one state service. A refused discovery leaves its rows in
                 * place — they are still bound and still real — but they are not an answer to «what can be
                 * chosen now», and presenting them as one is the defect this replaces.
                 */
                'accounts' => $state->for($c)['discovered'],
                'ever_discovered' => $c->external_accounts_count,
                'discovery_blocked_reason' => $c->discovery_blocked_reason,
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

    /**
     * INTEG-SOURCE-REMOVAL-001 — remove a source from the Connection Hub.
     *
     * Disables every binding, stops its syncs, clears the stored credential and soft-hides the row. It
     * deletes and moves no stored figure, report row or ownership row (see {@see ConnectionRemoval}).
     *
     * `unconfirmed_only` is the wizard's «إلغاء»: it removes the connection ONLY if nothing was ever
     * selected or synced on it, so cancelling a re-authorisation of a working source can never take that
     * source away. Anything else answers `removed: false` and changes nothing.
     *
     * Tenant isolation is the model's own scope: another tenant's connection id is a 404, not a 403.
     */
    public function remove(Request $request, ProviderConnection $connection, ConnectionRemoval $removal): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.disconnect'), 403);

        $validated = $request->validate(['unconfirmed_only' => ['sometimes', 'boolean']]);

        if ($connection->removed_at !== null) {
            return ApiResponse::success(['removed' => true, 'already' => true, 'disabled_bindings' => 0], 'Source already removed.');
        }

        if (($validated['unconfirmed_only'] ?? false) && ! $removal->isUnconfirmed($connection)) {
            return ApiResponse::success(['removed' => false, 'disabled_bindings' => 0], 'The source is already in use, so it was kept.');
        }

        $result = $removal->remove($connection, ($validated['unconfirmed_only'] ?? false) ? 'wizard_cancelled' : 'operator');

        return ApiResponse::success(
            ['removed' => true, 'disabled_bindings' => $result['disabled_bindings']],
            'Source removed; its bindings were disabled and its stored history was kept.',
        );
    }
}
