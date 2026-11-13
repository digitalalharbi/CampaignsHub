<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Support\CampaignDelivery;
use App\Domains\Campaigns\Launch\JourneyPlanner;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\Campaigns\Launch\LaunchReadiness;
use App\Domains\Campaigns\Management\Write\WriteAdapterRegistry;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Domains\CRM\Access\LeadVisibility;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Services\TrackingHealth;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Models\SpendLimitEvent;
use App\Domains\Metrics\Services\ChangeTimeline;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns raw signals into alerts, honestly and without storms.
 *
 * Lifecycle per (rule, entity): the first breach opens an AlertEvent and raises a notification (via the shared
 * NotificationDispatcher, so quiet-hours / per-user preferences / in-app-vs-email honesty all apply) and,
 * optionally, a Task. A repeat breach is suppressed while the rule's COOLDOWN window is open, while the event
 * is SNOOZED, or while it stays de-duplicated — so a persistent problem alerts once, not every five minutes.
 * Resolving an event closes it; a fresh breach after the cooldown re-opens a new one.
 *
 * Alert types: budget_risk, cpa_increase, cpl_increase, roas_drop, no_results, sync_failure, token_expiry,
 * report_failed, sla_warning. Each maps to a notification type for the inbox + delivery ledger.
 */
final class AlertEvaluator
{
    /**
     * The types THIS evaluator raises on a schedule.
     *
     * `AlertController::TYPES` is what a person may create; this is what actually gets evaluated, and
     * the two drifted. `cpa_increase` and `cpl_increase` were accepted by the controller, labelled in
     * the alerts page («ارتفاع CPA»), named in this class's own docblock — and fell through `match`'s
     * `default => []`. The rule saved, listed as active, and could never fire. A silent no-op is worse
     * than a missing feature: the operator believes they are covered.
     *
     * `AlertEvaluatorCoverageTest` asserts PERIODIC + EVENT_DRIVEN + UNRAISED equals the controller's set,
     * so the two cannot drift apart again without a red test.
     */
    public const PERIODIC = [
        'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop',
        'no_results', 'sync_failure', 'token_expiry',
        // LEAD-SLA-NOTIFICATION-001 — the follow-up promises, swept on the same schedule.
        'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue',
        // AUTOMATION-FIRST-OPERATIONS-001 — the detector that existed and was never swept.
        'metric_anomaly',
        // EMAIL-ALERT-SYNC-OUTAGE-001 / EMAIL-ALERT-MEASUREMENT-OUTAGE-001 — an outage is a project
        // whose freshness engine says `sync_failed` or `stale`, and a measurement property whose
        // latest sync failed or whose last success is older than the threshold. Both read the
        // product's own freshness answers; neither invents a second definition of «stale».
        'sync_outage', 'measurement_outage',
        // TRACKING-HEALTH-001 — what the Tracking Health page calls broken or lossy, raised as alerts so it reaches people who do not open that page.
        'tracking_health',
        // LAUNCH-ADVANCE-001 — an account that now earns a better launch stage than it had when its plan went out: an opportunity, offered to a person, never applied.
        'launch_advance',
        // GA4-ALERTS-001 — the store as GA4 measured it: sales, bounce, conversion, basket, the platforms' claims against GA4, lost tracking, unmatched spend.
        'ga4_sales_drop', 'ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift', 'ga4_attribution_gap', 'ga4_tracking_loss', 'ga4_unmatched_spend',
    ];

    /** The default age, in hours, after which a measurement property with no successful sync is an outage. */
    private const MEASUREMENT_OUTAGE_HOURS = 12;

    /**
     * How recent an anomalous day must be for `metric_anomaly` to send anything.
     *
     * Two days rather than one because providers land a day late and some land two: «yesterday» is
     * frequently the newest day that exists at all, and a one-day window would make the type fire
     * only on the accounts whose platform happens to report fastest.
     */
    private const ANOMALY_RECENCY_DAYS = 2;

    /**
     * The shortest window `metric_anomaly` will read, whatever the rule asks for.
     *
     * `ChangeTimeline` needs five prior days before it will judge a sixth and returns
     * `window_too_short_to_have_a_baseline` otherwise — so a rule saved with `days: 3` would be a
     * rule that can never fire, which is the exact silent no-op this class's PERIODIC list exists to
     * prevent. The floor leaves it able to answer instead of quietly answering nothing.
     */
    private const ANOMALY_MIN_WINDOW_DAYS = 14;

    /** Raised by the thing that failed, not by a sweep — a report failing is an event, not a threshold. */
    public const EVENT_DRIVEN = ['report_failed'];

    /**
     * ALERT-CRITERIA-001 — event types that are not rule types: notices the «صرف بدون نتائج» sweep raises about the campaigns it
     * reads («توقفت أمس», «ما تصرف»). Nobody creates a rule of these types; they live and close under the no_results rule.
     */
    public const NOTICES = ['campaign_stopped', 'not_spending'];

    /**
     * Creatable, and raised by nothing at all — ALERT-SLA-UNRAISED-001.
     *
     * `sla_warning` is offered by the picker and accepted by the controller, and no code anywhere
     * raises it. (`EvaluateSla` emits `request.sla_warning` in the Requests domain — a different
     * vocabulary.) Withdrawing it means moving the `alert.type` taxonomy option and the controller
     * list together, with a migration for the options already seeded in production; doing only part
     * of that turns a silent rule into a rejected form. It is named here so the coverage test can
     * assert it is KNOWN rather than merely missing.
     */
    public const UNRAISED = ['sla_warning'];

    /** alert rule type → notification type (the notification center's vocabulary). */
    private const NOTIFICATION_TYPE = [
        'budget_risk' => 'budget_risk',
        'cpa_increase' => 'budget_risk',
        'cpl_increase' => 'budget_risk',
        'roas_drop' => 'budget_risk',
        'no_results' => 'budget_risk',
        // ALERT-CRITERIA-001 — the campaign notices raised by the «صرف بدون نتائج» sweep.
        'campaign_stopped' => 'performance',
        'not_spending' => 'budget_risk',
        'sync_failure' => 'sync_failed',
        'sync_outage' => 'sync_failed',
        'measurement_outage' => 'sync_failed',
        // GA4-ALERTS-001 — a data-quality finding about the store's measurement.
        'ga4_sales_drop' => 'performance',
        'ga4_bounce_spike' => 'performance',
        'ga4_cvr_drop' => 'performance',
        'ga4_aov_shift' => 'performance',
        'ga4_attribution_gap' => 'performance',
        'ga4_tracking_loss' => 'sync_failed',
        'ga4_unmatched_spend' => 'performance',
        // A broken form or a failing conversion delivery is a data-pipeline failure to the person who is told about it.
        'tracking_health' => 'sync_failed',
        'token_expiry' => 'token_expiring',
        'report_failed' => 'report_failed',
        'sla_warning' => 'security',
        // LEAD-SLA-NOTIFICATION-001 — one notification type for three rules: a reader who wants to
        // hear about follow-up wants all three, and three switches is a preferences page nobody
        // finishes reading.
        'lead_unassigned' => 'lead_sla',
        'lead_no_contact' => 'lead_sla',
        'lead_follow_up_overdue' => 'lead_sla',
    ];

    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly MetricsAggregator $metrics,
        private readonly TenantContext $tenants,
        private readonly SpendLimitGovernor $limits,
        private readonly DataFreshnessService $freshness,
        private readonly AlertEventMailer $mailer,
    ) {}

    /**
     * EMAIL-ALERT-EVENTS-001 — what this sweep raised, refreshed or recovered, per tenant, until the
     * mailer takes it. One bundle per person per sweep is the aggregation rule.
     *
     * @var list<array<string,mixed>>
     */
    private array $outbox = [];

    /** Evaluate every active rule across all tenants. Returns the number of newly raised alerts. */
    public function evaluateAll(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $raised = 0;

        // Rules are tenant-scoped models; iterate tenant-by-tenant so every scoped query (metrics, connections,
        // campaigns) resolves inside the correct tenant boundary.
        $tenantIds = DB::table('alert_rules')->where('active', true)->distinct()->pluck('tenant_id');
        $previous = $this->tenants->tenantId();

        $this->inSweep = true;
        try {
            foreach ($tenantIds as $tenantId) {
                $this->tenants->setTenantId((string) $tenantId);
                $this->delivery()->forget();
                AlertRule::query()->where('active', true)->get()
                    ->each(function (AlertRule $rule) use ($now, &$raised) {
                        $raised += $this->evaluateRule($rule, $now);
                    });
                $this->flushMail((string) $tenantId, $now);
            }
        } finally {
            $this->inSweep = false;
            $this->delivery()->forget();
        }

        $this->tenants->setTenantId($previous);

        return $raised;
    }

    /** Evaluate one rule; returns newly raised alerts for it. */
    /**
     * Hand the sweep's outbox for one tenant to the mailer. `evaluateAll()` does this per tenant;
     * callers that evaluate single rules call it themselves when they want the mail to go.
     *
     * @return array<string,int>
     */
    public function flushMail(string $tenantId, ?Carbon $now = null): array
    {
        $items = $this->outbox;
        $this->outbox = [];

        return $this->mailer->sweep($tenantId, $items, $now ?? Carbon::now());
    }

    public function evaluateRule(AlertRule $rule, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        // A sweep reads each campaign set's delivery once for all its rules; a single rule evaluated alone reads afresh.
        if (! $this->inSweep) {
            $this->delivery()->forget();
        }
        $breaches = match ($rule->type) {
            'sync_failure' => $this->syncFailures($rule),
            'sync_outage' => $this->syncOutages($rule, $now),
            'measurement_outage' => $this->measurementOutages($rule, $now),
            'tracking_health' => $this->trackingHealth($rule, $now),
            'launch_advance' => $this->launchAdvance($rule),
            'ga4_sales_drop' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_bounce_spike' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_cvr_drop' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_aov_shift' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_attribution_gap' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_tracking_loss' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'ga4_unmatched_spend' => app(Ga4AlertSignals::class)->breaches($rule, $now),
            'token_expiry' => $this->tokenExpiries($rule, $now),
            'budget_risk' => $this->budgetRisks($rule, $now),
            'no_results' => $this->noResults($rule, $now),
            'roas_drop' => $this->roasDrops($rule, $now),
            'cpa_increase' => $this->costPerIncreases($rule, $now, 'cpa', 'conversions'),
            'cpl_increase' => $this->costPerIncreases($rule, $now, 'cpl', 'leads'),
            /*
             * LEAD-SLA-NOTIFICATION-001 — the follow-up promises, on the engine that already exists.
             *
             * These are deliberately three types rather than one `sla_warning`, because they are
             * three different failures with three different people to tell: nobody owns it, the
             * owner has not tried, and a promise made has passed. One alert saying «SLA breach»
             * would need the reader to open the product to learn which — and the whole point of an
             * alert is to save that trip.
             *
             * Cooldown, dedup, snooze, channels, tasks and the audit trail are the engine's, not
             * theirs; a second notification engine is what this requirement explicitly refuses.
             */
            'lead_unassigned' => $this->leadsUnassigned($rule, $now),
            'lead_no_contact' => $this->leadsNotContacted($rule, $now),
            'lead_follow_up_overdue' => $this->leadFollowUpsOverdue($rule, $now),
            /*
             * AUTOMATION-FIRST-OPERATIONS-001 — the one threshold nobody has to choose.
             *
             * Every other type above needs a number from a person: how many percent, how many days.
             * That works for the campaign somebody is watching and fails at the scale this product
             * is for — nobody sets a CPA threshold on four hundred campaigns, so the ones nobody set
             * one for are the ones that go wrong quietly. This type asks a different question: did
             * this figure depart from ITS OWN recent behaviour. No threshold to maintain, and it
             * applies to an account the day it connects.
             */
            'metric_anomaly' => $this->metricAnomalies($rule, $now),
            default => $this->unevaluated($rule),
        };

        $raised = 0;
        foreach ($breaches as $b) {
            if ($this->raise($rule, $b, $now)) {
                $raised++;
            }
        }

        $this->recoverCleared($rule, $breaches, $now);

        return $raised;
    }

    /**
     * EMAIL-ALERT-RECOVERY-001 — an alert whose condition is no longer present is resolved by the sweep
     * that found it absent, and the reader is told so.
     *
     * Until now an event stayed open until a person pressed «resolve», so the alerts list carried
     * problems that had ended days earlier, and nobody was ever told «this is over». Recovery is only
     * stated for the rule types this sweep can actually evaluate: a type the evaluator does not read
     * (`report_failed`, `sla_warning`, an unknown type) produces an empty breach list for a different
     * reason, and an empty list there must not resolve anything.
     *
     * @param  list<array<string,mixed>>  $breaches
     */
    private function recoverCleared(AlertRule $rule, array $breaches, Carbon $now): void
    {
        if (! in_array($rule->type, self::PERIODIC, true)) {
            return;
        }

        $still = [];
        foreach ($breaches as $b) {
            $still[$this->dedupKey($rule, $b)] = true;
        }

        AlertEvent::query()
            ->where('rule_id', $rule->id)
            ->whereIn('status', ['open', 'snoozed'])
            ->get()
            ->each(function (AlertEvent $event) use ($still, $rule, $now): void {
                if (isset($still[$event->dedup_key])) {
                    return;
                }
                // ALERT-DELIVERING-YESTERDAY-001 — yesterday's figures not proven yet (a late or failed sync): no verdict either way.
                if (($this->ineligible[(string) $rule->id][(string) $event->entity_id] ?? null) === CampaignDelivery::DATA_PENDING
                    || (($this->noResultsState[(string) $rule->id][(string) $event->entity_id]['reason'] ?? null) === CampaignDelivery::DATA_PENDING)) {
                    return;
                }
                // ALERT-CRITERIA-001 — a notice («توقفت أمس», «ما تصرف») whose day has passed did not «recover»: it closes silently.
                if ((string) $event->type !== (string) $rule->type) {
                    $this->closeQuietly($event, 'notice_over', $now);

                    return;
                }
                $quiet = $this->notApplicable($rule, $event);
                if ($quiet !== null) {
                    $this->closeQuietly($event, $quiet, $now);

                    return;
                }
                $this->recover($rule, $event, $now);
            });
    }

    /**
     * ALERT-OBJECTIVE-RESULT-001 — why an open «صرف بدون نتائج» is no longer a question this rule asks, or null when its condition
     * really cleared.
     *
     * A campaign that was paused or ended, one whose objective nobody set, one whose result its platforms do not report, and one
     * alerted on a figure that is no longer its result (the old rule read `conversions` on a traffic campaign that brought visits)
     * did not RECOVER: telling the reader «تعافى» about them would be a claim nobody measured.
     */
    private function notApplicable(AlertRule $rule, AlertEvent $event): ?string
    {
        // ALERT-DELIVERING-YESTERDAY-001 — a campaign the performance rules no longer ask about (stopped, ended, not delivering
        // yesterday) did not recover either: its open ROAS / cost / anomaly alert closes with the reason, silently.
        $ineligible = $this->ineligible[(string) $rule->id][(string) $event->entity_id] ?? null;
        if ($ineligible !== null) {
            return $ineligible;
        }
        if ($rule->type !== 'no_results') {
            return null;
        }
        $state = $this->noResultsState[(string) $rule->id][(string) $event->entity_id] ?? null;
        if ($state === null) {
            return null;
        }
        if ($state['reason'] !== null) {
            return $state['reason'];
        }

        // An alert raised before the objective decided the result was raised on `conversions`.
        return ($event->context['result_key'] ?? 'conversions') === $state['result_key'] ? null : 'result_redefined';
    }

    /** Close an alert that no longer applies: resolved, with its reason, and no notification or mail — nothing recovered. */
    private function closeQuietly(AlertEvent $event, string $reason, Carbon $now): void
    {
        $context = (array) $event->context;
        $context['closed_reason'] = $reason;
        $context['closed_at'] = $now->toIso8601String();

        $this->resolve($event, $now);
        $event->forceFill(['context' => $context])->save();
    }

    /**
     * What the last `noResults()` sweep found about each campaign it read, per rule: why it is not asked about (`reason`), or
     * which result it is judged on (`result_key`).
     *
     * @var array<string, array<string, array{reason: ?string, result_key: ?string}>>
     */
    private array $noResultsState = [];

    /**
     * ALERT-DELIVERING-YESTERDAY-001 — per rule, the campaigns the last sweep of a ROAS, cost or anomaly rule did not ask
     * about, and why ({@see CampaignDelivery}).
     *
     * @var array<string, array<string, string>>
     */
    private array $ineligible = [];

    private bool $inSweep = false;

    private ?CampaignDelivery $delivery = null;

    private function delivery(): CampaignDelivery
    {
        return $this->delivery ??= app(CampaignDelivery::class);
    }

    /** @return array{min_spend?: mixed, min_impressions?: mixed} the rule's own minimum sample, when it states one */
    private static function sample(AlertRule $rule): array
    {
        return array_intersect_key((array) ($rule->threshold ?? []), ['min_spend' => true, 'min_impressions' => true]);
    }

    /**
     * The campaigns a performance rule may judge — running by the platform's latest status and, unless `$statusOnly`,
     * delivering yesterday — with the others recorded for {@see notApplicable()}.
     *
     * @param  Collection<int, UnifiedCampaign>  $campaigns
     * @return Collection<int, UnifiedCampaign>
     */
    private function eligibleOnly(AlertRule $rule, Collection $campaigns, Carbon $now, int $days, bool $statusOnly = false): Collection
    {
        $verdicts = $this->delivery()->assess($campaigns, $now, $days, null, self::sample($rule));
        $skipped = [];
        $kept = $campaigns->filter(static function (UnifiedCampaign $c) use ($verdicts, $statusOnly, &$skipped): bool {
            $reason = $verdicts[(string) $c->getKey()]['reason'] ?? null;
            if ($reason === null || ($statusOnly && in_array($reason, [CampaignDelivery::NOT_DELIVERING, CampaignDelivery::LEARNING, CampaignDelivery::DATA_PENDING], true))) {
                return true;
            }
            $skipped[(string) $c->getKey()] = $reason;

            return false;
        })->values();
        $this->ineligible[(string) $rule->id] = $skipped;

        return $kept;
    }

    private function recover(AlertRule $rule, AlertEvent $event, Carbon $now): void
    {
        $title = (string) ($event->context['title'] ?? $rule->name);
        $titleAr = (string) ($event->context['title_ar'] ?? $title);
        $context = (array) $event->context;
        $context['recovered_at'] = $now->toIso8601String();

        $this->resolve($event, $now);
        $event->forceFill(['context' => $context])->save();

        $this->notifications->dispatch([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $event->project_id ? (string) $event->project_id : $rule->project_id,
            'type' => self::NOTIFICATION_TYPE[(string) $event->type] ?? self::NOTIFICATION_TYPE[$rule->type] ?? 'performance',
            'severity' => 'info',
            'title' => 'Recovered: '.$title,
            'message' => 'The condition behind «'.$title.'» was no longer present at '.$now->toDateTimeString().'. The alert is resolved.',
            'title_ar' => 'تعافى: '.$titleAr,
            'message_ar' => 'لم تعد حالة «'.$titleAr.'» قائمة عند '.$now->toDateTimeString().'. أُغلق التنبيه.',
            'source' => 'alerts',
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id,
            'action_url' => '/alerts',
            // The alert itself was notified on this type+entity minutes ago; its recovery is a different
            // message, not a repeat of it, so it carries its own key past the dispatcher's dedup window.
            'dedup_extra' => 'recovered:'.$event->id,
        ]);

        $this->post($rule, $event, [
            'title' => 'Recovered: '.$title,
            'title_ar' => 'تعافى: '.$titleAr,
            'message' => 'The condition behind «'.$title.'» was no longer present at '.$now->toDateTimeString().'. The alert is resolved.',
            'message_ar' => 'لم تعد حالة «'.$titleAr.'» قائمة عند '.$now->toDateTimeString().'. أُغلق التنبيه.',
            'project_id' => $event->project_id ? (string) $event->project_id : $rule->project_id,
            'context' => [],
        ], 'info', $now, recovered: true);
    }

    private function dedupKey(AlertRule $rule, array $b): string
    {
        // A notice raised under a rule of another type (ALERT-CRITERIA-001: «توقفت أمس», «ما تصرف» under «صرف بدون نتائج») is its own
        // finding about the same campaign, so it carries its type in the key; the rule's own type keeps the key it always had.
        $kind = isset($b['type']) && $b['type'] !== $rule->type ? [(string) $b['type']] : [];

        return hash('sha256', implode('|', [$rule->id, $b['entity_type'], $b['entity_id'] ?? '', ...$kind]));
    }

    /**
     * Open (or refresh) an alert for a breach, honoring cooldown / snooze / dedup. Returns true only when a
     * NEW alert is opened (a within-cooldown refresh or a suppressed repeat returns false).
     *
     * @param  array{entity_type:string,entity_id:?string,project_id:?string,title:string,message:string,context:array<string,mixed>}  $b
     */
    private function raise(AlertRule $rule, array $b, Carbon $now): bool
    {
        $dedupKey = $this->dedupKey($rule, $b);
        $cooldownUntil = fn (AlertEvent $e) => ($e->last_triggered_at ?? $e->created_at)
            ->copy()->addMinutes($rule->cooldown_minutes);

        // An active (open|snoozed) event for this rule+entity?
        $active = AlertEvent::query()
            ->where('dedup_key', $dedupKey)->whereIn('status', ['open', 'snoozed'])
            ->latest('last_triggered_at')->first();

        if ($active !== null) {
            // ALERT-CRITERIA-001 — a notice is said once per episode: while it is open it is neither refreshed nor sent again.
            if (($b['once'] ?? false) === true) {
                return false;
            }
            // Snoozed into the future, or still inside the cooldown window → suppress (no storm).
            if ($active->status === 'snoozed' && $active->snoozed_until !== null && $active->snoozed_until->isFuture()) {
                return false;
            }
            if ($cooldownUntil($active)->isFuture()) {
                return false;
            }
            // Cooldown elapsed on a still-open problem: refresh the same event + re-notify (not a new alert).
            $active->forceFill([
                'last_triggered_at' => $now,
                'context' => array_merge($b['context'], ['title' => $b['title'], 'message' => $b['message']]),
                'status' => 'open',
            ] + $this->anatomy($rule, $b))->save();
            $this->notify($rule, $b);
            $this->post($rule, $active, $b, $b['severity'] ?? $rule->severity, $now);

            return false;
        }

        // No active event. If the most recent (possibly resolved) event is still inside cooldown, suppress.
        $recent = AlertEvent::query()->where('dedup_key', $dedupKey)->latest('last_triggered_at')->first();
        // A notice is said once per EPISODE, even after a quiet close: the episode is the campaign's last delivered day, which does
        // not move while it stays stopped or keeps spending nothing. It moves only when the campaign delivered again.
        if (($b['once'] ?? false) === true && $recent !== null
            && ($recent->context['last_delivered'] ?? '') === ($b['context']['last_delivered'] ?? '')) {
            return false;
        }
        if ($recent !== null && $cooldownUntil($recent)->isFuture()) {
            return false;
        }

        // Fresh alert.
        $notification = $this->notify($rule, $b);
        // A notice is information about a campaign, not work: it opens no task even when the rule's own breaches do.
        $taskId = $rule->create_task && ($b['once'] ?? false) !== true ? $this->openTask($rule, $b) : null;

        $event = AlertEvent::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'rule_id' => $rule->id,
            'type' => ($b['type'] ?? $rule->type),
            'entity_type' => $b['entity_type'],
            'entity_id' => $b['entity_id'],
            'dedup_key' => $dedupKey,
            'status' => 'open',
            'severity' => $b['severity'] ?? $rule->severity,
            // Persist the human title/message alongside the measured values so the alerts UI can render them.
            'context' => array_merge($b['context'], ['title' => $b['title'], 'message' => $b['message']]),
            'notification_id' => $notification,
            'task_id' => $taskId,
            'last_triggered_at' => $now,
        ] + $this->anatomy($rule, $b));
        $this->post($rule, $event, $b, $b['severity'] ?? $rule->severity, $now);

        return true;
    }

    /**
     * OPERATIONAL-INTELLIGENCE-P1-001 — the finding's anatomy (priority, evidence, problem/impact/action), stored with it.
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>
     */
    private function anatomy(AlertRule $rule, array $b): array
    {
        return AlertAnatomy::build((string) ($b['type'] ?? $rule->type), (array) ($b['context'] ?? []), (string) ($b['severity'] ?? $rule->severity));
    }

    /** Raise the notification through the shared dispatcher; returns the notification id (or null if deduped). */
    /** Put one event on the sweep's outbox for the mailer. */
    private function post(AlertRule $rule, AlertEvent $event, array $b, string $severity, Carbon $now, bool $recovered = false): void
    {
        $this->outbox[] = [
            'event_id' => (string) $event->id,
            'notification_type' => self::NOTIFICATION_TYPE[($b['type'] ?? $rule->type)] ?? 'performance',
            // EMAIL-DIGESTS-OPERATIONAL-001 — what the finding is ABOUT, so the mail can say it plainly and link to the exact page.
            'rule_type' => (string) ($b['type'] ?? $rule->type),
            'entity_type' => (string) ($b['entity_type'] ?? $event->entity_type ?? ''),
            'entity_id' => (string) ($b['entity_id'] ?? $event->entity_id ?? ''),
            'severity' => $severity,
            'title' => (string) $b['title'],
            'title_ar' => $b['context']['title_ar'] ?? $b['title_ar'] ?? null,
            'detail' => (string) $b['message'],
            'detail_ar' => $b['context']['message_ar'] ?? $b['message_ar'] ?? null,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'project_name' => isset($b['project_id']) ? (Project::query()->whereKey($b['project_id'])->value('name') ?? '') : '',
            'triggered_at' => ($recovered ? 'recovered:' : '').$now->toIso8601String(),
            // EMAIL-PERF-DASHBOARDS-001 — the rule's own figures travel with the finding, so the email can lead with the number.
            'context' => (array) ($b['context'] ?? []),
            // OPERATIONAL-INTELLIGENCE-P1-001 — priority and whether the evidence is thin, so the mail can say both in one line.
            'priority' => $event->priority ?? AlertAnatomy::build((string) ($b['type'] ?? $rule->type), (array) ($b['context'] ?? []), $severity)['priority'],
            'insufficient' => ($event->evidence['sufficient'] ?? AlertAnatomy::evidence((string) ($b['type'] ?? $rule->type), (array) ($b['context'] ?? []))['sufficient']) !== true,
        ];
    }

    private function notify(AlertRule $rule, array $b): ?string
    {
        $n = $this->notifications->dispatch([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'type' => self::NOTIFICATION_TYPE[($b['type'] ?? $rule->type)] ?? 'performance',
            // A notice carries its own severity («توقفت أمس» is info); a rule's breach keeps the rule's.
            'severity' => ($sev = (string) ($b['severity'] ?? $rule->severity)) === 'critical' ? 'error' : ($sev === 'info' ? 'info' : 'warning'),
            'title' => $b['title'],
            'message' => $b['message'],
            // DIGESTS-VISUAL-V2-001 — the bell's Arabic: the rule's own when it wrote one, else the mail's wording of the same finding.
            ...$this->bellArabic($rule, $b),
            'source' => 'alerts',
            'entity_type' => $b['entity_type'],
            'entity_id' => $b['entity_id'],
            /*
             * PORTAL-RELATIVE (REG-011). An alert is tenant-wide and its recipients are not all in
             * the same portal, so a fixed `/app/alerts` sent an agency operator into the advertiser
             * portal — and once that tree was guarded, into a refusal. The client resolves this
             * against whichever portal the reader is in; rows written before this still carry an
             * absolute path and are left alone by that resolution.
             */
            'action_url' => '/alerts',
        ]);

        return $n?->id !== null ? (string) $n->id : null;
    }

    /**
     * @param  array<string,mixed>  $b
     * @return array{title_ar: string, message_ar: string}
     */
    private function bellArabic(AlertRule $rule, array $b): array
    {
        $c = AlertEventText::compose([
            'rule_type' => (string) ($b['type'] ?? $rule->type), 'severity' => (string) ($b['severity'] ?? $rule->severity), 'context' => (array) ($b['context'] ?? []),
            'project_id' => $b['project_id'] ?? $rule->project_id, 'project_name' => '', 'entity_type' => '', 'entity_id' => '', 'event_id' => '', 'triggered_at' => '',
        ], true);

        return ['title_ar' => (string) ($b['title_ar'] ?? $c['title']), 'message_ar' => trim($c['numbers'] !== '' ? $c['numbers'] : $c['detail'])];
    }

    /** Open a follow-up task for the breach (create-task capability). */
    private function openTask(AlertRule $rule, array $b): string
    {
        $task = Task::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'title' => $b['title'],
            'description' => $b['message'],
            'status' => 'todo',
            'priority' => $rule->severity === 'critical' ? 'urgent' : 'high',
            'meta' => ['source' => 'alert', 'alert_type' => ($b['type'] ?? $rule->type)],
        ]);

        return (string) $task->id;
    }

    // ---- Resolve / snooze --------------------------------------------------------------------------------

    public function resolve(AlertEvent $event, ?Carbon $now = null): AlertEvent
    {
        $event->forceFill(['status' => 'resolved', 'resolved_at' => $now ?? Carbon::now(), 'snoozed_until' => null])->save();

        return $event;
    }

    public function snooze(AlertEvent $event, Carbon $until): AlertEvent
    {
        $event->forceFill(['status' => 'snoozed', 'snoozed_until' => $until])->save();

        return $event;
    }

    // ---- Signal handlers ---------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    /** EMAIL-ALERT-DETERIORATION-001 — the window and the threshold a deterioration was judged on, in one sentence. */
    private function deteriorationBasis(int $days, float $pct, string $direction): string
    {
        return 'the last '.$days.' days against the '.$days.' before; this rule fires at a '.rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').'% '.$direction.'.';
    }

    private function syncFailures(AlertRule $rule): array
    {
        // Latest sync run per connection; a failed latest run is an active problem.
        $latest = MetricSyncRun::query()
            ->whereIn('id', function ($q) {
                $q->selectRaw('DISTINCT ON (connection_id) id')
                    ->from('metric_sync_runs')
                    ->orderByRaw('connection_id, started_at DESC NULLS LAST, created_at DESC');
            })
            ->where('status', 'failed')
            ->get();

        return $latest->map(fn (MetricSyncRun $r) => [
            'entity_type' => ProviderConnection::class,
            'entity_id' => (string) $r->connection_id,
            'project_id' => $r->project_id ? (string) $r->project_id : null,
            'title' => 'Data sync failed',
            'message' => 'The latest sync for '.$r->provider.' failed'.($r->error ? ': '.$r->error : '.'),
            'context' => ['provider' => $r->provider, 'sync_run_id' => (string) $r->id, 'error' => $r->error],
        ])->all();
    }

    /** @return list<array<string,mixed>> */
    /**
     * EMAIL-ALERT-SYNC-OUTAGE-001 — a project whose sources, taken together, are failed or stale.
     *
     * The verdict is {@see DataFreshnessService::state()} over the last seven days — the same answer
     * the analytics header, the digest and the live operating view give — so an outage alert can
     * never disagree with the page the reader opens next.
     */
    private function syncOutages(AlertRule $rule, Carbon $now): array
    {
        $tenantId = (string) $rule->tenant_id;
        $projectIds = DB::table('project_integration_bindings')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($rule->project_id !== null, fn ($q) => $q->where('project_id', $rule->project_id))
            ->distinct()
            ->pluck('project_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $from = $now->copy()->subDays(6)->startOfDay();
        $to = $now->copy()->endOfDay();
        $out = [];

        foreach ($projectIds as $projectId) {
            $state = $this->freshness->state($tenantId, [$projectId], $from, $to, null, $now);
            /*
             * The SOURCE states decide, not the roll-up alone: the roll-up ranks «partial» (days with no
             * row in the window) above «stale», so a project whose only platform stopped answering four
             * days ago reads `partial` as a whole while its source reads `stale`. Both are an outage to
             * the reader; a source still awaiting its first successful read is not — that is credentials.
             */
            $troubled = array_values(array_filter(
                $state['sources'],
                static fn (array $s): bool => in_array($s['state'], ['failed', 'stale'], true),
            ));
            if ($troubled === []) {
                continue;
            }
            $failed = array_filter($troubled, static fn (array $s): bool => $s['state'] === 'failed') !== [];
            $names = implode(', ', array_map(static fn (array $s): string => (string) $s['provider'], $troubled));

            $out[] = [
                'entity_type' => Project::class,
                'entity_id' => $projectId,
                'project_id' => $projectId,
                'title' => $failed ? 'Data sync outage' : 'Data is stale',
                'message' => ($failed
                    ? 'The latest sync failed for: '.$names.'.'
                    : 'No fresh figures from: '.$names.'.')
                    .($state['last_sync_at'] ? ' Last figures dated '.$state['last_sync_at'].'.' : ' No figures have ever arrived.'),
                'context' => [
                    // The reader's language, beside the English the rest of the engine carries.
                    'title_ar' => $failed ? 'انقطاع مزامنة البيانات' : 'البيانات متأخرة',
                    'message_ar' => ($failed
                        ? 'فشلت آخر مزامنة لـ: '.$names.'.'
                        : 'لا أرقام حديثة من: '.$names.'.')
                        .($state['last_sync_at'] ? ' آخر أرقام بتاريخ '.$state['last_sync_at'].'.' : ' لم تصل أي أرقام بعد.'),
                    'state' => $state['state'],
                    'last_sync_at' => $state['last_sync_at'],
                    'missing_days' => $state['missing_days'],
                    'sources' => array_map(static fn (array $s): array => [
                        'provider' => $s['provider'], 'kind' => $s['kind'], 'state' => $s['state'], 'data_as_of' => $s['data_as_of'],
                    ], $troubled),
                ],
            ];
        }

        return $out;
    }

    /**
     * EMAIL-ALERT-MEASUREMENT-OUTAGE-001 — a measurement property (GA4) whose latest sync failed, or
     * whose last successful sync is older than the threshold while syncs keep being attempted.
     *
     * Measurement is never an ad platform (GA4 is its own ProviderKind), so it never appears in
     * `metric_sync_runs`; its runs are `integration_sync_runs` of type `measurement`.
     */
    private function measurementOutages(AlertRule $rule, Carbon $now): array
    {
        $hours = (int) ($rule->threshold['hours'] ?? self::MEASUREMENT_OUTAGE_HOURS);
        $cutoff = $now->copy()->subHours($hours);

        $runs = IntegrationSyncRun::query()
            ->where('type', 'measurement')
            ->when($rule->project_id !== null, fn ($q) => $q->where('project_id', $rule->project_id))
            ->orderByDesc('started_at')
            ->get(['id', 'project_id', 'provider_connection_id', 'status', 'error', 'started_at', 'finished_at'])
            ->groupBy(fn (IntegrationSyncRun $r) => (string) $r->provider_connection_id);

        $out = [];
        foreach ($runs as $connectionId => $group) {
            $latest = $group->first();
            $lastSuccess = $group->first(fn (IntegrationSyncRun $r) => $r->status === 'success' || $r->status === 'no_data');
            $failedNow = $latest->status === 'failed';
            $tooOld = $lastSuccess === null || ($lastSuccess->finished_at !== null && $lastSuccess->finished_at->lessThan($cutoff));

            if (! $failedNow && ! $tooOld) {
                continue;
            }

            $out[] = [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $connectionId,
                'project_id' => $latest->project_id ? (string) $latest->project_id : null,
                'title' => $failedNow ? 'Measurement sync failed' : 'Measurement data is stale',
                'message' => $failedNow
                    ? 'The latest website sync failed'.($latest->error ? ': '.$latest->error : '.')
                    : 'No successful website sync in the last '.$hours.' hours'.($lastSuccess?->finished_at ? ' (last success '.$lastSuccess->finished_at->toDateTimeString().').' : '.'),
                'context' => [
                    'title_ar' => $failedNow ? 'فشلت مزامنة القياس' : 'بيانات القياس متأخرة',
                    'message_ar' => $failedNow
                        ? 'فشلت آخر مزامنة للموقع'.($latest->error ? ': '.$latest->error : '.')
                        : 'لا مزامنة ناجحة للموقع خلال آخر '.$hours.' ساعة'.($lastSuccess?->finished_at ? ' (آخر نجاح '.$lastSuccess->finished_at->toDateTimeString().').' : '.'),
                    'provider' => 'ga4',
                    'sync_run_id' => (string) $latest->id,
                    'error' => $latest->error,
                    'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
                    'threshold_hours' => $hours,
                ],
            ];
        }

        return $out;
    }

    private function tokenExpiries(AlertRule $rule, Carbon $now): array
    {
        $days = (int) ($rule->threshold['days'] ?? 7);
        $cutoff = $now->copy()->addDays($days);

        /*
         * META-SESSION-190-001 — a connection whose token the provider already refused (`error`) needs the same act as one about to
         * expire, sooner. It is named first and only once: an `error` row is not also reported as expiring.
         */
        $refused = ProviderConnection::query()
            ->where('status', 'error')
            ->get()
            ->map(fn (ProviderConnection $c) => [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $c->id,
                'project_id' => null,
                'title' => 'Connection needs reconnecting',
                'message' => 'The '.$c->provider.' connection was refused by the provider and has stopped syncing. Reconnect it to resume.',
                'context' => ['provider' => $c->provider, 'error' => $c->last_error],
            ]);

        $expiring = ProviderConnection::query()
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $cutoff)
            ->where('status', '!=', 'error')
            ->get()
            ->map(fn (ProviderConnection $c) => [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $c->id,
                'project_id' => null,
                'title' => 'Connection token expiring',
                'message' => 'The '.$c->provider.' connection token expires '.optional($c->token_expires_at)->toDateString().'. Reconnect to avoid a sync gap.',
                'context' => ['provider' => $c->provider, 'expires_at' => optional($c->token_expires_at)->toIso8601String()],
            ]);

        return $refused->concat($expiring)->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function budgetRisks(AlertRule $rule, Carbon $now): array
    {
        $ratio = (float) ($rule->threshold['ratio'] ?? 0.9); // spend / budget threshold
        $from = $now->copy()->subDays(30);
        $out = [];

        $campaigns = UnifiedCampaign::query()->where('total_budget', '>', 0)
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get();

        /*
         * Falls THROUGH rather than returning: the workspace's own spend limits are merged in at the
         * bottom of this method, and a workspace with no budgeted campaigns still has them. An early
         * return here silenced every internal limit alert for exactly that workspace.
         */
        if ($campaigns->isNotEmpty()) {

            /*
             * ALERTS-TRUTH-001 — the alert reads the money contract's verdict instead of dividing.
             *
             * This was `(float) $totals['spend'] / $c->total_budget`, and two failures sat in that one
             * line, both already answered everywhere else in the product:
             *
             *   1. A spend the contract WITHHELD is null, and `(float) null` is 0.0 — so a campaign whose
             *      platform reported in a currency with no dated rate could burn its whole budget in
             *      silence. A missed warning reports nothing, which is exactly why it survived: nobody
             *      sees an alert that was never raised.
             *
             *   2. The spend is in the PROJECT's currency and `total_budget` is in the CAMPAIGN's.
             *      Dividing them divides riyals by dollars: 3,750 SAR against a 1,000 USD budget is
             *      roughly on plan and reads as 375%.
             *
             * `budgetPacing()` states `consumed_pct` and REFUSES it, under a named `pacing_basis`, in
             * precisely these cases — and it is one query for the whole scope rather than one per
             * campaign. An unmeasurable budget raises its own alert rather than being silently skipped:
             * the operator's budget protection does not cover that campaign, and that is the fact they
             * need, not a percentage nobody can stand behind.
             */
            $projectIds = $campaigns->pluck('project_id')->unique()->map(fn (mixed $id): string => (string) $id)->values()->all();

            $pacing = collect($this->metrics->forProjects($projectIds)->budgetPacing($from, $now, $now->copy()))
                ->keyBy(fn (array $row): string => (string) $row['campaign_id']);

            foreach ($campaigns as $c) {
                $row = $pacing->get((string) $c->id);
                if ($row === null) {
                    continue;
                }

                $consumed = $row['consumed_pct'] ?? null;
                $basis = (string) ($row['pacing_basis'] ?? '');

                if ($basis === 'no_budget') {
                    continue;
                }

                if ($consumed === null) {
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => 'Budget cannot be monitored',
                        'message' => $c->name.' has a budget that cannot be compared to its spend ('.$basis.'), so it is not being watched.',
                        'context' => [
                            'basis_class' => 'unmeasurable',
                            'pacing_basis' => $basis,
                            'budget' => (float) $c->total_budget,
                            'budget_currency' => $c->budget_currency,
                            'message' => $c->name.' has a budget that cannot be compared to its spend ('.$basis.'), so it is not being watched.',
                        ],
                    ];

                    continue;
                }

                if ((float) $consumed >= $ratio) {
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => 'Budget at risk',
                        'message' => $c->name.' has spent '.round((float) $consumed * 100).'% of its budget.',
                        'context' => [
                            'basis_class' => 'measured',
                            'spend' => $row['spent'],
                            'budget' => $row['budget'],
                            'ratio' => round((float) $consumed, 4),
                        ],
                    ];
                }
            }

        }

        return array_merge($out, $this->internalSpendLimits($rule, $now));
    }

    /**
     * BUDGET-GOVERNANCE-001 — the workspace's OWN limits, evaluated by the same rule that watches the
     * platforms'.
     *
     * One detector, deliberately. A second engine watching a second kind of budget would eventually
     * disagree with this one about the same campaign's spend, and the customer would meet both.
     *
     * The message says «internal limit» in as many words, because the two objects behave completely
     * differently: a platform budget stops delivery when it is exhausted; nothing stops an internal
     * one. An operator who reads a generic «budget at risk» and assumes the first will not go and
     * pause anything.
     *
     * The crossing is also written to `spend_limit_events`, whose unique (limit, threshold) index is
     * the audit trail AND the dedup: 80% is recorded once, with the figures as they stood, rather
     * than recomputed on every sweep for the rest of the period.
     *
     * @return list<array<string,mixed>>
     */
    private function internalSpendLimits(AlertRule $rule, Carbon $now): array
    {
        $out = [];

        SpendLimit::query()
            ->where('active', true)
            ->whereDate('starts_on', '<=', $now->toDateString())
            ->whereDate('ends_on', '>=', $now->toDateString())
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            ->each(function (SpendLimit $limit) use ($now, &$out): void {
                $reading = $this->limits->read($limit, $now->copy()->startOfDay());

                // No comparable figure is not a breach. It is the state where nothing can be said.
                if ($reading['utilisation'] === null) {
                    return;
                }

                $crossed = $this->highestCrossedThreshold($limit, (float) $reading['utilisation']);

                if ($crossed === null) {
                    return;
                }

                $recorded = SpendLimitEvent::query()->firstOrCreate(
                    ['spend_limit_id' => $limit->getKey(), 'threshold' => $crossed],
                    [
                        'tenant_id' => $limit->tenant_id,
                        'consumed' => $reading['consumed'],
                        'limit_amount' => $reading['amount'],
                        'currency' => $limit->currency,
                        'crossed_at' => $now,
                    ],
                );

                // Already announced. The ledger is the dedup; re-raising would be a second alert for
                // one event, which is the shape MAIL-006's cooldown exists to prevent.
                if (! $recorded->wasRecentlyCreated) {
                    return;
                }

                $out[] = [
                    'entity_type' => SpendLimit::class,
                    'entity_id' => (string) $limit->getKey(),
                    'project_id' => (string) $limit->project_id,
                    'title' => 'Internal spend limit at '.$crossed.'%',
                    'message' => 'This workspace’s own '.$limit->scope->value.' limit of '
                        .round((float) $reading['amount'], 2).' '.$limit->currency.' is '.$crossed
                        .'% used. CampaignsHub does not stop delivery — pause on the platform if that is the intent.',
                    'context' => [
                        'enforcement' => SpendLimit::ENFORCEMENT,
                        'scope' => $limit->scope->value,
                        'threshold' => $crossed,
                        'consumed' => $reading['consumed'],
                        'limit' => $reading['amount'],
                        'currency' => $limit->currency,
                        'projected_exhaustion' => $reading['projected_exhaustion'],
                    ],
                ];
            });

        return $out;
    }

    /** The highest configured threshold this utilisation has passed, or null for none. */
    private function highestCrossedThreshold(SpendLimit $limit, float $utilisation): ?int
    {
        $crossed = array_filter(
            $limit->thresholdPercents(),
            static fn (int $t): bool => $utilisation * 100 >= $t,
        );

        return $crossed === [] ? null : max($crossed);
    }

    /**
     * «صرف بدون نتائج» — a campaign that ran yesterday and whose spend since it last started running brought none of its result.
     *
     * ALERT-DELIVERING-YESTERDAY-001 — «كرم النخيل» kept being told this about campaigns that were stopped: the window was
     * «the last N days» on the server's clock, so a campaign paused three days ago still «spent with no results» on what it
     * spent before the pause, and an adopted campaign's stored `active` outlived its pause on the platform. Who qualifies is
     * now {@see CampaignDelivery}: running by the platform's latest status, and delivering (spend or impressions) YESTERDAY on
     * the client's clock. The judgement is the spend from the day it last (re)started delivering through yesterday, capped to
     * the rule's `days`, with 0 of its objective's result. An open alert on a campaign that no longer qualifies closes quietly.
     *
     * @return list<array<string,mixed>>
     */
    private function noResults(AlertRule $rule, Carbon $now): array
    {
        $days = max(1, (int) ($rule->threshold['days'] ?? 3));
        $out = [];

        $campaigns = UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get();
        $verdicts = $this->delivery()->assess($campaigns, $now, $days, null, self::sample($rule));
        $state = [];

        /*
         * One figures read per judgement window: campaigns that started delivering on the same day share it, so a sweep reads
         * at most `days` windows, never one per campaign.
         */
        $windows = [];
        $byId = $campaigns->keyBy(static fn (UnifiedCampaign $c): string => (string) $c->getKey());
        foreach ($verdicts as $id => $v) {
            $notice = $this->notice($byId[$id], $v, $now);
            if ($notice !== null) {
                $out[] = $notice;
            }
            if ($v['reason'] !== null) {
                // OBJECTIVE-RESULT-001 + ALERT-DELIVERING-YESTERDAY-001 — ended, stopped, or not running yesterday: not a performance problem.
                $state[$id] = ['reason' => $v['reason'], 'result_key' => null];

                continue;
            }
            $windows[$v['from'].'|'.$v['to']][] = $id;
        }
        // One currency read for the campaigns judged: when their spend agrees on one currency, every card states it.
        $judgedIds = array_merge([], ...array_values($windows));
        $currency = null;
        if ($judgedIds !== []) {
            $basis = $this->metrics->acrossProjects()->forCampaigns($judgedIds)->currencyBasis($now->copy()->subDays($days + 1), $now);
            $currency = $basis['bases'] === 1 ? (string) $basis['currency'] : null;
        }
        $figures = [];
        foreach ($windows as $key => $ids) {
            [$from, $to] = explode('|', $key);
            $figures += $this->metrics->acrossProjects()->forCampaigns($ids)->figuresByCampaign(Carbon::parse($from), Carbon::parse($to));
        }

        $campaigns->each(function (UnifiedCampaign $c) use ($figures, $verdicts, $currency, &$out, &$state) {
            $id = (string) $c->getKey();
            if (! isset($figures[$id])) {
                return;
            }
            $v = $verdicts[$id];
            $totals = $figures[$id];
            $spend = (float) $totals['spend'];

            /*
             * The campaign's OWN result, from the one table. It read `conversions`, so a traffic
             * campaign with thousands of landing page views was told it had none. An objective nobody
             * set has no result to be zero, and a result nobody reported is not a zero: neither alerts.
             */
            $result = $totals['result'] ?? null;
            if (($totals['result_key'] ?? null) === null) {
                $state[$id] = ['reason' => 'objective_unknown', 'result_key' => null];

                return;
            }
            if ($result === null) {
                $state[$id] = ['reason' => 'result_not_reported', 'result_key' => (string) $totals['result_key']];

                return;
            }
            $state[$id] = ['reason' => null, 'result_key' => (string) $totals['result_key']];

            if ($spend > 0 && (float) $result <= 0) {
                $label = (array) ($totals['result_label'] ?? []);
                $out[] = [
                    'entity_type' => UnifiedCampaign::class,
                    'entity_id' => (string) $c->id,
                    'project_id' => (string) $c->project_id,
                    'title' => 'Spending with no results',
                    'message' => $c->name.' spent '.round($spend, 2).' with no '.strtolower((string) ($label['en'] ?? 'results')).' from '.$v['from'].' to '.$v['to'].' ('.$v['days'].' days).',
                    // `conversions` keeps its name for the readers of this context; it now holds the objective's result.
                    // `days` is the days JUDGED (since it last started delivering), not the rule's ceiling.
                    'context' => ['spend' => $spend, 'conversions' => (float) $result, 'days' => $v['days'], 'from' => $v['from'], 'to' => $v['to'],
                        'active_since' => $v['active_since'], 'campaign_status' => $v['status'], 'checks' => $v['checks'],
                        'card' => $this->card($c, $v, ['spend' => $spend, 'result' => (float) $result, 'result_key' => (string) $totals['result_key'], 'from' => $v['from'], 'to' => $v['to']], $currency),
                        'result_key' => $totals['result_key'], 'result_family' => $totals['result_family'] ?? null, 'result_label' => $totals['result_label'] ?? null],
                ];
            }
        });
        $this->noResultsState[(string) $rule->id] = $state;

        return $out;
    }

    /** A campaign that delivered within this many days and now spends nothing is «ما تصرف»; an older silence is not news. */
    private const NOT_SPENDING_RECENT_DAYS = 7;

    /**
     * ALERT-CRITERIA-001 — the two campaign notices the Owner's rules allow beside the performance verdicts, raised by the
     * «صرف بدون نتائج» sweep that already holds the proof (OWNER_NOTIFICATIONS_RULES §6, §7أ case 2–3, §10أ check 4):
     *
     * - `campaign_stopped` «توقفت أمس» — its platform says it is not running and it last delivered the day before yesterday. Once:
     *   the next day the condition no longer holds and the notice closes silently.
     * - `not_spending` «ما تصرف» — running per its platform, yesterday's figures arrived (a successful sync covered the day), it
     *   spent nothing, and it either delivered in the last {@see NOT_SPENDING_RECENT_DAYS} days or its ads are all rejected / in
     *   review. The stored setup facts travel with it; what we do not fetch says «غير متاح».
     *
     * Both are said once per episode (`once`: an open notice is never refreshed or re-sent) and close silently, never «تعافى».
     *
     * @param  array<string, mixed>  $v  the campaign's {@see CampaignDelivery} verdict
     * @return array<string, mixed>|null
     */
    private function notice(UnifiedCampaign $c, array $v, Carbon $now): ?array
    {
        $checks = (array) $v['checks'];
        $last = $checks['last_delivered'] ?? null;
        $type = null;
        if ($v['reason'] === CampaignDelivery::INACTIVE && ($checks['stopped_yesterday'] ?? false) === true) {
            $type = 'campaign_stopped';
        } elseif ($v['reason'] === CampaignDelivery::NOT_DELIVERING && ($checks['running']['platform'] ?? []) !== []) {
            // «الربط سليم» needs a linked platform whose sync covered yesterday (CampaignDelivery already withheld it otherwise).
            $ads = $checks['setup']['ads'] ?? null;
            $recent = is_string($last) && $last >= Carbon::parse($v['to'])->subDays(self::NOT_SPENDING_RECENT_DAYS)->toDateString();
            if ($recent || in_array($ads, ['rejected', 'in_review'], true)) {
                $type = 'not_spending';
            }
        }
        if ($type === null) {
            return null;
        }
        $card = $this->card($c, $v, null);

        return [
            'type' => $type,
            'once' => true,
            'severity' => $type === 'campaign_stopped' ? 'info' : 'warning',
            'entity_type' => UnifiedCampaign::class,
            'entity_id' => (string) $c->id,
            'project_id' => (string) $c->project_id,
            'title' => $type === 'campaign_stopped' ? 'Stopped yesterday' : 'Not spending',
            'message' => $c->name.($type === 'campaign_stopped'
                ? ' stopped: its platform says '.$v['status'].' and it last delivered on '.$last.'.'
                : ' is running on its platform and spent nothing on '.$v['to'].'.'),
            'context' => ['last_delivered' => $last, 'to' => $v['to'], 'campaign_status' => $v['status'], 'checks' => $checks, 'card' => $card],
        ];
    }

    /**
     * ALERT-CRITERIA-001 — what every campaign alert states (§10أ): platform, ad account, campaign (and its ad set when it has
     * one), objective, the result's unit, spend with its currency, the period's dates, and the status with since when.
     *
     * @param  array<string, mixed>  $v  the {@see CampaignDelivery} verdict
     * @param  array{spend?: float, result?: ?float, result_key?: ?string, from?: string, to?: string}|null  $judged
     * @return array<string, mixed>
     */
    private function card(UnifiedCampaign $c, array $v, ?array $judged, ?string $currency = null): array
    {
        $facts = $this->delivery()->cardFacts((string) $c->id);
        $from = $judged['from'] ?? $v['from'] ?? $v['to'];
        $to = $judged['to'] ?? $v['to'];
        // The sweep's shared currency when its campaigns agree; otherwise this campaign's own (one read, only for a raised alert).
        if ($currency === null && isset($judged['spend'])) {
            $basis = $this->metrics->acrossProjects()->forCampaigns([(string) $c->id])->currencyBasis(Carbon::parse((string) $from), Carbon::parse((string) $to));
            $currency = $basis['bases'] === 1 ? (string) $basis['currency'] : null;
        }

        return [
            'providers' => $facts['providers'],
            'accounts' => $facts['accounts'],
            'campaign' => (string) $c->name,
            'ad_set' => $facts['ad_set'],
            'objective' => (string) $c->objective,
            'result_key' => $judged['result_key'] ?? null,
            'result' => $judged['result'] ?? null,
            'spend' => $judged['spend'] ?? null,
            'currency' => isset($judged['spend']) ? $currency : null,
            'from' => $from,
            'to' => $to,
            'status' => (string) $v['status'],
            'active_since' => $v['active_since'] ?? null,
            'last_delivered' => $v['checks']['last_delivered'] ?? null,
        ];
    }

    /**
     * ALERT-CRITERIA-001 (§10أ check 6) — which objective family each comparison rule may judge, its default threshold for that
     * family (a rule's own `threshold.pct` wins), and the smallest previous window a comparison may rest on: a «rise» measured
     * against three orders is noise. ROAS and cost per order are sales figures; cost per lead is a leads figure. A traffic or
     * awareness campaign is never told its ROAS fell.
     *
     * @var array<string, array{families: list<string>, pct: float, min_previous_results: int, result: string}>
     */
    public const COMPARISON_RULES = [
        'roas_drop' => ['families' => ['sales'], 'pct' => 25.0, 'min_previous_results' => 5, 'result' => 'conversions'],
        'cpa_increase' => ['families' => ['sales'], 'pct' => 25.0, 'min_previous_results' => 5, 'result' => 'conversions'],
        'cpl_increase' => ['families' => ['leads'], 'pct' => 25.0, 'min_previous_results' => 5, 'result' => 'leads'],
    ];

    /** @param  Collection<int, UnifiedCampaign>  $campaigns  @return Collection<int, UnifiedCampaign> */
    private static function ofFamilies(string $type, Collection $campaigns): Collection
    {
        $families = self::COMPARISON_RULES[$type]['families'] ?? null;

        return $families === null ? $campaigns : $campaigns->filter(
            static fn (UnifiedCampaign $c): bool => in_array(ObjectiveResult::familyOf($c->objective === null ? null : (string) $c->objective)->value, $families, true),
        )->values();
    }

    /** The rule's own percentage, else the family default for its type. */
    private static function pctFor(AlertRule $rule, float $fallback): float
    {
        return is_numeric($rule->threshold['pct'] ?? null) ? (float) $rule->threshold['pct'] : (float) (self::COMPARISON_RULES[$rule->type]['pct'] ?? $fallback);
    }

    /** @return list<array<string,mixed>> */
    private function roasDrops(AlertRule $rule, Carbon $now): array
    {
        $pct = self::pctFor($rule, 25); // % drop vs previous window
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        $curFrom = $now->copy()->subDays($days);
        $prevFrom = $now->copy()->subDays($days * 2);
        $prevTo = $now->copy()->subDays($days + 1);

        // ALERT-DELIVERING-YESTERDAY-001 — a stopped campaign's ROAS is not a decision to make; ALERT-CRITERIA-001 — nor is a non-sales one's.
        $campaigns = $this->eligibleOnly($rule, self::ofFamilies('roas_drop', UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()), $now, $days);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $metrics = $this->campaignMetrics($campaigns);
        $current = $metrics->figuresByCampaign($curFrom, $now);
        $previous = $metrics->figuresByCampaign($prevFrom, $prevTo);

        $campaigns->each(function (UnifiedCampaign $c) use ($current, $previous, $pct, $days, &$out) {
            $cur = $current[(string) $c->getKey()]['roas'];
            $prev = $previous[(string) $c->getKey()]['roas'];
            // ALERT-CRITERIA-001 — a comparable previous window: enough orders to compare against.
            $enough = (float) ($previous[(string) $c->getKey()]['conversions'] ?? 0) >= self::COMPARISON_RULES['roas_drop']['min_previous_results'];
            if ($enough && $cur !== null && $prev !== null && $prev > 0 && ($prev - $cur) / $prev * 100 >= $pct) {
                $out[] = [
                    'entity_type' => UnifiedCampaign::class,
                    'entity_id' => (string) $c->id,
                    'project_id' => (string) $c->project_id,
                    'title' => 'ROAS dropped',
                    // EMAIL-ALERT-DETERIORATION-001 — the metric, the threshold and the window, stated.
                    'message' => $c->name.' ROAS fell from '.round($prev, 2).' to '.round($cur, 2).' — '.$this->deteriorationBasis($days, $pct, 'drop'),
                    'context' => ['roas_current' => round($cur, 4), 'roas_previous' => round($prev, 4), 'threshold_pct' => $pct, 'window_days' => $days],
                ];
            }
        });

        return $out;
    }

    /**
     * The campaigns whose latest reported day departed from their own baseline.
     *
     * ## One detector, called — not a second one written
     *
     * {@see ChangeTimeline} has found these days since it shipped: each day against the MEDIAN and
     * the median absolute deviation of the days BEFORE it, so one spike cannot hide the next and no
     * finding rests on hindsight the reader could not have acted on. Its only caller was
     * `MetricsController` — a page somebody had to open. Re-deriving the arithmetic here would give
     * the product two answers to «was Tuesday unusual», differing by whatever the two copies
     * drifted, and the reader who noticed would have no way to tell which was the real one. So this
     * calls it, on the same aggregator, and formats what it returns.
     *
     * ## Recency is the difference between a chart and an alert
     *
     * `ChangeTimeline` answers «which days in this window were unusual», which is exactly right for
     * a chart and wrong for a sweep that runs every fifteen minutes: over a three-week window, a
     * spike on the 2nd is still an answer on the 21st. An alert is a claim that somebody should look
     * NOW, so only a day inside {@see self::ANOMALY_RECENCY_DAYS} qualifies, and a campaign whose
     * only strange day is a fortnight old stays quiet.
     *
     * ## Active campaigns only
     *
     * Two reasons, and the second is the one that matters. A paused campaign costs a timeseries
     * query for a verdict nobody can act on; and when a provider keeps reporting zeros after the
     * pause, every campaign anybody stops becomes a «spend collapsed» alert. Pausing a campaign is
     * a decision, not an anomaly, and a sweep that pages you for your own action is the noise this
     * type would otherwise be dismissed for.
     *
     * One timeseries statement for all the active campaigns, as {@see roasDrops()} and
     * {@see costPerIncreases()} read their figures.
     *
     * @return list<array<string, mixed>>
     */
    private function metricAnomalies(AlertRule $rule, Carbon $now): array
    {
        $days = max(self::ANOMALY_MIN_WINDOW_DAYS, (int) ($rule->threshold['days'] ?? 21));
        $from = $now->copy()->subDays($days);
        $earliest = $now->copy()->subDays(self::ANOMALY_RECENCY_DAYS)->toDateString();

        $out = [];

        /*
         * ALERT-DELIVERING-YESTERDAY-001 — running by the platform's latest status too, not only by our stored word: an adopted
         * campaign paused on its platform kept `active` here. Delivery yesterday is NOT required: a running campaign whose
         * spend collapsed to nothing is exactly what this rule exists to notice.
         */
        $campaigns = $this->eligibleOnly($rule, UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get(), $now, 1, statusOnly: true);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $metrics = $this->campaignMetrics($campaigns);
        $series = $metrics->timeseriesByCampaign($from, $now);
        $timeline = new ChangeTimeline($metrics);

        $campaigns
            ->each(function (UnifiedCampaign $c) use ($series, $timeline, $from, $now, $earliest, &$out) {
                $points = array_values(array_filter(
                    $timeline->fromSeries($series[(string) $c->getKey()] ?? [])['points'],
                    static fn (array $p): bool => $p['date'] >= $earliest,
                ));

                if ($points === []) {
                    return;
                }

                $out[] = [
                    'entity_type' => UnifiedCampaign::class,
                    'entity_id' => (string) $c->id,
                    'project_id' => (string) $c->project_id,
                    'title' => 'Unusual day',
                    'message' => $this->anomalySentence($c->name, $points),
                    /*
                     * The evidence, not a verdict. «Unusual» is a claim, and a reader who cannot see
                     * the figure it was measured against has been told to worry without being told
                     * why — so the value, the baseline and how far apart they are all travel with
                     * the alert, which is what the alerts surface renders as context chips.
                     */
                    'context' => ['points' => $points, 'window_days' => $from->diffInDays($now)],
                ];
            });

        return $out;
    }

    /**
     * «Ramadan push: spend rose sharply against its own baseline on 2026-08-19 (1,200 vs 100).»
     *
     * Several metrics moving on one day is ONE incident and reads as one sentence. The engine's
     * dedup key is (rule, entity) — deliberately, because «this campaign is in trouble» is one fact
     * however many ways you notice it — so raising per metric would need a second dedup vocabulary
     * or would page somebody three times for one bad Tuesday.
     *
     * @param  list<array<string, mixed>>  $points
     */
    private function anomalySentence(string $campaign, array $points): string
    {
        $parts = array_map(static function (array $p): string {
            $direction = $p['direction'] === 'up' ? 'rose sharply' : 'fell sharply';

            return sprintf(
                '%s %s on %s (%s vs a baseline of %s)',
                $p['metric'],
                $direction,
                $p['date'],
                number_format((float) $p['value'], 2),
                number_format((float) $p['baseline'], 2),
            );
        }, $points);

        return $campaign.': '.implode('; ', $parts).'.';
    }

    /**
     * The campaigns' figures, from the SAME engine every screen reads (UNIFIED-001).
     *
     * These handlers used to sum `daily_metrics` themselves and divide revenue by spend inline. The
     * arithmetic agreed with {@see MetricsAggregator} on the day it was written and nothing held it
     * there — so an alert firing on a ROAS the dashboard never showed was one edit away, and the reader
     * who spotted the discrepancy would have had no way to tell which number was the real one. There is
     * one definition of spend, of conversions and of ROAS now, and alerts read it.
     *
     * Scoped by campaign only, deliberately: this runs from the scheduler with no request behind it and
     * therefore no active project, and a campaign id already names exactly one project. The tenant scope
     * is live throughout — {@see evaluateAll()} sets it per tenant.
     *
     * PERF-RECOVERY-001 — one statement for a rule's campaigns and window, where one `totals()` per
     * campaign per window cost about seven and was most of a sweep's minutes. The reads differ in nothing
     * an alert reads; see {@see MetricsAggregator::figuresByCampaign()}. No campaign is skipped for having
     * «nothing new»: a window moves with the clock and a cooldown lapses without a new row, so a campaign
     * with no new row can still breach, recover or re-notify.
     *
     * @param  Collection<int, UnifiedCampaign>  $campaigns
     */
    private function campaignMetrics(Collection $campaigns): MetricsAggregator
    {
        return $this->metrics->acrossProjects()->forCampaigns($campaigns->map(static fn (UnifiedCampaign $c): string => (string) $c->id)->all());
    }

    /**
     * Leads nobody owns, past the project's own SLA — LEAD-SLA-NOTIFICATION-001.
     *
     * The SLA is `threshold.minutes` on the rule, and the rule already carries `project_id`, so a
     * client who answers within the hour and a client who answers within the day are two rules
     * rather than a global constant somebody has to compromise on. Sixty minutes is the default
     * because an unowned lead is the one failure where every minute is the client's money.
     *
     * **One alert per breach, not per lead.** Twenty unassigned leads is one situation and one
     * person to tell; twenty alerts is an inbox nobody reads and a cooldown that cannot help,
     * because each lead dedups on its own key. The count is in the message and the names are not —
     * an alert can reach a channel that is not the product.
     *
     * @return list<array<string,mixed>>
     */
    private function leadsUnassigned(AlertRule $rule, Carbon $now): array
    {
        $minutes = max(1, (int) ($rule->threshold['minutes'] ?? 60));
        $cutoff = $now->copy()->subMinutes($minutes);

        return $this->leadBreach(
            $rule,
            fn (Builder $q): Builder => $q
                ->whereNull('owner_id')
                ->whereNotNull('received_at')
                ->where('received_at', '<', $cutoff),
            'Leads with no owner',
            fn (int $n): string => $n.' lead(s) have had no owner for more than '.self::minutes($minutes).'.',
            ['minutes' => $minutes, 'rule' => 'unassigned'],
        );
    }

    /**
     * Owned, and nobody has tried yet.
     *
     * `first_attempt_at` and not `first_contact_at`: a call that rang out is work done, and holding
     * a team to «somebody answered» would page them for the buyer's behaviour rather than their own.
     * The pair exists precisely so a team cannot look responsive while nobody has dialled, and this
     * alert is the half that watches the dialling.
     *
     * @return list<array<string,mixed>>
     */
    private function leadsNotContacted(AlertRule $rule, Carbon $now): array
    {
        $minutes = max(1, (int) ($rule->threshold['minutes'] ?? 240));
        $cutoff = $now->copy()->subMinutes($minutes);

        return $this->leadBreach(
            $rule,
            fn (Builder $q): Builder => $q
                ->whereNotNull('owner_id')
                ->whereNull('first_attempt_at')
                ->whereNotNull('received_at')
                ->where('received_at', '<', $cutoff),
            'Leads nobody has tried',
            fn (int $n): string => $n.' assigned lead(s) have had no contact attempt in '.self::minutes($minutes).'.',
            ['minutes' => $minutes, 'rule' => 'no_contact'],
        );
    }

    /**
     * A follow-up somebody promised, and the time has passed.
     *
     * No window and no SLA: `next_follow_up_at` is a date a person chose, and it is late or it is
     * not. A promise made three weeks ago is overdue today, which is why this asks the whole open
     * pipeline rather than a recent slice — the same rule the follow-up workspace follows, for the
     * same reason.
     *
     * @return list<array<string,mixed>>
     */
    private function leadFollowUpsOverdue(AlertRule $rule, Carbon $now): array
    {
        return $this->leadBreach(
            $rule,
            fn (Builder $q): Builder => $q
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $now),
            'Follow-ups past their date',
            fn (int $n): string => $n.' follow-up(s) are past the date somebody promised.',
            ['rule' => 'follow_up_overdue'],
        );
    }

    /** «1 minute», «60 minutes», «1 hour» — an alert that says «1 minutes» reads as a machine wrote it. */
    private static function minutes(int $minutes): string
    {
        if ($minutes === 1) {
            return '1 minute';
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $minutes.' minutes';
    }

    /**
     * The shared shape of a lead SLA breach: count the open pipeline, per project, and say how many.
     *
     * Closed leads are excluded everywhere — won, lost and invalid are decisions already made, and
     * an alert about them is an overdue list nobody can clear.
     *
     * The entity is the PROJECT, so the engine's dedup key is stable across evaluations: the same
     * situation refreshes one alert rather than opening a new one each sweep as leads come and go.
     *
     * @param  callable(Builder): Builder  $narrow
     * @param  callable(int): string  $message
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function leadBreach(AlertRule $rule, callable $narrow, string $title, callable $message, array $context): array
    {
        $rows = $narrow(
            Lead::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $rule->tenant_id)
                ->when($rule->project_id, fn (Builder $q): Builder => $q->where('project_id', $rule->project_id))
                ->whereNotIn('status', [LeadStage::Won->value, LeadStage::Lost->value, LeadStage::Invalid->value])
                ->tap(fn (Builder $q): Builder => LeadVisibility::boundToItsProject($q))
        )
            /*
             * Grouped by project even when the rule already names one, so a tenant-wide rule tells
             * the reader WHICH client is behind rather than handing them one number across six.
             */
            ->selectRaw('project_id, count(*) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $out = [];

        foreach ($rows as $projectId => $total) {
            $n = (int) $total;

            if ($n === 0) {
                continue;
            }

            $out[] = [
                'entity_type' => Project::class,
                'entity_id' => $projectId === null ? null : (string) $projectId,
                'project_id' => $projectId === null ? null : (string) $projectId,
                'title' => $title,
                'message' => $message($n),
                'context' => $context + ['count' => $n],
            ];
        }

        return $out;
    }

    /**
     * TRACKING-HEALTH-001 — the findings of the Tracking Health page, as alerts.
     *
     * Only what that page calls `critical` or `warning` is raised: `info`, `unavailable` and `ok` are statements about
     * what could or could not be checked, and an alert about «we could not look» is noise that trains people to ignore
     * alerts. Each check is its own entity (the check's key, which carries the form or destination id), so the engine's
     * dedup, cooldown, snooze and recovery work per finding: a form that was broken and is fixed RESOLVES its alert and
     * says so, instead of leaving a stale one for a person to close.
     *
     * The alert carries the check's own severity (a broken form is critical even on a rule saved as «warning») and both
     * languages of its title, detail and the action it asks for. A project with no published form has nothing to say.
     *
     * @return list<array<string,mixed>>
     */
    private function trackingHealth(AlertRule $rule, Carbon $now): array
    {
        $projects = LeadForm::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->where('status', LeadForm::PUBLISHED)
            ->when($rule->project_id, fn (Builder $q): Builder => $q->where('project_id', $rule->project_id))
            ->distinct()->pluck('project_id');

        $out = [];
        foreach ($projects as $projectId) {
            $health = app(TrackingHealth::class)->forProject((string) $rule->tenant_id, (string) $projectId, $now->copy()->subDays(6), $now);

            foreach ($health['checks'] as $check) {
                if (! in_array($check['severity'], ['critical', 'warning'], true)) {
                    continue;
                }

                $action = $check['action'];
                $out[] = [
                    'entity_type' => 'tracking_check',
                    'entity_id' => (string) $projectId.':'.$check['key'],
                    'project_id' => (string) $projectId,
                    'severity' => $check['severity'],
                    'title' => $check['title']['en'],
                    'message' => trim(($check['detail']['en'] ?? '').' '.($action === null ? '' : $action['en'])),
                    'title_ar' => $check['title']['ar'],
                    'message_ar' => trim(($check['detail']['ar'] ?? '').' '.($action === null ? '' : $action['ar'])),
                    'context' => ['check' => $check['key'], 'numbers' => $check['numbers'], 'title_ar' => $check['title']['ar'], 'message_ar' => trim(($check['detail']['ar'] ?? '').' '.($action === null ? '' : $action['ar']))],
                ];
            }
        }

        return $out;
    }

    /**
     * LAUNCH-ADVANCE-001 — launched plans whose accounts have since earned a better stage.
     *
     * The stage recorded at launch (`spec.journey.stages`) is compared with what the same accounts' synced results support NOW, by the
     * same readiness and planner the launch page uses — no second definition of «ready». Evidence, not elapsed days: an account that has
     * not moved is silent however old the plan is. This only TELLS a person; it changes no optimisation goal, creates nothing and
     * pauses nothing.
     *
     * @return list<array<string,mixed>>
     */
    private function launchAdvance(AlertRule $rule): array
    {
        $rank = ['measurement_first' => 0, 'bridge' => 1, 'sales' => 2, 'leads' => 2];
        $plans = LaunchPlan::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->whereIn('status', [LaunchPlan::PUBLISHED, LaunchPlan::PARTIAL])
            ->when($rule->project_id, fn (Builder $q): Builder => $q->where('project_id', $rule->project_id))
            ->get();

        $out = [];
        foreach ($plans as $plan) {
            $journey = (array) ($plan->spec['journey'] ?? []);
            $recorded = (array) ($journey['stages'] ?? []);
            $goal = (string) ($journey['goal'] ?? '');
            if ($recorded === [] || ! in_array($goal, JourneyPlanner::GOALS, true)) {
                continue;
            }
            $accounts = ExternalAccount::withoutGlobalScopes()
                ->where('tenant_id', $plan->tenant_id)
                ->whereIn('id', array_keys($recorded))
                ->whereIn('id', BoundAccountVisibility::activeAccountIds((string) $plan->project_id))
                ->get(['id', 'provider', 'name']);
            $readiness = app(LaunchReadiness::class)->forAccounts((string) $plan->tenant_id, (string) $plan->project_id, $accounts->map(fn ($a): array => ['id' => (string) $a->id, 'provider' => (string) $a->provider])->all());

            foreach ($accounts as $account) {
                $supported = app(WriteAdapterRegistry::class)->for((string) $account->provider)?->objectives() ?? [];
                $now = app(JourneyPlanner::class)->plan($goal, (string) $account->provider, $readiness['accounts'][(string) $account->id] ?? [], $supported, $readiness['project']);
                $before = (string) ($recorded[(string) $account->id] ?? '');
                if (($rank[$now['stage']] ?? 0) <= ($rank[$before] ?? 99)) {
                    continue;
                }
                $name = (string) ($account->name ?: $account->provider);
                $event = $now['optimization_event'];
                $out[] = [
                    'entity_type' => 'launch_plan_account',
                    'entity_id' => (string) $plan->getKey().':'.$account->id.':'.$now['stage'],
                    'project_id' => (string) $plan->project_id,
                    'severity' => 'info',
                    'title' => "{$name} can move to a better launch stage",
                    'message' => "When «{$plan->name}» went out, {$name} was at «{$before}»; its synced results now support «{$now['stage']}»".($event === null ? '' : " (optimise for {$event})").'. Nothing was changed — open the launch page to decide.',
                    'title_ar' => "حساب {$name} صار مؤهلًا لمرحلة إطلاق أفضل",
                    'message_ar' => "عند نشر «{$plan->name}» كان الحساب في مرحلة «{$before}»، وتدعم نتائجه المزامنة الآن مرحلة «{$now['stage']}»".($event === null ? '' : " (التحسين لحدث {$event})").'. لم يتغيّر شيء — افتح صفحة الإطلاق لتقرّر.',
                    'context' => ['plan_id' => (string) $plan->getKey(), 'account_id' => (string) $account->id, 'from' => $before, 'to' => $now['stage'], 'title_ar' => "حساب {$name} صار مؤهلًا لمرحلة إطلاق أفضل", 'message_ar' => "عند نشر «{$plan->name}» كان الحساب في مرحلة «{$before}»، وتدعم نتائجه المزامنة الآن مرحلة «{$now['stage']}»".($event === null ? '' : " (التحسين لحدث {$event})").'. لم يتغيّر شيء — افتح صفحة الإطلاق لتقرّر.'],
                ];
            }
        }

        return $out;
    }

    /**
     * A rule nobody evaluates, said out loud.
     *
     * `default => []` returned «no breaches», which is indistinguishable from «this rule is fine» —
     * the same shape as every other defect in this product's history: absence of evidence rendered
     * as evidence of absence. An unhandled type is a bug in this class, and now it says so where the
     * operator's logs will show it, while still returning no breaches so the sweep continues.
     */
    private function unevaluated(AlertRule $rule): array
    {
        Log::warning('Alert rule type has no evaluator; the rule can never fire.', [
            'alert_rule_id' => (string) $rule->id,
            'type' => $rule->type,
            'periodic_types' => self::PERIODIC,
        ]);

        return [];
    }

    /**
     * A cost per result that got worse — CPA or CPL, period over period.
     *
     * ## Why this cannot simply read `totals()['cpa']`
     *
     * `spend` is `COALESCE(SUM(value), 0)`, so a window whose money the provider withheld sums to
     * ZERO, and `cpa` is then `0 / conversions` = `0.00` — a real-looking figure for a cost nobody
     * knows. Comparing a withheld window against a converted one produces «CPA rose from 0.00 to
     * 50.00», which is not a cost increase; it is a rate arriving. The operator would be paged for
     * an FX gap.
     *
     * So both windows must be fully converted — `spend_withheld_rows === 0` on each — AND completely
     * covered, since a failed or stale provider leaves the same hole an unconverted one does. Either
     * way there is no verdict. This is the same rule the cards and the charts follow: a partial figure is not a
     * smaller figure, and nothing may be derived from it.
     *
     * A zero previous cost is also refused: every increase from zero is infinite, and «up ∞%» is not
     * a threshold anyone set.
     *
     * @param  'cpa'|'cpl'  $key
     */
    private function costPerIncreases(AlertRule $rule, Carbon $now, string $key, string $resultKey): array
    {
        $pct = self::pctFor($rule, 25);
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        $curFrom = $now->copy()->subDays($days);
        $prevFrom = $now->copy()->subDays($days * 2);
        $prevTo = $now->copy()->subDays($days + 1);
        $label = strtoupper($key);

        // ALERT-DELIVERING-YESTERDAY-001 — only a campaign running and delivering yesterday has a cost worth a decision.
        $campaigns = $this->eligibleOnly($rule, self::ofFamilies((string) $rule->type, UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()), $now, $days);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $metrics = $this->campaignMetrics($campaigns);

        // Either window holding withheld money has no comparable cost — no verdict, no alert.
        /*
         * AGGREGATION-TRUTH-001 widened this guard, and the reason is the paging.
         *
         * It refused a verdict when money was withheld for want of an exchange rate, which was
         * right and too narrow: a provider whose sync FAILED produces exactly the same shape —
         * a total missing a contributor that should be in it — and the comparison then reads
         * «CPA rose from 12.00 to 50.00» when what actually happened is that a platform stopped
         * reporting. The operator gets paged for a broken connector wearing a cost increase's
         * name, which is the most expensive kind of false alarm: it is actionable, and every
         * action it suggests is wrong.
         *
         * Coverage answers the general question — is this total the whole answer — so both
         * windows must now be complete, not merely fully converted.
         */
        /*
         * PERF-RECOVERY-001 — the coverage block of an across-projects read is the tenant's, the same for every campaign, so it is read
         * once per window and an incomplete one withdraws every verdict of this rule at once, as it always did one campaign at a time.
         */
        $curComplete = ($metrics->coverageOfWindow($curFrom, $now)['state'] ?? 'complete') === 'complete';
        $prevComplete = ($metrics->coverageOfWindow($prevFrom, $prevTo)['state'] ?? 'complete') === 'complete';

        if (! $curComplete || ! $prevComplete) {
            return [];
        }

        $current = $metrics->figuresByCampaign($curFrom, $now);
        $previous = $metrics->figuresByCampaign($prevFrom, $prevTo);

        $campaigns->each(function (UnifiedCampaign $c) use ($current, $previous, $pct, $days, $key, $resultKey, $label, &$out) {
            $cur = $current[(string) $c->getKey()];
            $prev = $previous[(string) $c->getKey()];

            if ((int) ($cur['spend_withheld_rows'] ?? 0) > 0 || (int) ($prev['spend_withheld_rows'] ?? 0) > 0) {
                return;
            }

            $now_ = $cur[$key] ?? null;
            $was = $prev[$key] ?? null;

            if ($now_ === null || $was === null || $was <= 0) {
                return;
            }
            // ALERT-CRITERIA-001 — a cost compared against too few results in the previous window is noise, not a rise.
            if ((float) ($prev[$resultKey] ?? 0) < (self::COMPARISON_RULES[$key === 'cpl' ? 'cpl_increase' : 'cpa_increase']['min_previous_results'])) {
                return;
            }

            $rise = ($now_ - $was) / $was * 100;

            if ($rise < $pct) {
                return;
            }

            $out[] = [
                'entity_type' => UnifiedCampaign::class,
                'entity_id' => (string) $c->id,
                'project_id' => (string) $c->project_id,
                'title' => $label.' increased',
                'message' => $c->name.' '.$label.' rose from '.round($was, 2).' to '.round($now_, 2)
                    .' ('.round($rise).'% up) on '.(int) ($cur[$resultKey] ?? 0).' '.$resultKey.' — '.$this->deteriorationBasis($days, $pct, 'rise'),
                'context' => [
                    'threshold_pct' => $pct,
                    'window_days' => $days,
                    $key.'_current' => round($now_, 4),
                    $key.'_previous' => round($was, 4),
                    'rise_pct' => round($rise, 2),
                    $resultKey => (int) ($cur[$resultKey] ?? 0),
                ],
            ];
        });

        return $out;
    }
}
