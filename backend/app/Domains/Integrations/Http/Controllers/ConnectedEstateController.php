<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\InsightsAuthorisation;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Integrations\Support\NextScheduledSync;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the connected estate, organised the way a customer holds it.
 *
 * ## The page this replaces as the default
 *
 * The Integration Center opened on a grid of provider cards and, underneath, every account every
 * authorisation had ever discovered. Both are true and neither is the question. An agency does not
 * think «how is Meta» — it thinks «is عميل X complete», because a client is what it invoices, what it
 * reports on, and what somebody is on the phone about. Answering that from provider cards meant
 * holding the whole binding table in your head: this account is that client's, that one is nobody's.
 *
 * So the default becomes PROJECT first: one row per project, what it is connected to, whether it is
 * current, and what it needs. The provider view survives — it is the right view for «is our Snapchat
 * authorisation healthy» — and the discovered inventory survives behind its own control, because
 * «everything we can see» is a real question that is simply not this one.
 *
 * ## Only SELECTED accounts appear here
 *
 * ACCOUNT-SCOPE-ISOLATION-001. The rows are built from ACTIVE bindings and nothing else: an account
 * that consent merely revealed has no client, no figure and no place on this page. Seventeen
 * discovered Meta accounts under one authorisation produce exactly as many estate rows as have been
 * chosen — which for the Production tenant this was built against is one.
 *
 * ## Why it is aggregates and not account rows
 *
 * A tenant's estate is bounded by its plan, not by what fits on a screen, and {@see AccountHealth}
 * costs a query or two per account. Rendering every account here would be the inventory again,
 * wearing project headings. Each row therefore carries counts and the two truths, and expanding one
 * asks `GET integrations/accounts?project=…` — the inventory engine that already exists, already
 * paginates and already computes per-account health.
 */
final class ConnectedEstateController extends Controller
{
    /** Worst first. The order a row's single summary word is chosen in. */
    private const HEALTH_RANK = ['reauth', 'attention', 'syncing', 'no_data', 'complete'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenantId = (string) $this->tenant->tenantId();

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('project_id')
            ->get(['project_id', 'external_account_id', 'provider']);

        $accountIds = $bindings->pluck('external_account_id')->map(strval(...))->unique()->values();

        $accounts = $accountIds->isEmpty() ? collect() : ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', $accountIds)
            ->where('account_type', 'ad_account')
            ->get(['id', 'provider', 'provider_connection_id', 'name', 'external_id', 'currency',
                'last_synced_at', 'access_lost_at', 'last_sync_error_category'])
            ->keyBy(fn (ExternalAccount $a): string => (string) $a->getKey());

        $projectIds = $bindings->pluck('project_id')->map(strval(...))->unique()->values();

        $projects = $projectIds->isEmpty() ? collect() : Project::withoutGlobalScopes()
            ->whereIn('id', $projectIds)
            ->get(['id', 'name', 'client_workspace_id', 'status'])
            ->keyBy(fn (Project $p): string => (string) $p->getKey());

        $workspaces = ClientWorkspace::withoutGlobalScopes()
            ->whereIn('id', $projects->pluck('client_workspace_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy(fn (ClientWorkspace $w): string => (string) $w->getKey());

        $connections = ProviderConnection::withoutGlobalScopes()
            ->whereIn('id', $accounts->pluck('provider_connection_id')->filter()->unique())
            ->get(['id', 'provider', 'status', 'insights_denied_at', 'scopes'])
            ->keyBy(fn (ProviderConnection $c): string => (string) $c->getKey());

        // Every run for the selected estate, once. The rule is then applied per group in memory.
        $runs = $accountIds->isEmpty() ? collect() : MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $accountIds)
            ->orderByDesc('started_at')
            ->get(['external_account_id', 'status', 'started_at', 'finished_at']);

        $campaigns = $this->campaignCounts($tenantId, $projectIds->all());

        $rows = [];
        foreach ($bindings->groupBy(fn ($b): string => (string) $b->project_id) as $projectId => $forProject) {
            $project = $projects->get((string) $projectId);
            if ($project === null) {
                continue;
            }

            $providers = [];
            foreach ($forProject->groupBy(fn ($b): string => (string) $b->provider) as $provider => $group) {
                $ids = $group->pluck('external_account_id')->map(strval(...))
                    ->filter(fn (string $id): bool => $accounts->has($id))
                    ->unique()->values()->all();

                if ($ids === []) {
                    continue;
                }

                $providers[] = $this->provider((string) $provider, $ids, $accounts, $connections, $runs, $campaigns[(string) $projectId][(string) $provider] ?? null);
            }

            if ($providers === []) {
                continue;
            }

            usort($providers, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

            $rows[] = [
                'id' => (string) $project->getKey(),
                'name' => (string) $project->name,
                'status' => (string) $project->status,
                'client' => $this->client($project, $workspaces),
                'providers' => $providers,
                'accounts' => array_sum(array_column($providers, 'accounts')),
                'campaigns' => array_sum(array_column($providers, 'campaigns')),
                'active_campaigns' => array_sum(array_column($providers, 'active_campaigns')),
                'last_success_at' => $this->latest(array_column($providers, 'last_success_at')),
                'health' => $this->worst(array_column($providers, 'health')),
            ];
        }

        // Worst first: a page whose job is «does anything need me» opens on what does.
        usort($rows, function (array $a, array $b): int {
            $rank = array_search($a['health'], self::HEALTH_RANK, true) <=> array_search($b['health'], self::HEALTH_RANK, true);

            return $rank !== 0 ? $rank : strcmp((string) $a['name'], (string) $b['name']);
        });

        return ApiResponse::success([
            'projects' => $rows,
            /*
             * Discovered but chosen by nobody — stated as a NUMBER, never as rows.
             *
             * It is the one honest way to show that an authorisation reaches more than this page
             * lists, without putting seventeen accounts nobody asked for under a client's name.
             */
            'unselected_accounts' => $this->unselected($tenantId, $accountIds->all()),
        ], __('api.ok'));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string,mixed>
     */
    private function provider(string $provider, array $ids, $accounts, $connections, $runs, ?array $campaigns): array
    {
        $rows = collect($ids)->map(fn (string $id) => $accounts->get($id));

        $connection = $rows->map(fn ($a) => $connections->get((string) $a->provider_connection_id))->filter()->first();

        $connectionState = match (true) {
            $connection === null => IntegrationTruth::NOT_CONNECTED,
            in_array($connection->status, ['revoked', 'disconnected'], true) => IntegrationTruth::REVOKED,
            $connection->status === 'error',
            $connection->insights_denied_at !== null,
            ! InsightsAuthorisation::grantedBy((string) $connection->provider, $connection->scopes) => IntegrationTruth::REAUTH_REQUIRED,
            default => IntegrationTruth::CONNECTED,
        };

        $syncState = IntegrationTruth::syncStateFromRuns($ids, $runs);

        $flagged = $rows->contains(
            fn ($a): bool => $a->access_lost_at !== null || $a->last_sync_error_category !== null,
        );
        $everSynced = $rows->contains(fn ($a): bool => $a->last_synced_at !== null);

        $health = match (true) {
            in_array($connectionState, [IntegrationTruth::REAUTH_REQUIRED, IntegrationTruth::REVOKED], true) => 'reauth',
            $flagged, $syncState === IntegrationTruth::FAILED => 'attention',
            in_array($syncState, [IntegrationTruth::SYNCING, IntegrationTruth::QUEUED, IntegrationTruth::NEVER_SYNCED], true) => 'syncing',
            /*
             * Succeeded, and nothing ever landed. `last_synced_at` is written only when data actually
             * arrives (DISCOVERY-NOT-SYNC-001), so this is «we asked and there was nothing» — not an
             * error, never red, and the number an operator asking «why is it connected but empty?»
             * came for.
             */
            ! $everSynced => 'no_data',
            default => 'complete',
        };

        return [
            'key' => $provider,
            'label' => ProviderCatalogue::has($provider) ? ProviderCatalogue::get($provider)->label : $provider,
            'label_ar' => ProviderCatalogue::has($provider) ? ProviderCatalogue::get($provider)->labelAr : $provider,
            'connection_id' => $connection === null ? null : (string) $connection->getKey(),
            'connection_state' => $connectionState,
            'sync_state' => $syncState,
            'health' => $health,
            'accounts' => count($ids),
            'currencies' => $rows->pluck('currency')->filter()->unique()->values()->all(),
            'campaigns' => (int) ($campaigns['total'] ?? 0),
            'active_campaigns' => (int) ($campaigns['active'] ?? 0),
            'last_success_at' => $this->latest(
                $rows->pluck('last_synced_at')->filter()->map(fn (Carbon $c): string => $c->toIso8601String())->all(),
            ),
            /*
             * INTEGRATION-SYNC-VISIBILITY-001 — «when will this update itself», stated only where it
             * is true. A connection that needs re-authorising is on the scheduler in the sense that
             * the command will run and is not going to sync anything, and «next sync in 12 minutes»
             * over one of those is the most confident kind of wrong.
             */
            'next_sync_at' => NextScheduledSync::at(
                $connectionState === IntegrationTruth::CONNECTED,
            )?->toIso8601String(),
        ];
    }

    /** @return array<string,array<string,array{total:int,active:int}>> */
    private function campaignCounts(string $tenantId, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        // Aggregated in the database and read as plain rows: a model hydrated from a GROUP BY carries
        // a count that is not one of its own columns, and asking Eloquent for it is a lie either way.
        $rows = ExternalCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('project_id', $projectIds)
            ->whereNull('unlinked_at')
            ->groupBy('project_id', 'provider', 'status')
            ->toBase()
            ->get(['project_id', 'provider', 'status', DB::raw('count(*) as total')]);

        $out = [];
        foreach ($rows as $row) {
            $project = (string) $row->project_id;
            $provider = (string) $row->provider;
            $out[$project][$provider] ??= ['total' => 0, 'active' => 0];
            $out[$project][$provider]['total'] += (int) $row->total;

            // The provider's own word, read through the product's vocabulary rather than matched on.
            if (CampaignStatus::fromProvider($row->status === null ? null : (string) $row->status) === CampaignStatus::Active) {
                $out[$project][$provider]['active'] += (int) $row->total;
            }
        }

        return $out;
    }

    /** @param  list<string>  $selected */
    private function unselected(string $tenantId, array $selected): int
    {
        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', 'ad_account')
            ->whereNull('access_lost_at')
            ->when($selected !== [], fn ($q) => $q->whereNotIn('id', $selected))
            ->count();
    }

    /** @return array<string,mixed>|null */
    private function client(Project $project, $workspaces): ?array
    {
        $workspace = $project->client_workspace_id === null
            ? null
            : $workspaces->get((string) $project->client_workspace_id);

        return $workspace === null
            ? null
            : ['id' => (string) $workspace->getKey(), 'name' => (string) $workspace->name];
    }

    /** @param  array<int,string|null>  $stamps */
    private function latest(array $stamps): ?string
    {
        $stamps = array_values(array_filter($stamps));

        return $stamps === [] ? null : max($stamps);
    }

    /** @param  array<int,string>  $healths */
    private function worst(array $healths): string
    {
        foreach (self::HEALTH_RANK as $health) {
            if (in_array($health, $healths, true)) {
                return $health;
            }
        }

        return 'complete';
    }
}
