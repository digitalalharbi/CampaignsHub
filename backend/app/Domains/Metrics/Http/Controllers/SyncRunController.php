<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SYNC-001 — the operator-facing surface of the sync pipeline: what ran, what it produced, what broke,
 * and the ability to trigger a run.
 *
 * This is the evidence that the integrations are more than a UI: an account either has real runs with
 * real windows, counts and errors behind it, or it honestly reports that it has never synced.
 */
final class SyncRunController extends Controller
{
    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly AccountAssignment $assignment,
    ) {}

    /** GET projects/{project}/sync-runs — the sync log for this project's accounts. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $filters = $request->validate([
            'provider' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        $runs = MetricSyncRun::query()
            ->when($filters['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', $runs->pluck('external_account_id')->filter()->unique())
            ->get(['id', 'name', 'external_id'])
            ->keyBy('id');

        return ApiResponse::success([
            'runs' => $runs->map(fn (MetricSyncRun $r) => $r->logRow(
                $accounts->get($r->external_account_id)?->name,
                $accounts->get($r->external_account_id)?->external_id,
            ))->all(),
            'summary' => $runs->groupBy('status')->map->count(),
        ], 'Sync runs.');
    }

    /**
     * POST projects/{project}/sync-runs — trigger a sync for one account.
     *
     * A connector without credentials is still accepted and still recorded, because "we never tried"
     * is information an operator needs. What it will NOT do is report success.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $data = $request->validate([
            'external_account_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $account = ExternalAccount::withoutGlobalScopes()
            ->where('id', $data['external_account_id'])
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->firstOrFail();

        /*
         * OWNERSHIP-004 — the account must be ASSIGNED to a project, and that is the only test.
         *
         * This used to ask whether any `external_campaigns` row existed for the account, which is a
         * different question with a worse answer. An account that had just been linked and whose
         * structure sync had not landed yet — the exact state a first sync exists to resolve — was
         * refused with «that ad account does not feed this project», so the one button that could
         * have fixed an empty project was the one button it would not let you press. And a campaign
         * row is not an ownership record: it is a consequence of one, which is precisely the kind of
         * stand-in the ownership rule exists to forbid.
         */
        abort_if(
            $this->assignment->projectIdFor($account) === null,
            404,
            'That ad account is not assigned to a project yet.',
        );

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(6);

        SyncAccountMetricsJob::dispatch($account->id, $from->toDateString(), $to->toDateString(), [
            'triggered_by' => $request->user()->id,
            'manual' => true,
        ]);

        $this->audit->log('metrics.sync_requested', 'external_account', (string) $account->id, after: [
            'window' => [$from->toDateString(), $to->toDateString()],
        ]);

        $connector = $this->registry->get($account->provider);

        return ApiResponse::success([
            'queued' => true,
            'provider' => $account->provider,
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // Told up front, so the UI never promises data a credential-less connector cannot deliver.
            'will_fetch' => $connector !== null && $connector->status() !== ConnectorStatus::AwaitingCredentials,
        ], 'Sync queued.', status: 202);
    }
}
