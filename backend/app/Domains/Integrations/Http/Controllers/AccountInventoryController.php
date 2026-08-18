<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Services\AccountLabel;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * INTEG-RUNTIME §3 — every account behind every connection, and the ONE thing that is true of each.
 *
 * ## What this answers
 *
 * The wizard answers «I have just authorised Snapchat; which of these 309 feed which project?» —
 * once, during a flow. This answers the question the customer has every day afterwards: **what does
 * CampaignsHub reach, and where is each of them going.** That spans connections and providers, so it
 * cannot be answered from inside one wizard.
 *
 * Eight providers, two shapes — six advertising and two commerce — listed together because the
 * customer has one set of sources, not two, while remaining separate everywhere it matters: a store
 * never counts against the Connected Ad Accounts quota and no store quota is invented.
 *
 * ## There are two states here, not four
 *
 * An account is either **linked to a project** or it is not. That is the whole model, and it is read
 * from `ProjectIntegrationBinding` where `is_active` — the single ownership record — never from a
 * column on the account.
 *
 * This replaced a four-state curation workflow (discovered / enabled / excluded / assigned) with its
 * own column, its own endpoints and its own bulk bar. It was internal bookkeeping promoted to
 * customer-facing vocabulary: «enabled» meant nothing to the person reading it, because enabling an
 * account did not make anything happen — only linking it to a project ever did. The journey is
 * OAuth → discovery → organisation → account → project → confirm → first sync, with no step in the
 * middle whose only effect is to change a word on a chip.
 *
 * ## Paginated, and the counts describe the whole
 *
 * 309 is the real number from one real authorisation. «4 of 309» is only true if the 309 is counted
 * without the filter that produced the 4, so the summary is computed before the filters — and the
 * linked/unlinked split is expressed in SQL rather than filtered in PHP after paginating, because a
 * page cut and then filtered is a page short by however many rows the filter removed.
 */
final class AccountInventoryController extends Controller
{
    /** §3 — the most history one request may pull, so a backfill cannot quietly become a year. */
    private const MAX_BACKFILL_DAYS = 90;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccountLabel $label,
        private readonly AccountHealth $health,
    ) {}

    /**
     * GET /integrations/accounts — every source this tenant reaches.
     *
     * Filters are additive and all optional: `provider`, `link` (linked|unlinked), `connection`,
     * `account_type`, `q`.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $validated = $request->validate([
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'connection' => ['sometimes', 'nullable', 'uuid'],
            'account_type' => ['sometimes', 'nullable', Rule::in(['ad_account', 'store'])],
            'link' => ['sometimes', 'nullable', Rule::in(['linked', 'unlinked'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = (string) $this->tenant->tenantId();

        $base = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['ad_account', 'store']);

        $summary = $this->summarise($tenantId);

        $query = (clone $base)
            ->when(
                ($validated['provider'] ?? null) !== null,
                fn ($q) => $q->where('provider', $validated['provider']),
            )
            ->when(
                ($validated['connection'] ?? null) !== null,
                fn ($q) => $q->where('provider_connection_id', $validated['connection']),
            )
            ->when(
                ($validated['account_type'] ?? null) !== null,
                fn ($q) => $q->where('account_type', $validated['account_type']),
            )
            ->when(($validated['q'] ?? null) !== null, function ($q) use ($validated): void {
                $term = '%'.str_replace('%', '\%', (string) $validated['q']).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $term)
                    ->orWhere('external_id', 'ilike', $term)
                    ->orWhere('parent_name', 'ilike', $term));
            });

        $linkedIds = fn () => ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('external_account_id');

        if (($validated['link'] ?? null) === 'linked') {
            $query->whereIn('id', $linkedIds());
        } elseif (($validated['link'] ?? null) === 'unlinked') {
            $query->whereNotIn('id', $linkedIds());
        }

        $page = $query->orderBy('provider')->orderBy('name')->orderBy('external_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        /** @var list<ExternalAccount> $rows */
        $rows = collect($page->items())->all();

        return ApiResponse::success([
            'accounts' => $this->present($rows, $tenantId),
            'summary' => $summary,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ], __('api.ok'));
    }

    /**
     * POST /integrations/accounts/{account}/backfill — fetch a window of history that already passed.
     *
     * ## Why this is not «sync with different dates»
     *
     * The scheduled sweep is deliberately short: it restates the last few days, because that is what
     * providers still revise. Somebody who connects an account in August and needs June's numbers for
     * a client report is not asking for a sync — they are asking for a window the sweep will never
     * cover, and without this they have no way to get it at all.
     *
     * Refused for an account no project owns, and the refusal names the reason. Backfilling an
     * unlinked account is the same fault this programme closed everywhere else: data with nowhere
     * honest to land, which previously landed in whichever project sorted first.
     */
    public function backfill(Request $request, string $accountId): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $account = $this->accountOr404($accountId);

        if (! $this->isLinked($account)) {
            return ApiResponse::error(__('integrations.backfill_unassigned'), status: 409);
        }

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        if ($from->greaterThan($to)) {
            return ApiResponse::error(__('integrations.backfill_window_invalid'), status: 422);
        }

        // `diffInDays` is exclusive of the start day and the window is inclusive of both ends — so a
        // 90-day window is 89 whole days apart, and off-by-one here would silently permit 91.
        if ($from->diffInDays($to) + 1 > self::MAX_BACKFILL_DAYS) {
            return ApiResponse::error(
                __('integrations.backfill_window_too_long', ['days' => self::MAX_BACKFILL_DAYS]),
                status: 422,
            );
        }

        SyncAccountMetricsJob::dispatch(
            (string) $account->id,
            $from->toDateString(),
            $to->toDateString(),
            // Recorded as a backfill so the sync log can say which of the three causes this was.
            ['source' => 'backfill', 'backfill' => true, 'requested_by' => (string) $request->user()->getKey()],
        );

        return ApiResponse::success([
            'account_id' => (string) $account->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'queued' => true,
        ], __('api.ok'));
    }

    /**
     * GET /integrations/accounts/{account}/logs — what this account's syncs actually did.
     *
     * Per ACCOUNT, not per provider. The provider-level history already existed and could not answer
     * the only question anybody asks when a number looks wrong: «what happened to THIS account?»
     * With ten accounts behind one authorisation, a provider-level log is nine other accounts' noise.
     *
     * The rows come from `MetricSyncRun::logRow()`, the same serializer the project and campaign logs
     * use, so this log carries the four counts too — «the platform sent 400 rows and we stored 0» is
     * the sentence that makes a zero readable, and it must not be missing from the one log that is
     * about a single account.
     */
    public function logs(Request $request, string $accountId): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $account = $this->accountOr404($accountId);

        $runs = MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->tenantId())
            ->where('external_account_id', $account->id)
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (MetricSyncRun $r): array => $r->logRow())
            ->values();

        return ApiResponse::success([
            'account' => $this->present([$account], (string) $this->tenant->tenantId())[0],
            'runs' => $runs,
        ], __('api.ok'));
    }

    // ── Presentation ──────────────────────────────────────────────────────────────────────────────

    /**
     * One row per account, with everything resolved in bulk.
     *
     * Assignment, project names and connection names are each ONE query for the whole page. Resolved
     * per row they would be three hundred round trips for a page of a hundred, and the answers would
     * be identical.
     *
     * @param  list<ExternalAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function present(array $accounts, string $tenantId): array
    {
        $ids = array_map(static fn (ExternalAccount $a): string => (string) $a->id, $accounts);

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('external_account_id', $ids)
            ->pluck('project_id', 'external_account_id');

        $projectNames = Project::withoutGlobalScopes()
            ->whereIn('id', $bindings->values()->unique()->all())
            ->pluck('name', 'id');

        $connectionNames = ProviderConnection::withoutGlobalScopes()
            ->whereIn('id', array_map(static fn (ExternalAccount $a): string => (string) $a->provider_connection_id, $accounts))
            ->pluck('connection_name', 'id');

        return array_map(function (ExternalAccount $account) use ($bindings, $projectNames, $connectionNames): array {
            $projectId = $bindings[$account->id] ?? null;
            $label = $this->label->describe($account);

            return [
                'id' => (string) $account->id,
                'provider' => $account->provider,
                'provider_label' => __('providers.'.$account->provider),
                'account_type' => $account->account_type,
                'account_type_label' => __('integrations.account_type.'.$account->account_type),

                // §3 — what to READ, and what to MATCH, never merged into one field.
                'name' => $label['name'],
                'reference' => $label['reference'],
                'named_by_provider' => $label['named_by_provider'],

                'parent_name' => $account->parent_name,
                'parent_external_id' => $account->parent_external_id,
                'currency' => $account->currency,
                'timezone' => $account->timezone,

                'connection_id' => (string) $account->provider_connection_id,
                'connection_name' => $connectionNames[$account->provider_connection_id] ?? null,

                // The whole model: linked to a project, or not. Read from the binding, never stored.
                'is_linked' => $projectId !== null,
                'assigned_project_id' => $projectId,
                // A project id is not an answer to «where does this go». The name is.
                'assigned_project_name' => $projectId !== null ? ($projectNames[$projectId] ?? null) : null,

                /*
                 * Health is only reported where syncing is a thing that happens. An account nothing
                 * has ever tried to sync has no health, and inventing one would put a badge on
                 * hundreds of rows that mean nothing by it.
                 */
                'health' => $projectId !== null ? $this->health->for($account) : null,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'last_sync_attempt_at' => $account->last_sync_attempt_at?->toIso8601String(),
                'last_sync_error_category' => $account->last_sync_error_category,
                'next_sync_at' => $account->next_sync_at?->toIso8601String(),
                'access_lost_at' => $account->access_lost_at?->toIso8601String(),

                /*
                 * Said out loud on the row rather than left to be inferred. A store goes through the
                 * same explicit assignment and costs nothing against the Connected Ad Accounts cap,
                 * and the row that spends a slot should be the row that says so.
                 */
                'counts_toward_ad_account_quota' => $account->account_type === 'ad_account',
            ];
        }, $accounts);
    }

    /**
     * How many of the tenant's accounts are linked, and how many are not.
     *
     * @return array<string, int>
     */
    private function summarise(string $tenantId): array
    {
        $linkedIds = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('external_account_id')
            ->unique();

        $base = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['ad_account', 'store']);

        $total = (clone $base)->count();
        $linked = (clone $base)->whereIn('id', $linkedIds)->count();

        return ['linked' => $linked, 'unlinked' => $total - $linked, 'total' => $total];
    }

    private function isLinked(ExternalAccount $account): bool
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->exists();
    }

    /** The account, or a 404 — never another tenant's, and never a type this page does not manage. */
    private function accountOr404(string $accountId): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->tenantId())
            ->whereIn('account_type', ['ad_account', 'store'])
            ->find($accountId);

        abort_if($account === null, 404);

        return $account;
    }
}
