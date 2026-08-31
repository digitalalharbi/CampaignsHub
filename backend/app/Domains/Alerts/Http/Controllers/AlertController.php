<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tenant alerting: manage rules and triage the firing ledger. Tenant + fail-closed scoping comes from the
 * models' global scope (route-model binding 404s cross-tenant). Reads need alerts.view; mutations alerts.manage.
 */
final class AlertController extends Controller
{
    /**
     * What a person may create.
     *
     * Kept in lockstep with the `alert.type` taxonomy the picker reads — `TaxonomyEngineSeeder` says
     * so in its own comment — because a value offered by the picker and refused by this list is a 422
     * on a control the product itself drew.
     *
     * `sla_warning` is in this list and **nothing raises it**: not {@see AlertEvaluator}, not any
     * event, not any job. (`EvaluateSla` in the Requests domain emits `request.sla_warning`, which is
     * a different vocabulary in a different domain.) It stays creatable rather than being withdrawn
     * here alone, because production has already seeded it as a picker option and removing only this
     * end would trade a silent rule for a rejected form. Withdrawing it properly means the taxonomy
     * option and this list moving together, in a change that owns the migration — recorded as
     * ALERT-SLA-UNRAISED-001, not smuggled in here.
     *
     * Until then {@see AlertEvaluator::unevaluated()} logs when such a rule is swept, so a rule that
     * cannot fire is at least visible to whoever reads the logs instead of silently reporting health.
     */
    /** The threshold keys the evaluator actually reads. Anything else is a typo, not a setting. */
    private const THRESHOLD_KEYS = ['days', 'pct', 'ratio'];

    private const TYPES = [
        'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results',
        'sync_failure', 'token_expiry', 'report_failed', 'sla_warning',
    ];

    /**
     * The rules a workspace has, newest first and BOUNDED.
     *
     * It returned every row. That is fine on the day a workspace writes its third rule and wrong
     * forever after: the payload and the number of cards rendered both grow without limit, and the
     * page a customer opens to add one rule gets slower every time anybody adds one. Our own
     * acceptance suite reached 316 rules and took the page past ten seconds to paint on the third
     * browser of a run — the first honest symptom of an unbounded list.
     *
     * Newest first is what makes the cap safe rather than merely smaller: the rule somebody has just
     * created is the one at the top, so a bounded list never hides the thing the customer is looking
     * for. `meta.total` says how many there are, so an interface can say "showing the most recent
     * 100 of 316" instead of quietly presenting a truncated list as the whole set.
     */
    /** How many rules one response carries. Newest first, so the cap never hides a fresh one. */
    private const RULE_PAGE_SIZE = 100;

    public function rules(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);

        $query = AlertRule::query()->latest('created_at');

        return ApiResponse::success(
            (clone $query)->limit(self::RULE_PAGE_SIZE)->get()->all(),
            'Alert rules.',
            ['total' => $query->count(), 'limit' => self::RULE_PAGE_SIZE],
        );
    }

    /**
     * Refuse a threshold key the evaluator will never read.
     *
     * Laravel validates the keys it is given and ignores the rest, so `{"dayz": 7}` would pass every
     * rule above and be stored — a rule that looks configured and behaves as though nothing was set.
     */
    private function rejectUnknownThresholdKeys(Request $request): void
    {
        $threshold = $request->input('threshold');
        if (! is_array($threshold)) {
            return;
        }

        $unknown = array_diff(array_keys($threshold), self::THRESHOLD_KEYS);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'threshold' => 'حقل غير معروف في الحد: '.implode(', ', $unknown),
            ]);
        }
    }

    public function storeRule(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);

        /*
         * A key nobody reads is refused, so a typo does not sit on the screen looking configured
         * while doing nothing. `prohibited_unless` cannot express «no other keys», so the check is
         * explicit and names what it found.
         */
        $this->rejectUnknownThresholdKeys($request);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            /*
             * EMAIL-SETTINGS-DEPTH-001 — a threshold that would SILENCE the alert is refused.
             *
             * This was `['nullable', 'array']` and nothing more, so any shape was stored and handed
             * to an evaluator that reads `(int) $threshold['days']` and `(float) $threshold['pct']`.
             * The failure that matters is not a crash: `days: 0` gives a window with no days in it
             * and `pct: -5` a threshold every window clears, so the rule looks configured on the
             * screen, reports nothing, and is indistinguishable from an account with nothing wrong.
             * An alert that cannot fire is worse than no alert, because somebody is relying on it.
             *
             * `(int) 'soon'` is 0 in PHP, which is why `numeric` matters as much as the ranges.
             */
            'threshold' => ['nullable', 'array'],
            'threshold.days' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:365'],
            'threshold.pct' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            // A budget ratio at or above 1 is «tell me after I have overspent», which is not a risk
            // warning; at or below 0 it fires on every campaign the moment it exists.
            'threshold.ratio' => ['sometimes', 'numeric', 'gt:0', 'lt:1.5'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:5', 'max:20160'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:in_app,email,whatsapp'],
            'create_task' => ['nullable', 'boolean'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'active' => ['nullable', 'boolean'],
        ]);

        $rule = AlertRule::create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'project_id' => $data['project_id'] ?? null,
            'type' => $data['type'],
            'name' => $data['name'],
            'threshold' => $data['threshold'] ?? null,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 720,
            'channels' => $data['channels'] ?? ['in_app', 'email'],
            'create_task' => $data['create_task'] ?? false,
            'severity' => $data['severity'] ?? 'warning',
            'active' => $data['active'] ?? true,
            'created_by' => $request->user()->id, // guaranteed by the alerts.manage guard above
        ]);

        return ApiResponse::success($rule, 'Alert rule created.', status: 201);
    }

    /** How many events one response carries. The order below is what decides WHICH ones. */
    private const EVENT_PAGE_SIZE = 200;

    /**
     * The firing ledger, in the order somebody triaging it would work down.
     *
     * It used to be `latest('last_triggered_at')` capped at 200, and both halves were wrong together.
     *
     * **The order was not an order.** The evaluator writes every event of a sweep in one pass, so a
     * dozen alerts commonly share a `last_triggered_at` to the second, and the rows behind them come
     * back in whatever order Postgres finds convenient. An operator who resolves three, refreshes,
     * and sees the list rearranged cannot tell whether something fired or nothing did. Ordering ends
     * on the id for that reason — a total order, not a mostly-order.
     *
     * **And recency is the wrong axis anyway.** A critical alert that opened this morning outranks an
     * info alert that fired a minute ago, and a resolved one outranks nothing at all. So: open, then
     * snoozed, then resolved; within a status, critical before warning before info; within that,
     * newest first.
     *
     * **The cap was silent, and it lied twice.** The page reads this endpoint ONCE with no status and
     * derives its tab counts — open / critical / snoozed / resolved — by filtering the array it got
     * back. Those counts were therefore counts of the first 200 rows by recency, presented as counts
     * of everything. On a tenant past 200 events the badges were simply wrong, with nothing on screen
     * admitting it, and the sibling `rules()` endpoint had already been given `meta.total` for exactly
     * this reason. `meta.counts` is computed over the WHOLE ledger, so the badges stay true no matter
     * where the cap falls; `meta.total` and `meta.limit` let the list say it is showing 200 of 431.
     *
     * The ordering also makes the cap safe rather than merely smaller: what a cap drops is now the
     * oldest RESOLVED events — the rows nobody is triaging — instead of an open critical alert that
     * happened to fire last week.
     */
    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);

        $query = AlertEvent::query();
        if ($status = $request->string('status')->toString()) {
            if (in_array($status, ['open', 'snoozed', 'resolved'], true)) {
                $query->where('status', $status);
            }
        }

        $counts = (clone $query)
            ->selectRaw('status, severity, count(*) as n')
            ->groupBy('status', 'severity')
            ->get();

        return ApiResponse::success(
            $this->triageOrder(clone $query)->limit(self::EVENT_PAGE_SIZE)->get()->all(),
            'Alert events.',
            [
                'total' => (int) $counts->sum('n'),
                'limit' => self::EVENT_PAGE_SIZE,
                'counts' => [
                    'open' => (int) $counts->where('status', 'open')->sum('n'),
                    'snoozed' => (int) $counts->where('status', 'snoozed')->sum('n'),
                    'resolved' => (int) $counts->where('status', 'resolved')->sum('n'),
                    'open_critical' => (int) $counts->where('status', 'open')->where('severity', 'critical')->sum('n'),
                ],
            ],
        );
    }

    /**
     * Status, then severity, then recency, then id.
     *
     * Written as CASE expressions rather than a stored rank column because the rank is a reading
     * decision, not a fact about the row: a second surface is free to rank the same ledger
     * differently, and a column would quietly become the one true answer for all of them.
     *
     * An unknown status or severity sorts LAST rather than first. A value this application does not
     * recognise is not evidence of urgency, and putting it at the top of a triage queue would let a
     * bad write push real alerts off the screen.
     */
    private function triageOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case status when 'open' then 0 when 'snoozed' then 1 when 'resolved' then 2 else 3 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 when 'info' then 2 else 3 end")
            ->orderByRaw('last_triggered_at desc nulls last')
            ->orderBy('id');
    }

    public function resolve(Request $request, AlertEvent $alertEvent, AlertEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);

        return ApiResponse::success($evaluator->resolve($alertEvent), 'Alert resolved.');
    }

    public function snooze(Request $request, AlertEvent $alertEvent, AlertEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:20160']]);

        return ApiResponse::success(
            $evaluator->snooze($alertEvent, Carbon::now()->addMinutes($data['minutes'])),
            'Alert snoozed.',
        );
    }
}
