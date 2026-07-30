<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
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
            'runs' => $runs->map(fn (MetricSyncRun $r) => [
                'id' => $r->id,
                'provider' => $r->provider,
                'status' => $r->status,
                'account' => $accounts->get($r->external_account_id)?->name,
                'account_external_id' => $accounts->get($r->external_account_id)?->external_id,
                'window_start' => $r->window_start?->toDateString(),
                'window_end' => $r->window_end?->toDateString(),
                'metrics_upserted' => (int) $r->metrics_upserted,
                'attempts' => (int) $r->attempts,
                'started_at' => optional($r->started_at)->toIso8601String(),
                'finished_at' => optional($r->finished_at)->toIso8601String(),
                'error' => $r->error,
                // Demo runs are labelled, never disguised as production traffic.
                'is_demo' => (bool) $r->is_demo,
            ])->all(),
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

        // Fail closed: the account must actually feed this project.
        $feedsProject = ExternalCampaign::query()
            ->where('external_account_id', $data['external_account_id'])
            ->exists();
        abort_unless($feedsProject, 404, 'That ad account does not feed this project.');

        $account = ExternalAccount::withoutGlobalScopes()
            ->where('id', $data['external_account_id'])
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->firstOrFail();

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
