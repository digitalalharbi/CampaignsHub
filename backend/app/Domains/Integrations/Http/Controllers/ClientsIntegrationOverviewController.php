<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Funding\Services\SourceFreshness;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATIONS-ALL-CLIENTS-001 — every client the reader reaches, with what its linked sources are doing, in ONE read.
 *
 * A client's sources are the ad accounts its projects have selected (an active binding). One row per client: how many
 * sources, how many need an action, the newest SUCCESSFUL sync, and each problem by platform with its recorded reason. «يحتاج إجراء»
 * is the same judgement the budgets use for stale data (`SourceFreshness::reason`): a failed last sync, lost access, never synced, or
 * a last good sync older than two hours. A client with no linked source is listed and says so, never hidden.
 */
final class ClientsIntegrationOverviewController extends Controller
{
    public function __construct(private readonly ClientAccess $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('integrations.view'), 403);

        $clients = ClientWorkspace::query()->whereNull('archived_at');
        $this->access->restrictQuery($clients, $user);
        $names = $clients->orderBy('name')->pluck('name', 'id');
        $ceilings = $this->access->projectCeilings($user);

        // PROJECT-LIFECYCLE-001 — listed projects (draft/active/paused); archived and deleted only under «المؤرشفة».
        $projects = LiveProjects::constrainListed(DB::table('projects')->whereIn('client_workspace_id', $names->keys()->all()))->get(['id', 'client_workspace_id'])
            ->filter(fn ($p): bool => $this->access->projectAllowed($ceilings, (string) $p->client_workspace_id, (string) $p->id));
        $clientOf = $projects->pluck('client_workspace_id', 'id')->map(static fn ($v): string => (string) $v);

        $bindings = DB::table('project_integration_bindings')->where('is_active', true)->whereIn('project_id', $clientOf->keys()->all())
            ->get(['project_id', 'external_account_id']);
        $accounts = ExternalAccount::query()->whereIn('id', $bindings->pluck('external_account_id')->unique()->all())->where('account_type', 'ad_account')
            ->get(['id', 'provider', 'name', 'last_synced_at', 'last_sync_error_category', 'access_lost_at'])->keyBy(fn (ExternalAccount $a): string => (string) $a->id);

        $now = Carbon::now();
        $byClient = [];
        foreach ($bindings as $b) {
            $a = $accounts[(string) $b->external_account_id] ?? null;
            $clientId = $clientOf[(string) $b->project_id] ?? null;
            if ($a === null || $clientId === null) {
                continue;
            }
            $byClient[$clientId][(string) $a->id] = $a;
        }

        $rows = $names->map(function (string $name, string $id) use ($byClient, $projects, $now): array {
            $list = collect($byClient[$id] ?? []);
            $issues = $list->map(function (ExternalAccount $a) use ($now): ?array {
                $reason = SourceFreshness::reason($a, $now);

                return $reason === null ? null : [
                    'provider' => AdPlatforms::canonical((string) $a->provider), 'account' => $a->name, 'reason' => $reason,
                    'error_category' => $a->last_sync_error_category, 'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                ];
            })->filter()->values()->all();
            $last = $list->map(fn (ExternalAccount $a) => $a->last_synced_at)->filter()->max();

            return [
                'client_id' => $id,
                'client_name' => $name,
                'projects' => $projects->where('client_workspace_id', $id)->count(),
                'sources' => $list->count(),
                'platforms' => AdPlatforms::sort($list->pluck('provider')->map(fn ($p): string => AdPlatforms::canonical((string) $p))->unique()->values()->all()),
                'needs_action' => $issues !== [],
                'issues' => $issues,
                'last_success_at' => $last?->toIso8601String(),
            ];
        })->values();

        return ApiResponse::success($rows->all(), 'Integrations by client.', [
            'needs_action' => $rows->where('needs_action', true)->count(),
            'total' => $rows->count(),
        ]);
    }
}
