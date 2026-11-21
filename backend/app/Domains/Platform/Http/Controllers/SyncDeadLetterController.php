<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\SyncDeadLetters;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INTEG-SYNC-ENGINE-001 — the platform owner's view of metrics syncs that exhausted their retries, and the one action on them.
 *
 * Sits behind `platform` (see routes/api/admin.php) and crosses tenants deliberately. The rows carry ids, a window, a category and a
 * redacted sentence: no `meta`, no payload, no figure. Re-driving is one account and one window, audited, and cannot run twice.
 */
final class SyncDeadLetterController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $page = MetricSyncRun::withoutGlobalScopes()
            ->whereNotNull('dead_lettered_at')
            ->when($request->query('state', 'open') === 'open', static fn ($q) => $q->whereNull('redriven_at'))
            ->when($request->query('state') === 'redriven', static fn ($q) => $q->whereNotNull('redriven_at'))
            ->when(is_string($request->query('provider')), static fn ($q) => $q->where('provider', $request->query('provider')))
            ->orderByDesc('dead_lettered_at')
            ->paginate(self::PER_PAGE);

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', collect($page->items())->pluck('external_account_id')->filter()->unique()->all())
            ->get(['id', 'external_id', 'name'])
            ->keyBy('id');

        return ApiResponse::success([
            'dead_letters' => collect($page->items())->map(static function (MetricSyncRun $run) use ($accounts): array {
                $account = $accounts->get($run->external_account_id);

                return [
                    'id' => $run->id,
                    'tenant_id' => $run->tenant_id,
                    'project_id' => $run->project_id,
                    'provider' => $run->provider,
                    'external_account_id' => $run->external_account_id,
                    'account' => $account?->name,
                    'account_external_id' => $account?->external_id,
                    'window_start' => $run->window_start?->toDateString(),
                    'window_end' => $run->window_end?->toDateString(),
                    'category' => $run->dead_letter_category,
                    'error' => ProviderErrorText::forStorage($run->error),
                    'attempts' => (int) $run->attempts,
                    'dead_lettered_at' => $run->dead_lettered_at?->toIso8601String(),
                    'redriven_at' => $run->redriven_at?->toIso8601String(),
                ];
            })->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function redrive(Request $request, string $run, SyncDeadLetters $deadLetters, AuditLogger $audit): JsonResponse
    {
        $letter = MetricSyncRun::withoutGlobalScopes()->whereKey($run)->whereNotNull('dead_lettered_at')->firstOrFail();

        $outcome = $deadLetters->redrive($letter, $request->user()?->id);

        if ($outcome === SyncDeadLetters::REDRIVE_QUEUED) {
            $audit->log(
                'integrations.sync.dead_letter_redriven',
                'metric_sync_run',
                (string) $letter->getKey(),
                after: ['provider' => $letter->provider, 'category' => $letter->dead_letter_category, 'window_start' => $letter->window_start?->toDateString(), 'window_end' => $letter->window_end?->toDateString()],
                tenantId: (string) $letter->tenant_id,
            );
        }

        return ApiResponse::success(['outcome' => $outcome], status: match ($outcome) {
            SyncDeadLetters::REDRIVE_QUEUED => 202,
            SyncDeadLetters::REDRIVE_BINDING_GONE => 409,
            default => 200,
        });
    }
}
