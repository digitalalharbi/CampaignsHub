<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertEventDecision;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertDecisions;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Alerts\Services\AlertImpact;
use App\Domains\Alerts\Services\AlertRuleRecipients;
use App\Domains\Alerts\Services\AttentionBoard;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    private const THRESHOLD_KEYS = ['days', 'pct', 'ratio', 'minutes', 'hours'];

    private const TYPES = [
        'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop', 'no_results',
        'sync_failure', 'token_expiry', 'report_failed', 'sla_warning',
        // LEAD-SLA-NOTIFICATION-001. Three types rather than one, because they are three different
        // failures with three different people to tell — and a rule carries `project_id` and a
        // `threshold`, so the SLA is the client's own rather than a constant somebody compromised on.
        'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue',
        // AUTOMATION-FIRST-OPERATIONS-001 — the type that needs no threshold from anybody: it
        // compares a figure against its own recent behaviour, so it covers the campaigns nobody
        // got round to writing a rule for, which are the ones that fail quietly.
        'metric_anomaly',
        // EMAIL-ALERT-SYNC-OUTAGE-001 / EMAIL-ALERT-MEASUREMENT-OUTAGE-001 — the freshness engine's
        // verdict as an alert, and the measurement property's own sync as another.
        'sync_outage', 'measurement_outage',
        // TRACKING-HEALTH-001 — the findings of the Tracking Health page, as alerts.
        'tracking_health',
        // LAUNCH-ADVANCE-001 — an account that earned a better launch stage since its plan went out.
        'launch_advance',
        // GA4-ALERTS-001 — the store as GA4 measured it.
        'ga4_sales_drop', 'ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift', 'ga4_attribution_gap', 'ga4_tracking_loss', 'ga4_unmatched_spend',
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

    public function __construct(private readonly ClientScopeResolver $scopes, private readonly MembershipContext $membership) {}

    /**
     * The projects this reader may be shown alerts for, or null when nothing narrows them (ADR 0002).
     *
     * The membership's client ceiling — via the canonical {@see ClientScopeResolver} — gives the clients, their
     * projects are the reach; a membership that also names projects narrows that further. Alerts with no project
     * (a connection's token, say) belong to the workspace rather than to a client, so a capped reader is not
     * shown them: it cannot be proved they are theirs. An unrestricted reader sees everything, as before.
     *
     * @return list<string>|null
     */
    private function reach(User $user): ?array
    {
        $clientScope = $this->membership->clientScopeIds();
        $projectScope = $this->membership->projectScopeIds();

        /*
         * Only a membership that NAMES clients or projects narrows the ledger. A member who names none keeps the
         * workspace-wide view this screen has always given — the alerts roles are tenant-level, and the rule that
         * an empty scope reaches nothing is the resolver's for CLIENT surfaces, not a reason to blank an alerts
         * screen for a role that was never confined.
         */
        if ($clientScope === [] && $projectScope === []) {
            return null;
        }

        if ($clientScope === []) {
            return $projectScope;
        }

        // The resolver is the one place that turns a membership into reachable clients (a named list outranks any permission).
        $clients = $this->scopes->reachableClientIds($user) ?? $clientScope;
        $ofClients = Project::query()->whereIn('client_workspace_id', $clients)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return $projectScope === [] ? $ofClients : array_values(array_intersect($ofClients, $projectScope));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function narrow(Builder $query, User $user): void
    {
        $reach = $this->reach($user);
        if ($reach !== null) {
            $query->whereIn('project_id', $reach);
        }
    }

    public function rules(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);

        $query = AlertRule::query()->latest('created_at');
        $this->narrow($query, $request->user());
        // Scoped exactly as the events are (TENANCY-UNIFY-001): this project's rules plus the workspace-wide ones.
        $project = $request->string('project')->toString();
        if ($project !== '') {
            $query->where(fn ($q) => $q->where('project_id', $project)->orWhereNull('project_id'));
        }

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

    /**
     * The fields a rule is written with — one list for create and edit, so the two can never drift apart.
     *
     * On edit (`partial`) every field is optional and `type` is refused outright: a rule's type decides what its
     * threshold MEANS (`pct` for a cost rule, `days` for a token rule), so changing it in place would leave a stored
     * threshold that silently means something else. A different type is a new rule.
     *
     * @return array<string, mixed>
     */
    private function ruleValidation(bool $partial): array
    {
        $rules = [
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // A project of THIS workspace — an id from elsewhere is refused, never stored (TENANCY-UNIFY-001).
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())->whereNull('deleted_at')],
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
            /*
         * LEAD-SLA-NOTIFICATION-001 — a follow-up SLA is counted in MINUTES.
         *
         * Days is the wrong unit for the thing being promised: «we call within the hour» is the
         * commitment a lead-generation client sells, and expressing it as a fraction of a day is
         * how it stops being checkable. The ceiling is a week, past which the rule is not an SLA.
         */
            'threshold.minutes' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:10080'],
            'threshold.hours' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:720'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:5', 'max:20160'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:in_app,email,whatsapp'],
            'create_task' => ['nullable', 'boolean'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'active' => ['nullable', 'boolean'],
            // ALERT-RULES-MANAGE-001 — who hears the rule; checked against this workspace in validatedRecipients().
            'recipients' => ['nullable', 'array:project_manager,user_ids'],
            'recipients.project_manager' => ['sometimes', 'boolean'],
            'recipients.user_ids' => ['sometimes', 'array', 'max:'.AlertRuleRecipients::MAX_NAMED],
            'recipients.user_ids.*' => ['integer', 'distinct'],
        ];

        if ($partial) {
            $rules['type'] = ['prohibited'];
            $rules['name'] = ['sometimes', 'string', 'min:2', 'max:120'];
            $rules['project_id'] = ['sometimes', ...$rules['project_id']];
        }

        return $rules;
    }

    /**
     * The rule's recipients, normalised — or a 422 naming anybody this rule may not name.
     *
     * @return array{project_manager:bool,user_ids:list<int>}|null
     */
    private function validatedRecipients(mixed $input, ?string $projectId): ?array
    {
        $recipients = AlertRuleRecipients::normalise($input);
        if ($recipients === null) {
            return null;
        }

        $refused = app(AlertRuleRecipients::class)->refused(app(TenantContext::class)->tenantId(), $projectId, $recipients['user_ids']);
        if ($refused !== []) {
            throw ValidationException::withMessages([
                'recipients.user_ids' => 'هذا الشخص ما يقدر يستلم تنبيهات هذا المشروع.',
            ]);
        }

        return $recipients;
    }

    /**
     * A rule files alerts into the ledger under its project: it has to be one the author reaches, and a capped author
     * cannot make (or move a rule to) a workspace-wide rule.
     */
    private function assertAuthorReachesProject(Request $request, ?string $projectId): void
    {
        $reach = $this->reach($request->user());
        if ($reach === null) {
            return;
        }
        if ($projectId === null) {
            abort(403, 'A rule must name one of your projects.');
        }
        if (! in_array($projectId, $reach, true)) {
            throw ValidationException::withMessages(['project_id' => 'The selected project id is invalid.']);
        }
    }

    /** A rule outside the reader's reach does not exist for them — the same 404 an event outside it gets. */
    private function assertReachesRule(Request $request, AlertRule $rule): void
    {
        $reach = $this->reach($request->user());

        abort_unless($reach === null || ($rule->project_id !== null && in_array((string) $rule->project_id, $reach, true)), 404);
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

        $data = $request->validate($this->ruleValidation(partial: false));
        $this->assertAuthorReachesProject($request, $data['project_id'] ?? null);
        $recipients = $this->validatedRecipients($request->input('recipients'), $data['project_id'] ?? null);

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
            'recipients' => $recipients,
            'created_by' => $request->user()->id, // guaranteed by the alerts.manage guard above
        ]);

        return ApiResponse::success($rule, 'Alert rule created.', status: 201);
    }

    /**
     * ALERT-RULES-MANAGE-001 — edit a rule in place: its name, threshold, severity, the time between alerts, channels,
     * recipients, project, and whether it is on.
     *
     * Only what is sent changes; a threshold that is sent replaces the stored one whole (the same validation as create,
     * so a threshold that would silence the rule is refused here too). Pausing (`active: false`) stops the evaluator
     * from sweeping it and leaves the alerts it already raised exactly as they are — they are still somebody's decision.
     */
    public function updateRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReachesRule($request, $alertRule);
        $this->rejectUnknownThresholdKeys($request);

        $data = $request->validate($this->ruleValidation(partial: true));

        $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : ($alertRule->project_id === null ? null : (string) $alertRule->project_id);
        if (array_key_exists('project_id', $data)) {
            $this->assertAuthorReachesProject($request, $projectId);
        }

        // Recipients are re-checked against the rule's project whenever either changes: moving a rule to another
        // project must not carry a person who cannot see that project along with it.
        if (array_key_exists('recipients', $data) || array_key_exists('project_id', $data)) {
            $data['recipients'] = $this->validatedRecipients(
                array_key_exists('recipients', $data) ? $request->input('recipients') : $alertRule->recipients,
                $projectId,
            );
        }

        $alertRule->fill(array_intersect_key($data, array_flip([
            'name', 'project_id', 'threshold', 'cooldown_minutes', 'channels', 'create_task', 'severity', 'active', 'recipients',
        ])));
        $alertRule->save();

        return ApiResponse::success($alertRule->fresh(), 'Alert rule updated.');
    }

    /**
     * ALERT-RULES-MANAGE-001 — remove a rule.
     *
     * Soft: `alert_events.rule_id` cascades, so a hard delete would erase every alert the rule ever raised along with
     * the decisions and notes on them. The rule is switched off and soft-deleted, and its alerts that are still open or
     * snoozed are closed without a message — the rule that raised them no longer watches anything, so nobody is going
     * to be told they recovered. The context records why they closed.
     */
    public function destroyRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReachesRule($request, $alertRule);

        DB::transaction(function () use ($alertRule): void {
            $now = Carbon::now();
            AlertEvent::query()->where('rule_id', $alertRule->id)->whereIn('status', ['open', 'snoozed'])->get()
                ->each(function (AlertEvent $event) use ($now): void {
                    $event->forceFill([
                        'status' => 'resolved', 'resolved_at' => $now, 'snoozed_until' => null,
                        'context' => array_merge((array) $event->context, ['closed_reason' => 'rule_removed']),
                    ])->save();
                });
            $alertRule->forceFill(['active' => false])->save();
            $alertRule->delete();
        });

        return ApiResponse::success(null, 'Alert rule removed.');
    }

    /**
     * ALERT-RULES-MANAGE-001 — the people a rule on this project (or workspace-wide) may name as recipients.
     *
     * Members of this workspace on an internal portal whose membership reaches the project; see AlertRuleRecipients.
     */
    public function recipientOptions(Request $request, AlertRuleRecipients $recipients): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $project = $request->string('project')->toString();
        $projectId = $project === '' ? null : $project;
        if ($projectId !== null) {
            $reach = $this->reach($request->user());
            abort_unless($reach === null || in_array($projectId, $reach, true), 404);
        }

        return ApiResponse::success($recipients->options(app(TenantContext::class)->tenantId(), $projectId), 'Alert rule recipients.');
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
        $this->narrow($query, $request->user());

        /*
         * ANALYTICS-FILTER-TRUTH-001 — the ledger answers for the project the reader is standing in.
         *
         * `AlertEvent` is tenant-scoped and nothing narrowed it further, so an agency holding ten
         * clients opened one client's workspace and was shown ten clients' alerts on one screen,
         * with nothing on any row to say whose. The page's own empty state promised the opposite in
         * as many words — «no active rule has fired for this project».
         *
         * A NULL project is kept deliberately rather than swept out with the neighbours:
         * `AlertEvaluator::tokenExpiries()` writes one on purpose, because a token belongs to a
         * connection that may feed several projects or none. Hiding those on every screen a person
         * actually opens would trade a leak for a silence, and a silence is the worse of the two.
         *
         * An unknown id narrows to the account-wide rows and stops there. It does not fall back to
         * the whole tenant: «an empty filtered scope never falls back to unfiltered» is this
         * requirement's rule, and the fallback is exactly how a chip naming somebody else's project
         * would come to sit above everybody's alerts.
         */
        $project = $request->string('project')->toString();
        // A project outside the reader's reach is answered like one that does not exist.
        abort_if($project !== '' && ($reach = $this->reach($request->user())) !== null && ! in_array($project, $reach, true), 404);
        if ($project !== '') {
            $query->where(fn ($q) => $q->where('project_id', $project)->orWhereNull('project_id'));
        }

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
            $this->anatomised($this->triageOrder(clone $query)->limit(self::EVENT_PAGE_SIZE)->get()->all()),
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
                // What was actually answered, so a badge can never describe a wider ledger than the list.
                'project' => $project === '' ? null : $project,
            ],
        );
    }

    /**
     * @param  list<AlertEvent>  $events
     * @return list<AlertEvent>
     */
    private function anatomised(array $events): array
    {
        $board = app(AttentionBoard::class);
        foreach ($events as $event) {
            $board->anatomised($event);
        }

        return $events;
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
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
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
        $this->assertReaches($request, $alertEvent);

        $resolved = $evaluator->resolve($alertEvent);
        app(AlertDecisions::class)->lifecycle($resolved, $request->user(), 'resolve');

        return ApiResponse::success($resolved, 'Alert resolved.');
    }

    public function snooze(Request $request, AlertEvent $alertEvent, AlertEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReaches($request, $alertEvent);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:20160']]);

        $snoozed = $evaluator->snooze($alertEvent, Carbon::now()->addMinutes($data['minutes']));
        app(AlertDecisions::class)->lifecycle($snoozed, $request->user(), 'snooze', $data['minutes'].' min');

        return ApiResponse::success($snoozed, 'Alert snoozed.');
    }

    /**
     * OPERATIONAL-INTELLIGENCE-P1-001 — the exception-first board: status matrix + ranked «needs your decision» list.
     * Same reach and project narrowing as the ledger (a project outside the reader's reach is a 404).
     */
    public function attention(Request $request, AttentionBoard $board): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);
        $project = $request->string('project')->toString();
        $reach = $this->reach($request->user());
        abort_if($project !== '' && $reach !== null && ! in_array($project, $reach, true), 404);

        return ApiResponse::success($board->build($reach, $project === '' ? null : $project), 'Attention board.');
    }

    /** One alert with its anatomy, decision log, impact result and the members who may own it. */
    public function show(Request $request, AlertEvent $alertEvent, AttentionBoard $board, AlertImpact $impact, AlertDecisions $decisions): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.view'), 403);
        $this->assertReaches($request, $alertEvent);
        $board->anatomised($alertEvent);

        $log = AlertEventDecision::query()->where('alert_event_id', $alertEvent->id)->orderBy('seq')->get();
        $names = User::query()->whereIn('id', $log->pluck('user_id')->merge($log->pluck('to_owner_id'))->merge($log->pluck('from_owner_id'))->push($alertEvent->owner_id)->filter()->unique()->all())->pluck('name', 'id');
        $canManage = (bool) $request->user()->hasPermission('alerts.manage');

        return ApiResponse::success([
            'event' => $alertEvent,
            'owner' => $alertEvent->owner_id === null ? null : ['id' => (int) $alertEvent->owner_id, 'name' => (string) ($names[$alertEvent->owner_id] ?? '')],
            'log' => $log->map(fn (AlertEventDecision $d): array => [
                'id' => (string) $d->id, 'kind' => $d->kind, 'by' => $names[$d->user_id] ?? null, 'at' => $d->created_at?->toIso8601String(),
                'from_status' => $d->from_status, 'to_status' => $d->to_status, 'action_tag' => $d->action_tag, 'note' => $d->note,
                'from_owner' => $names[$d->from_owner_id] ?? null, 'to_owner' => $names[$d->to_owner_id] ?? null,
            ])->all(),
            'impact' => $impact->measure($alertEvent),
            'assignees' => $canManage ? $decisions->candidates($alertEvent) : [],
            'action_tags' => AlertDecisions::ACTION_TAGS,
        ], 'Alert.');
    }

    public function decide(Request $request, AlertEvent $alertEvent, AlertDecisions $decisions): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReaches($request, $alertEvent);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AlertDecisions::STATUSES)],
            'reason' => ['required_if:status,dismissed', 'nullable', 'string', 'min:3', 'max:500'],
            'action_tag' => ['nullable', 'string', 'in:'.implode(',', AlertDecisions::ACTION_TAGS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success(
            $decisions->setStatus($alertEvent, $request->user(), $data['status'], $data['reason'] ?? null, $data['action_tag'] ?? null, $data['note'] ?? null),
            'Decision recorded.',
        );
    }

    public function assign(Request $request, AlertEvent $alertEvent, AlertDecisions $decisions): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReaches($request, $alertEvent);
        $data = $request->validate(['owner_id' => ['present', 'nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);

        if ($data['owner_id'] !== null) {
            // A member of THIS tenant whose own reach contains the alert's project — anyone else is "not found", never assigned.
            $candidate = User::query()->inTenant((string) $alertEvent->tenant_id)->whereKey($data['owner_id'])->first();
            if ($candidate === null || ! $decisions->mayOwn($alertEvent, $candidate)) {
                throw ValidationException::withMessages(['owner_id' => 'The selected owner cannot take this alert.']);
            }
        }

        return ApiResponse::success($decisions->assign($alertEvent, $request->user(), $data['owner_id'] === null ? null : (int) $data['owner_id'], $data['note'] ?? null), 'Owner set.');
    }

    public function note(Request $request, AlertEvent $alertEvent, AlertDecisions $decisions): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('alerts.manage'), 403);
        $this->assertReaches($request, $alertEvent);
        $data = $request->validate(['note' => ['required', 'string', 'min:1', 'max:500']]);
        $decisions->note($alertEvent, $request->user(), $data['note']);

        return ApiResponse::success(null, 'Note added.', status: 201);
    }

    /** An event of a project outside the reader's reach does not exist for them. */
    private function assertReaches(Request $request, AlertEvent $event): void
    {
        $reach = $this->reach($request->user());

        abort_unless($reach === null || ($event->project_id !== null && in_array((string) $event->project_id, $reach, true)), 404);
    }
}
