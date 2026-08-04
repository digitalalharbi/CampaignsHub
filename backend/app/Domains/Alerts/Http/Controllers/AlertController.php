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

/**
 * Tenant alerting: manage rules and triage the firing ledger. Tenant + fail-closed scoping comes from the
 * models' global scope (route-model binding 404s cross-tenant). Reads need alerts.view; mutations alerts.manage.
 */
final class AlertController extends Controller
{
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

    public function storeRule(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'threshold' => ['nullable', 'array'],
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
