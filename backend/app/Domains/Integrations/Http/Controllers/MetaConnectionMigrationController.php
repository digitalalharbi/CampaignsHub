<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * META-SAAS-APP-001 — the explicit path for an EXISTING Meta account to be read through another Meta app's connection.
 *
 * Never automatic. The operator first connects through the other app (a NEW connection; the old one is untouched and keeps syncing),
 * which proves that app can see the account: its discovery filed the account under the new connection. Only then may the operator move
 * the account. The move re-points the SAME account row, so its project bindings and every historical figure stay exactly as they were;
 * the old connection is not revoked, not disconnected and keeps its token, so moving back is the same request in reverse.
 */
final class MetaConnectionMigrationController extends Controller
{
    public function move(Request $request, AuditLogger $audit, string $account): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('integrations.connect'), 403);
        $data = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'confirm' => ['required', 'accepted'],
        ]);

        $row = ExternalAccount::query()->findOrFail($account);
        $from = ProviderConnection::query()->findOrFail($row->provider_connection_id);
        $to = ProviderConnection::query()->findOrFail($data['connection_id']);

        abort_unless($row->provider === 'meta' && $from->provider === 'meta' && $to->provider === 'meta', 422, 'Only a Meta ad account moves between Meta app connections.');
        abort_if($from->getKey() === $to->getKey(), 422, 'The account is already read through that connection.');
        abort_unless($to->status === 'connected', 422, 'The target connection is not connected.');

        $fromApp = MetaCredentialProfile::ofConnection($from->app_profile);
        $toApp = MetaCredentialProfile::ofConnection($to->app_profile);
        abort_if($fromApp === $toApp, 422, 'Both connections belong to the same Meta app; nothing would change.');

        // The proof: the target app's own discovery found this account. Moving BACK to the connection it came from needs no new proof.
        $metadata = (array) ($row->metadata ?? []);
        $returning = ($metadata['moved_from_connection_id'] ?? null) === (string) $to->getKey();
        $twin = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $to->getKey())
            ->where('external_id', $row->external_id)
            ->where('account_type', $row->account_type)
            ->first();
        abort_unless($returning || $twin !== null, 422, 'The target app has not discovered this ad account. Connect through it and let discovery finish first.');

        /*
         * The twin row is only a discovery record, and is retired only while it is exactly that: no project has ever bound it and no sync
         * has ever run for it. It is never deleted either way, so no figure, binding or reference can be lost through it.
         */
        if ($twin !== null) {
            $used = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $twin->getKey())->exists()
                || $twin->last_synced_at !== null;
            abort_if($used, 409, 'The target connection already uses this account in a project; unlink it there first.');
        }

        DB::transaction(function () use ($row, $from, $to, $twin, $metadata, $request): void {
            /*
             * Nothing is ever deleted. The discovery twin is RETIRED instead: its key is set aside so the moved row can take its place under
             * the target connection, and the row itself — with anything that ever referenced it — stays exactly where it is.
             */
            if ($twin !== null) {
                $twin->forceFill([
                    'external_id' => $twin->external_id.'#superseded-'.substr((string) $twin->getKey(), 0, 8),
                    'status' => 'superseded',
                    'metadata' => array_merge((array) ($twin->metadata ?? []), ['superseded_by_account_id' => (string) $row->getKey(), 'superseded_at' => now()->toIso8601String()]),
                ])->save();
            }
            $row->forceFill([
                'provider_connection_id' => $to->getKey(),
                'metadata' => array_merge($metadata, [
                    'moved_from_connection_id' => (string) $from->getKey(),
                    'moved_at' => now()->toIso8601String(),
                    'moved_by' => $request->user()?->getKey(),
                ]),
            ])->save();
        });

        $audit->log(
            action: 'integration.account.moved_between_meta_apps',
            entityType: ExternalAccount::class,
            entityId: (string) $row->getKey(),
            before: ['connection_id' => (string) $from->getKey(), 'app_profile' => $fromApp->value],
            after: ['connection_id' => (string) $to->getKey(), 'app_profile' => $toApp->value],
        );

        return ApiResponse::success([
            'account_id' => (string) $row->getKey(),
            'connection_id' => (string) $to->getKey(),
            'app_profile' => $toApp->value,
            'previous_connection_id' => (string) $from->getKey(),
            'previous_connection_still_connected' => $from->refresh()->status === 'connected',
        ], 'Account now read through the other Meta app. The previous connection is untouched; moving back is the same request.');
    }
}
