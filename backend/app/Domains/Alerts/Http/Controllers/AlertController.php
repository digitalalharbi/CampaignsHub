<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
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

    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);

        $query = AlertEvent::query()->latest('last_triggered_at');
        if ($status = $request->string('status')->toString()) {
            if (in_array($status, ['open', 'snoozed', 'resolved'], true)) {
                $query->where('status', $status);
            }
        }

        return ApiResponse::success($query->limit(200)->get()->all(), 'Alert events.');
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
