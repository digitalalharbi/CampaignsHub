<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountHealth;
use App\Domains\Integrations\Services\AccountLabel;
use App\Domains\Integrations\Services\AccountLifecycle;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * COMMAND-CENTER §§7–20 — one inventory of every account behind every connection.
 *
 * ## What this is for
 *
 * The wizard answers «I have just authorised Snapchat, which of these 309 do I want?» — once, per
 * connection, during a flow. This answers the question the customer has every day afterwards:
 * **what does CampaignsHub have access to, what is it actually doing with it, and where.** That
 * question spans connections and providers, so it cannot be answered from inside one wizard.
 *
 * Eight providers, two shapes: six advertising and two commerce. They are listed TOGETHER here on
 * purpose — the customer has one set of sources, not two — while remaining separate everywhere it
 * matters: a store is never counted against the Connected Ad Accounts quota, and no store quota is
 * invented (COMMERCE-QUOTA-001).
 *
 * ## Every row says three things, and they are different things
 *
 *  - `lifecycle` — discovered, enabled, excluded or assigned (`AccountLifecycle`).
 *  - `health` — how the syncing is going, for the accounts where syncing happens (`AccountHealth`).
 *  - `assigned_project` — the NAME of the project that owns it, never only an id.
 *
 * A row that is `discovered` has no health worth reporting and says so, rather than showing a green
 * tick over an account nothing has ever tried to sync.
 *
 * ## Paginated, and filtered to what was chosen by default
 *
 * 309 rows is the real number from one real authorisation. An «everything, unpaginated» inventory is
 * a page nobody can use and a query that gets slower every month. The default view is what the
 * customer has curated; the rest is one filter away and the counts always name the whole.
 */
final class AccountInventoryController extends Controller
{
    /** COMMAND-CENTER §18 — the most history one request may pull, so a backfill cannot become a year. */
    private const MAX_BACKFILL_DAYS = 90;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccountLifecycle $lifecycle,
        private readonly AccountLabel $label,
        private readonly AccountHealth $health,
    ) {}

    /**
     * GET /integrations/accounts — the inventory.
     *
     * Filters are additive and all optional: `provider`, `state`, `connection`, `q`, `account_type`.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $validated = $request->validate([
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'connection' => ['sometimes', 'nullable', 'uuid'],
            'account_type' => ['sometimes', 'nullable', Rule::in(['ad_account', 'store'])],
            'state' => ['sometimes', 'nullable', Rule::in([
                AccountLifecycle::DISCOVERED,
                AccountLifecycle::ENABLED,
                AccountLifecycle::EXCLUDED,
                AccountLifecycle::ASSIGNED,
            ])],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $this->tenant->tenantId();

        $base = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['ad_account', 'store']);

        /*
         * The summary counts the WHOLE inventory, before the filters — «4 of 309» is only true if
         * the 309 is counted without the filter that produced the 4. Assignment is counted from the
         * bindings rather than from a column, because there is no such column and there must not be.
         */
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

        $state = $validated['state'] ?? null;

        /*
         * `assigned` and `discovered` cannot be filtered by column — one lives in the bindings and
         * the other is the ABSENCE of a decision. Both are expressed as SQL rather than filtered in
         * PHP after paginating, because filtering a page after it has been cut returns a page that
         * is short by however many rows the filter removed.
         */
        $assignedAccountIds = fn () => ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('external_account_id');

        if ($state === AccountLifecycle::ASSIGNED) {
            $query->whereIn('id', $assignedAccountIds());
        } elseif ($state === AccountLifecycle::DISCOVERED) {
            $query->whereNull('management_state')->whereNotIn('id', $assignedAccountIds());
        } elseif ($state === AccountLifecycle::ENABLED) {
            $query->where('management_state', AccountLifecycle::ENABLED)
                ->whereNotIn('id', $assignedAccountIds());
        } elseif ($state === AccountLifecycle::EXCLUDED) {
            $query->where('management_state', AccountLifecycle::EXCLUDED)
                ->whereNotIn('id', $assignedAccountIds());
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
     * POST /integrations/accounts/{account}/state — enable, exclude, or return to undecided.
     *
     * `assigned` is not settable. It is the binding's answer, and offering it here would create a
     * second way to say who owns an account — the exact defect RUNTIME-100 spent three PRs removing.
     */
    public function setState(Request $request, string $accountId, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $validated = $request->validate([
            'state' => ['required', Rule::in(AccountLifecycle::SETTABLE)],
        ]);

        $account = $this->accountOr404($accountId);
        $target = $validated['state'];

        /*
         * Refused rather than silently ignored. Excluding an assigned account would leave a row the
         * customer believes is gone while its spend keeps arriving in a project's reporting — and
         * `stateFor()` would keep answering `assigned` anyway, so the interface and the database
         * would disagree about something the customer had just been told.
         */
        if ($target === AccountLifecycle::EXCLUDED && $this->lifecycle->stateFor($account) === AccountLifecycle::ASSIGNED) {
            return ApiResponse::error(__('integrations.exclude_assigned'), status: 409);
        }

        $before = $account->management_state;

        $account->forceFill([
            'management_state' => $target === AccountLifecycle::DISCOVERED ? null : $target,
            'management_state_changed_at' => Carbon::now(),
        ])->save();

        $audit->log(
            action: 'integration.account.state_changed',
            entityType: ExternalAccount::class,
            entityId: (string) $account->id,
            before: ['management_state' => $before],
            after: ['management_state' => $account->management_state],
        );

        return ApiResponse::success(
            $this->present([$account->refresh()], (string) $this->tenant->tenantId())[0],
            __('api.ok'),
        );
    }

    /**
     * POST /integrations/accounts/state — the same decision for many accounts at once.
     *
     * Present because the real number is 309. Asking somebody to press «استبعاد» three hundred times
     * is not a feature with a rough edge, it is a feature that will not be used.
     *
     * Atomic: either every account named is moved or none is. A half-applied bulk action leaves the
     * customer unable to tell which half, and their only recourse is to check all 309 by hand.
     */
    public function setStateBulk(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $validated = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:500'],
            'account_ids.*' => ['uuid'],
            'state' => ['required', Rule::in(AccountLifecycle::SETTABLE)],
        ]);

        $tenantId = (string) $this->tenant->tenantId();
        $target = $validated['state'];

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $validated['account_ids'])
            ->get();

        if ($accounts->count() !== count(array_unique($validated['account_ids']))) {
            return ApiResponse::error(__('integrations.connection_not_authorized'), status: 404);
        }

        if ($target === AccountLifecycle::EXCLUDED) {
            $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('external_account_id', $accounts->modelKeys())
                ->exists();

            if ($assigned) {
                return ApiResponse::error(__('integrations.exclude_assigned'), status: 409);
            }
        }

        DB::transaction(function () use ($accounts, $target, $audit, $tenantId): void {
            ExternalAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $accounts->modelKeys())
                ->update([
                    'management_state' => $target === AccountLifecycle::DISCOVERED ? null : $target,
                    'management_state_changed_at' => Carbon::now(),
                ]);

            $audit->log(
                action: 'integration.account.state_changed_bulk',
                entityType: ExternalAccount::class,
                entityId: (string) $accounts->first()?->id,
                after: ['management_state' => $target, 'count' => $accounts->count()],
            );
        });

        return ApiResponse::success(['updated' => $accounts->count(), 'state' => $target], __('api.ok'));
    }

    /**
     * POST /integrations/accounts/{account}/backfill — fetch a window of history that already passed.
     *
     * ## Why this is not «sync with different dates»
     *
     * The scheduled sweep is deliberately short: it restates the last few days, because that is what
     * providers still revise. Somebody who connects an account in August and needs June's numbers for
     * a client report is not asking for a sync, they are asking for a window the sweep will never
     * cover, and without this they have no way to get it at all.
     *
     * Refused for an account no project owns, and the refusal names the reason. Backfilling an
     * unassigned account is the same fault RUNTIME-100 closed everywhere else: data with nowhere
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

        if ($this->lifecycle->stateFor($account) !== AccountLifecycle::ASSIGNED) {
            return ApiResponse::error(__('integrations.backfill_unassigned'), status: 409);
        }

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        if ($from->greaterThan($to)) {
            return ApiResponse::error(__('integrations.backfill_window_invalid'), status: 422);
        }

        // `diffInDays` is exclusive of the start day, and the window itself is inclusive of both
        // ends — so a 90-day window is 89 whole days apart, and off-by-one here would silently
        // permit 91.
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
            ['source' => 'backfill', 'requested_by' => (string) $request->user()->getKey()],
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
            ->map(fn (MetricSyncRun $r): array => [
                'id' => (string) $r->id,
                'status' => $r->status,
                'window_start' => $r->window_start?->toDateString(),
                'window_end' => $r->window_end?->toDateString(),
                'metrics_upserted' => $r->metrics_upserted,
                'attempts' => $r->attempts,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                // The error is shown as it was recorded. A log that tidies its own errors is a log
                // nobody can debug from.
                'error' => $r->error,
            ])
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
            $state = $this->lifecycle->stateFor($account, $projectId !== null);
            $label = $this->label->describe($account);

            return [
                'id' => (string) $account->id,
                'provider' => $account->provider,
                'provider_label' => __('providers.'.$account->provider),
                'account_type' => $account->account_type,
                'account_type_label' => __('integrations.account_type.'.$account->account_type),

                // COMMAND-CENTER §12 — what to read, and what to match, never merged into one field.
                'name' => $label['name'],
                'reference' => $label['reference'],
                'named_by_provider' => $label['named_by_provider'],

                'parent_name' => $account->parent_name,
                'parent_external_id' => $account->parent_external_id,
                'currency' => $account->currency,
                'timezone' => $account->timezone,

                'connection_id' => (string) $account->provider_connection_id,
                'connection_name' => $connectionNames[$account->provider_connection_id] ?? null,

                'lifecycle' => $state,
                'lifecycle_label' => __('integrations.lifecycle.'.$state),
                'lifecycle_hint' => __('integrations.lifecycle_hint.'.$state),

                'assigned_project_id' => $projectId,
                // A project id is not an answer to «where does this go». The name is.
                'assigned_project_name' => $projectId !== null ? ($projectNames[$projectId] ?? null) : null,

                /*
                 * Health is only reported where syncing is a thing that happens. An account nothing
                 * has ever tried to sync has no health, and inventing one would put a badge on 305
                 * rows that mean nothing by it.
                 */
                'health' => $state === AccountLifecycle::ASSIGNED ? $this->health->for($account) : null,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'last_sync_attempt_at' => $account->last_sync_attempt_at?->toIso8601String(),
                'last_sync_error_category' => $account->last_sync_error_category,
                'next_sync_at' => $account->next_sync_at?->toIso8601String(),
                'access_lost_at' => $account->access_lost_at?->toIso8601String(),

                /*
                 * COMMERCE-QUOTA-001 — said out loud on the row rather than left to be inferred.
                 * A store goes through the same explicit assignment and costs nothing against the
                 * Connected Ad Accounts cap, and the row that spends a slot should be the row that
                 * says so.
                 */
                'counts_toward_ad_account_quota' => $account->account_type === 'ad_account',
            ];
        }, $accounts);
    }

    /**
     * How many accounts are in each state across the WHOLE tenant.
     *
     * @return array<string, int>
     */
    private function summarise(string $tenantId): array
    {
        $assignedIds = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('external_account_id')
            ->unique();

        $base = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('account_type', ['ad_account', 'store']);

        $assigned = (clone $base)->whereIn('id', $assignedIds)->count();

        $countUnassigned = fn (?string $managementState): int => (clone $base)
            ->when($managementState === null, fn ($q) => $q->whereNull('management_state'))
            ->when($managementState !== null, fn ($q) => $q->where('management_state', $managementState))
            ->whereNotIn('id', $assignedIds)
            ->count();

        return [
            AccountLifecycle::DISCOVERED => $countUnassigned(null),
            AccountLifecycle::ENABLED => $countUnassigned(AccountLifecycle::ENABLED),
            AccountLifecycle::EXCLUDED => $countUnassigned(AccountLifecycle::EXCLUDED),
            AccountLifecycle::ASSIGNED => $assigned,
            'total' => (clone $base)->count(),
        ];
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
