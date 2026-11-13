<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
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
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Integrations\Status\LinkHealth;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Models\SpendLimitEvent;
use App\Domains\Metrics\Services\ChangeTimeline;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingTimezone;
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

    /** The rule types that judge one campaign's performance — closed silently once that campaign stops running. */
    public const CAMPAIGN_PERFORMANCE = ['roas_drop', 'cpa_increase', 'cpl_increase', 'no_results', 'budget_risk', 'metric_anomaly'];

    /** The calendar a performance window is cut in — the Owner's (Asia/Riyadh); `metric_date` rows are calendar days. */
    public const MEASURE_TIMEZONE = 'Asia/Riyadh';

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
        private readonly LinkHealth $linkHealth,
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
        MetricsAggregator::sweep('alerts:'.spl_object_id($this).':'.$now->format('U.u'));

        try {
            return $this->sweepAll($now, $raised);
        } finally {
            MetricsAggregator::sweep(null);
            $this->delivered = [];
            $this->deliveredSweep = null;
        }
    }

    private function sweepAll(Carbon $now, int $raised): int
    {
        // Rules are tenant-scoped models; iterate tenant-by-tenant so every scoped query (metrics, connections,
        // campaigns) resolves inside the correct tenant boundary.
        $tenantIds = DB::table('alert_rules')->where('active', true)->distinct()->pluck('tenant_id');
        $previous = $this->tenants->tenantId();

        foreach ($tenantIds as $tenantId) {
            $this->tenants->setTenantId((string) $tenantId);
            $rules = AlertRule::query()->where('active', true)->get();
            // The tenant's switched-on types, from the read the sweep makes anyway (LINK-HEALTH-YESTERDAY-001 asks it per rule).
            $this->activeRuleTypes[(string) $tenantId] = $rules->pluck('type')->map(static fn ($t): string => (string) $t)->unique()->values()->all();
            $rules
                ->each(function (AlertRule $rule) use ($now, &$raised) {
                    $raised += $this->evaluateRule($rule, $now);
                });
            $this->flushMail((string) $tenantId, $now);
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
        $breaches = match ($rule->type) {
            'sync_failure' => $this->syncFailures($rule, $now),
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

        // LINK-HEALTH-YESTERDAY-001 — a link alert only when yesterday's data did not arrive; every link type, one rule.
        $openLinks = null;
        if (in_array($rule->type, LinkHealth::TYPES, true)) {
            $breaches = $this->onlyWhereYesterdayMissing($rule, $breaches, $now);
            // One read of the tenant's open events of this type serves the adoption below and the recovery after the raises.
            $openLinks = AlertEvent::query()->where('tenant_id', $rule->tenant_id)->where('type', $rule->type)
                ->whereIn('status', ['open', 'snoozed'])->orderByDesc('last_triggered_at')->get();
            $this->adoptLegacyLinkEvents($rule, $breaches, $openLinks);
        }

        $raised = 0;
        foreach ($breaches as $b) {
            if ($this->raise($rule, $b, $now)) {
                $raised++;
            }
        }

        $this->recoverCleared($rule, $breaches, $now, $openLinks);

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
    /** @param  Collection<int, AlertEvent>|null  $openLinks  a link type's open events, read once by {@see evaluateRule()} */
    private function recoverCleared(AlertRule $rule, array $breaches, Carbon $now, ?Collection $openLinks = null): void
    {
        if (! in_array($rule->type, self::PERIODIC, true)) {
            return;
        }

        $still = [];
        foreach ($breaches as $b) {
            $still[$this->dedupKey($rule, $b)] = true;
        }

        // LINK-HEALTH-YESTERDAY-001 — a link event is the tenant's, whichever rule raised it (one per connection).
        $events = $openLinks !== null ? $openLinks->filter(fn (AlertEvent $e): bool => in_array($e->status, ['open', 'snoozed'], true))->values() : AlertEvent::query()
            ->when(in_array($rule->type, LinkHealth::TYPES, true),
                fn ($q) => $q->where('tenant_id', $rule->tenant_id)->where('type', $rule->type),
                fn ($q) => $q->where('rule_id', $rule->id))
            ->whereIn('status', ['open', 'snoozed'])
            ->get();
        // The campaigns of the cleared performance events that are still running AND delivered yesterday: only those are told «recovered».
        $live = [];
        if (in_array($rule->type, self::CAMPAIGN_PERFORMANCE, true)) {
            $ids = $events->filter(fn (AlertEvent $e): bool => $e->entity_type === UnifiedCampaign::class && ! isset($still[$e->dedup_key]))->pluck('entity_id')->unique()->values()->all();
            $running = $ids === [] ? new Collection : UnifiedCampaign::withoutGlobalScopes()->whereIn('id', $ids)->get()->filter(fn (UnifiedCampaign $c): bool => self::running($c, $now, $this->clock()))->values();
            $live = $this->delivering($running, $now)->map(fn (UnifiedCampaign $c): string => (string) $c->id)->all();
        }

        if (in_array($rule->type, LinkHealth::TYPES, true)) {
            $closable = $events->filter(fn (AlertEvent $e): bool => ! isset($still[$e->dedup_key]));
            $this->linkHealth->prime($closable->where('entity_type', ProviderConnection::class)->pluck('entity_id')->map(static fn ($id): string => (string) $id)->values()->all(), $now);
            $pairs = [];
            foreach ($closable->where('entity_type', Project::class) as $e) {
                foreach ((array) (((array) $e->context)['sources'] ?? []) as $source) {
                    $pairs[] = [(string) $e->entity_id, (string) ($source['provider'] ?? '')];
                }
            }
            $this->linkHealth->primeProjects($pairs, $now);
        }

        $events->each(function (AlertEvent $event) use ($still, $rule, $now, $live): void {
            if (isset($still[$event->dedup_key])) {
                return;
            }
            /*
             * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — a performance alert on a campaign that is no longer running (paused,
             * completed, past its end date) is CLOSED without a word: «رجعت الأمور طبيعية» about an ended campaign is a
             * notification about an ended campaign, which the Owner's rules forbid. The context records why.
             */
            // LINKING-ALERT-NO-FALSE-EXPIRY-001 — an «expiring» event on a connection that renews itself was never true: closed without a «recovered» notice.
            if ($rule->type === 'token_expiry' && isset(((array) $event->context)['expires_at']) && ! isset(((array) $event->context)['error'])) {
                $this->resolve($event, $now);
                $event->forceFill(['context' => (array) $event->context + ['closed_reason' => 'renews_itself', 'closed_at' => $now->toIso8601String()]])->save();

                return;
            }
            /*
             * LINK-HEALTH-YESTERDAY-001 — a link event whose connection delivered yesterday (or that was raised by the old
             * rules on a «stale» window or a run a later run made good) is closed without a word: no «تعافى» notice, no mail.
             */
            if (in_array($rule->type, LinkHealth::TYPES, true)) {
                // Closed only on proof: yesterday arrived, or the link itself is gone. Anything else stays open — a rule that merely
                // stopped proposing it (another project's rule, a quiet fortnight) is never a reason to close a real outage.
                $why = $this->linkCloseReason($event, $now);
                if ($why !== null) {
                    $this->resolve($event, $now);
                    $event->forceFill(['context' => (array) $event->context + ['closed_reason' => $why, 'closed_at' => $now->toIso8601String()]])->save();
                }

                return;
            }
            // ALERT-OBJECTIVE-RESULT-001 — a «صرف بدون نتائج» that no longer applies (inactive, objective unknown, result not
            // reported or redefined) did not recover: closed with its reason, no notice.
            $quiet = $this->notApplicable($rule, $event);
            if ($quiet !== null) {
                $this->closeQuietly($event, $quiet, $now);

                return;
            }
            if ($event->entity_type === UnifiedCampaign::class && in_array($rule->type, self::CAMPAIGN_PERFORMANCE, true)
                && ! in_array((string) $event->entity_id, $live, true)) {
                $this->resolve($event, $now);
                $event->forceFill(['context' => (array) $event->context + ['closed_reason' => 'campaign_not_running', 'closed_at' => $now->toIso8601String()]])->save();

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

    private function recover(AlertRule $rule, AlertEvent $event, Carbon $now): void
    {
        $title = (string) ($event->context['title'] ?? $rule->name);
        $titleAr = (string) ($event->context['title_ar'] ?? $title);
        $context = (array) $event->context;
        $context['recovered_at'] = $now->toIso8601String();
        // ALERT-VISUAL-EVERY-CARD-001 — when it opened, so the recovery card's timeline can be drawn.
        $context['opened_at'] ??= ($event->created_at ?? $now)->toIso8601String();

        $this->resolve($event, $now);
        $event->forceFill(['context' => $context])->save();

        $this->notifications->dispatch([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $event->project_id ? (string) $event->project_id : $rule->project_id,
            'type' => self::NOTIFICATION_TYPE[$rule->type] ?? 'performance',
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
            // ALERT-VISUAL-EVERY-CARD-001 — the figures and dates travel with the recovery, so its card draws them.
            'context' => array_intersect_key($context, array_flip(['opened_at', 'recovered_at', 'window_from', 'window_to', 'provider',
                'roas_current', 'roas_previous', 'cpa_current', 'cpa_previous', 'cpl_current', 'cpl_previous', 'ctr_current', 'ctr_previous', 'cpc_current', 'cpc_previous'])),
        ], 'info', $now, recovered: true);
    }

    private function dedupKey(AlertRule $rule, array $b): string
    {
        /*
         * LINKING-ALERT-NO-FALSE-EXPIRY-001 — a connection's health is ONE fact per tenant, whichever rule found it: three token rules
         * (one per project) produced three identical cards. Every other type stays keyed by its rule.
         */
        if (in_array($rule->type, LinkHealth::TYPES, true)) {
            return hash('sha256', implode('|', [$rule->tenant_id, $rule->type, $b['entity_type'], $b['entity_id'] ?? '']));
        }

        return hash('sha256', implode('|', [$rule->id, $b['entity_type'], $b['entity_id'] ?? '']));
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
                // LINK-HEALTH-YESTERDAY-001 — an escalation reaches the stored event (bell, Recommendations), not only the mail.
                'severity' => $b['severity'] ?? $active->severity,
            ] + $this->anatomy($rule, $b))->save();
            if (($b['context']['digest_only'] ?? false) !== true) {
                $this->notify($rule, $b);
                $this->post($rule, $active, $b, $b['severity'] ?? $rule->severity, $now);
            }

            return false;
        }

        // No active event. If the most recent (possibly resolved) event is still inside cooldown, suppress.
        $recent = AlertEvent::query()->where('dedup_key', $dedupKey)->latest('last_triggered_at')->first();
        if ($recent !== null && $cooldownUntil($recent)->isFuture()) {
            return false;
        }

        // LINKING-ALERT-NO-FALSE-EXPIRY-001 — a finding marked `digest_only` is recorded for the morning digest's «ملخص الربط», never sent instantly.
        $digestOnly = ($b['context']['digest_only'] ?? false) === true;

        // Fresh alert.
        $notification = $digestOnly ? null : $this->notify($rule, $b);
        $taskId = $rule->create_task ? $this->openTask($rule, $b) : null;

        $event = AlertEvent::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'rule_id' => $rule->id,
            'type' => $rule->type,
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
        if (! $digestOnly) {
            $this->post($rule, $event, $b, $b['severity'] ?? $rule->severity, $now);
        }

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
        return AlertAnatomy::build((string) $rule->type, (array) ($b['context'] ?? []), (string) ($b['severity'] ?? $rule->severity));
    }

    /** Raise the notification through the shared dispatcher; returns the notification id (or null if deduped). */
    /** Put one event on the sweep's outbox for the mailer. */
    private function post(AlertRule $rule, AlertEvent $event, array $b, string $severity, Carbon $now, bool $recovered = false): void
    {
        $this->outbox[] = [
            'event_id' => (string) $event->id,
            // ALERT-RULES-MANAGE-001 — the mailer reads the rule's channels and recipients from it.
            'rule_id' => (string) $rule->id,
            'notification_type' => self::NOTIFICATION_TYPE[$rule->type] ?? 'performance',
            // EMAIL-DIGESTS-OPERATIONAL-001 — what the finding is ABOUT, so the mail can say it plainly and link to the exact page.
            'rule_type' => (string) $rule->type,
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
            'priority' => $event->priority ?? AlertAnatomy::build((string) $rule->type, (array) ($b['context'] ?? []), $severity)['priority'],
            'insufficient' => ($event->evidence['sufficient'] ?? AlertAnatomy::evidence((string) $rule->type, (array) ($b['context'] ?? []))['sufficient']) !== true,
        ];
    }

    private function notify(AlertRule $rule, array $b): ?string
    {
        $n = $this->notifications->dispatch([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'type' => self::NOTIFICATION_TYPE[$rule->type] ?? 'performance',
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
            'rule_type' => (string) $rule->type, 'severity' => (string) ($b['severity'] ?? $rule->severity), 'context' => (array) ($b['context'] ?? []),
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
            'meta' => ['source' => 'alert', 'alert_type' => $rule->type],
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
        return 'the last '.$days.' complete days against the '.$days.' before; this rule fires at a '.rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').'% '.$direction.'.';
    }

    /**
     * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — the measured window of a performance rule: `$days` COMPLETE days ending YESTERDAY
     * (Riyadh calendar), against the same number of complete days before them. Today is still being written by the platforms,
     * so a window that ends «now» compares a part-day with whole days and reads every sweep differently.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} current from, current to, previous from, previous to
     */
    public static function completeDays(Carbon $now, int $days): array
    {
        $days = max(1, $days);
        $to = $now->copy()->setTimezone(self::MEASURE_TIMEZONE)->subDay()->endOfDay();
        $from = $to->copy()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days)->startOfDay();

        return [$from, $to, $prevFrom, $prevTo];
    }

    /**
     * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — of these campaigns, the ones that DELIVERED yesterday (spend or impressions above
     * zero). A running campaign with no movement has nothing to be alerted about: no spend, no budget at risk.
     *
     * @param  Collection<int, UnifiedCampaign>  $campaigns
     * @return Collection<int, UnifiedCampaign>
     */
    private function delivering(Collection $campaigns, Carbon $now): Collection
    {
        if ($campaigns->isEmpty()) {
            return $campaigns;
        }
        // One read of yesterday per campaign per sweep (one `$now`), however many rules ask: the answer cannot differ between them.
        $sweep = $now->format('U.u');
        if ($this->deliveredSweep !== $sweep) {
            $this->delivered = [];
            $this->deliveredSweep = $sweep;
        }
        $unknown = $campaigns->filter(fn (UnifiedCampaign $c): bool => ! array_key_exists((string) $c->getKey(), $this->delivered))->values();
        if ($unknown->isNotEmpty()) {
            [$from, $to] = self::completeDays($now, 1);
            $figures = $this->campaignMetrics($unknown)->figuresByCampaign($from, $to);
            foreach ($unknown as $c) {
                $f = $figures[(string) $c->getKey()] ?? [];
                // A spend the money contract withheld (no exchange rate) is still spend the platform reported: it delivered.
                $this->delivered[(string) $c->getKey()] = (float) ($f['spend'] ?? 0) > 0 || (float) ($f['impressions'] ?? 0) > 0 || (int) ($f['spend_withheld_rows'] ?? 0) > 0;
            }
        }

        return $campaigns->filter(fn (UnifiedCampaign $c): bool => $this->delivered[(string) $c->getKey()] ?? false)->values();
    }

    private ?ReportingTimezone $clock = null;

    /** One reporting clock per evaluator: each project's timezone is read once, not once per campaign. */
    private function clock(): ReportingTimezone
    {
        return $this->clock ??= app(ReportingTimezone::class);
    }

    /** @var array<string, bool> campaign id → delivered yesterday, for the sweep in {@see} */
    private array $delivered = [];

    private ?string $deliveredSweep = null;

    /**
     * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — a campaign is eligible for a PERFORMANCE alert only while it is running: status
     * active and not past its end date. A paused, completed or ended campaign (its budget ran out, its dates passed) raises
     * nothing about its cost or its return — that is history, not a decision (MASTER_FINAL §هـ).
     */
    public static function running(UnifiedCampaign $c, Carbon $now, ?ReportingTimezone $clock = null): bool
    {
        // «Past its end date» is the CLIENT's day (ALERT-OBJECTIVE-RESULT-001): the project's reporting timezone, Riyadh by default.
        // A caller judging many campaigns passes ONE clock, which reads each project's timezone once.
        $today = $now->copy()->setTimezone(($clock ?? app(ReportingTimezone::class))->forProject((string) $c->project_id))->toDateString();
        $ended = $c->ends_on !== null && $c->ends_on->toDateString() < $today;

        return $c->status === CampaignStatus::Active->value && ! $ended;
    }

    /**
     * LINK-HEALTH-YESTERDAY-001 — every connection that synced in the last month is a CANDIDATE, one per connection; the
     * one rule ({@see onlyWhereYesterdayMissing()}) keeps those whose yesterday did not arrive. A failed run that a later run
     * made good is therefore nothing, and a connection that simply stopped syncing (no failed run at all) is
     * still found. A connection the platform refused is the `token_expiry` card, never a second one here.
     */
    private function syncFailures(AlertRule $rule, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        // The latest run per connection, for THIS rule's project when it has one (filtered inside, so another project's newer
        // run never hides this project's connection).
        $latest = MetricSyncRun::query()
            ->whereIn('id', function ($q) use ($rule, $now) {
                $q->selectRaw('DISTINCT ON (connection_id) id')
                    ->from('metric_sync_runs')
                    ->whereNotNull('connection_id')
                    ->where('created_at', '>=', $now->copy()->subDays(30))
                    ->when($rule->project_id !== null, fn ($q) => $q->where('project_id', $rule->project_id))
                    ->orderByRaw('connection_id, started_at DESC NULLS LAST, created_at DESC');
            })
            ->get();
        // A removed link says nothing; a refused one is the `token_expiry` card — when the tenant watches tokens at all.
        $watchesTokens = in_array('token_expiry', $this->activeRuleTypes((string) $rule->tenant_id), true);
        $skip = $latest->isEmpty() ? [] : ProviderConnection::query()->whereIn('id', $latest->pluck('connection_id')->unique()->values()->all())
            ->whereIn('status', $watchesTokens ? ['error', 'disconnected', 'disabled', 'revoked'] : ['disconnected', 'disabled', 'revoked'])
            ->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $latest = $latest->reject(fn (MetricSyncRun $r): bool => in_array((string) $r->connection_id, $skip, true))->values();

        return $latest->map(fn (MetricSyncRun $r) => [
            'entity_type' => ProviderConnection::class,
            'entity_id' => (string) $r->connection_id,
            'project_id' => $r->project_id ? (string) $r->project_id : null,
            'title' => 'Data sync failed',
            'message' => $r->status === 'failed' ? 'The latest sync for '.$r->provider.' failed'.($r->error ? ': '.$r->error : '.') : 'Syncs for '.$r->provider.' have not delivered the day.',
            'context' => ['provider' => $r->provider, 'sync_run_id' => (string) $r->id, 'error' => $r->status === 'failed' ? $r->error : null],
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
         * expire, sooner. It is named first and only once: an `error` row is not also reported as expiring. This is a PROVEN failure:
         * `TokenVault::fresh()` marks a connection `error` when the platform refused the refresh, or the grant was revoked.
         */
        $refused = self::oneIdentityOnce(ProviderConnection::query()
            ->where('status', 'error')
            ->orderBy('id')
            ->get())
            ->map(fn (ProviderConnection $c) => [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $c->id,
                'project_id' => null,
                'title' => 'Connection needs reconnecting',
                'message' => 'The '.$c->provider.' connection was refused by the provider and has stopped syncing. Reconnect it to resume.',
                'context' => ['provider' => $c->provider, 'error' => $c->last_error],
            ]);

        /*
         * LINKING-ALERT-NO-FALSE-EXPIRY-001 — `token_expires_at` is the ACCESS token's expiry. Google's lasts an hour and is renewed
         * from the refresh token on the next call, so «قارب ينتهي» on it was false on every working Google connection. Only a
         * credential that does NOT renew itself (no refresh token stored) has a real expiry to warn about, and that warning is not
         * instant: it waits for the morning digest's «ملخص الربط» (`digest_only`). A renewal that FAILS is the `error` row above.
         */
        $vault = app(TokenVault::class);
        $expiring = self::oneIdentityOnce(ProviderConnection::query()
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $cutoff)
            ->where('status', '!=', 'error')
            ->orderBy('id')
            ->get())
            ->filter(static function (ProviderConnection $c) use ($vault): bool {
                try {
                    return $vault->stored($c)->refreshToken === null;
                } catch (\Throwable) {
                    // No readable credential: nothing proves an expiry, and the sync failure (if any) is reported by its own rule.
                    return false;
                }
            })
            ->map(fn (ProviderConnection $c) => [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $c->id,
                'project_id' => null,
                'title' => 'Connection token expiring',
                'message' => 'The '.$c->provider.' connection token expires '.optional($c->token_expires_at)->toDateString().' and does not renew itself. Reconnect to avoid a sync gap.',
                'context' => ['provider' => $c->provider, 'expires_at' => optional($c->token_expires_at)->toIso8601String(), 'digest_only' => true],
            ]);

        return $refused->concat($expiring)->values()->all();
    }

    /**
     * LINK-HEALTH-YESTERDAY-001 — keeps a link breach only where the required day (yesterday, after 06:00 Riyadh) did not
     * arrive, states it in the reader's words (state 1, «بيانات أمس ما وصلت»), and escalates it to critical once
     * {@see LinkHealth::ESCALATE_AFTER_DAYS} days are missing. A project-level outage keeps only the platforms whose data
     * is missing, and goes away when none is.
     *
     * @param  list<array<string,mixed>>  $breaches
     * @return list<array<string,mixed>>
     */
    private function onlyWhereYesterdayMissing(AlertRule $rule, array $breaches, Carbon $now): array
    {
        $this->linkHealth->prime(array_values(array_map(static fn (array $b): string => (string) $b['entity_id'],
            array_filter($breaches, static fn (array $b): bool => $b['entity_type'] === ProviderConnection::class && $b['entity_id'] !== null))), $now);
        $pairs = [];
        foreach ($breaches as $b) {
            if ($b['entity_type'] === Project::class && $b['entity_id'] !== null) {
                foreach ((array) ($b['context']['sources'] ?? []) as $source) {
                    $pairs[] = [(string) $b['entity_id'], (string) ($source['provider'] ?? '')];
                }
            }
        }
        $this->linkHealth->primeProjects($pairs, $now);
        $out = [];
        foreach ($breaches as $b) {
            $context = (array) ($b['context'] ?? []);
            if ($b['entity_type'] === ProviderConnection::class && $b['entity_id'] !== null) {
                $reading = $this->linkHealth->forConnection((string) $b['entity_id'], $now);
                if ($reading['arrived']) {
                    continue;
                }
            } elseif ($b['entity_type'] === Project::class && $b['entity_id'] !== null) {
                $missing = [];
                $reading = null;
                foreach ((array) ($context['sources'] ?? []) as $source) {
                    $r = $this->linkHealth->forProjectProvider((string) $b['entity_id'], (string) ($source['provider'] ?? ''), $now);
                    if (! $r['arrived']) {
                        $missing[] = $source;
                        $reading ??= $r;
                    }
                }
                if ($missing === [] || $reading === null) {
                    continue;
                }
                $context['sources'] = $missing;
            } else {
                continue;
            }

            $day = (string) $reading['required_day'];
            $context['link_state'] = 'not_synced';
            $context['required_day'] = $day;
            $context['last_day'] = $reading['last_day'];
            $context['missing_days'] = $reading['missing_days'];
            $context['title_ar'] = LinkHealth::text('not_synced', true);
            $context['message_ar'] = 'ما وصلت بيانات يوم '.$day.($reading['last_day'] !== null ? '، آخر يوم وصل '.$reading['last_day'] : '').'.';
            $b['title'] = LinkHealth::text('not_synced', false);
            $b['message'] = 'No data arrived for '.$day.($reading['last_day'] !== null ? '; the last day that arrived is '.$reading['last_day'] : '').'.'
                .(isset($b['message']) ? ' '.$b['message'] : '');
            unset($context['digest_only']);
            // One item per connection: where the tenant watches each connection (`sync_failure`), the project-level outage would
            // restate those cards in the mail and the bell — it is kept for the digest's «ملخص الربط» only.
            if ($rule->type === 'sync_outage' && $this->watchesConnections((string) $rule->tenant_id)) {
                $context['digest_only'] = true;
            }
            if (($reading['missing_days'] ?? 0) >= LinkHealth::ESCALATE_AFTER_DAYS) { // escalation needs a MEASURED persistence; a link never read is not «days missing»
                $b['severity'] = 'critical';
            }
            $b['context'] = $context;
            $out[] = $b;
        }

        return $out;
    }

    private function watchesConnections(string $tenantId): bool
    {
        return in_array('sync_failure', $this->activeRuleTypes($tenantId), true);
    }

    /** @var array<string, list<string>> */
    private array $activeRuleTypes = [];

    /** @return list<string> the rule types a tenant has switched on — one read per tenant per evaluator */
    private function activeRuleTypes(string $tenantId): array
    {
        return $this->activeRuleTypes[$tenantId] ??= AlertRule::query()->where('tenant_id', $tenantId)->where('active', true)
            ->distinct()->pluck('type')->map(static fn ($t): string => (string) $t)->values()->all();
    }

    /**
     * LINK-HEALTH-YESTERDAY-001 — open link events written under the old per-RULE dedup key (before this rule) are the same fact as
     * the per-tenant key now: the newest is rekeyed and kept (no second notice on deploy), any further copy closed silently.
     *
     * @param  list<array<string,mixed>>  $breaches
     * @param  Collection<int, AlertEvent>  $open
     */
    private function adoptLegacyLinkEvents(AlertRule $rule, array $breaches, Collection $open): void
    {
        if ($breaches === [] || $open->isEmpty()) {
            return;
        }
        foreach ($breaches as $b) {
            $key = $this->dedupKey($rule, $b);
            $same = $open->filter(fn (AlertEvent $e): bool => $e->entity_type === $b['entity_type'] && (string) $e->entity_id === (string) ($b['entity_id'] ?? ''))->values();
            if ($same->isEmpty() || $same->every(fn (AlertEvent $e): bool => $e->dedup_key === $key)) {
                continue;
            }
            $keep = $same->first(fn (AlertEvent $e): bool => $e->dedup_key === $key) ?? $same->first();
            if ($keep->dedup_key !== $key) {
                $keep->forceFill(['dedup_key' => $key])->save();
            }
            $same->reject(fn (AlertEvent $e): bool => $e->is($keep))->each(fn (AlertEvent $e) => $this->closeAsDuplicate($e));
        }
    }

    private function closeAsDuplicate(AlertEvent $event): void
    {
        $now = Carbon::now();
        $this->resolve($event, $now);
        $event->forceFill(['context' => (array) $event->context + ['closed_reason' => 'same_link', 'closed_at' => $now->toIso8601String()]])->save();
    }

    /**
     * LINK-HEALTH-YESTERDAY-001 — why an open link event may close, on PROOF only: its yesterday arrived (`yesterday_arrived`), or
     * the link is gone (`link_removed`). Null keeps it open.
     */
    private function linkCloseReason(AlertEvent $event, Carbon $now): ?string
    {
        $ctx = (array) $event->context;
        if ($event->entity_type === ProviderConnection::class && $event->entity_id !== null) {
            if ($this->linkHealth->forConnection((string) $event->entity_id, $now)['arrived']) {
                return 'yesterday_arrived';
            }
            $connection = ProviderConnection::query()->withoutGlobalScopes()->whereKey($event->entity_id)->first(['id', 'status']);
            if ($connection === null || in_array((string) $connection->status, ['disconnected', 'disabled', 'revoked'], true)) {
                return 'link_removed';
            }
            // A refused grant closes when the connection was reconnected (no longer refused) — the proof of that failure's end.
            if ($event->type === 'token_expiry' && ! isset($ctx['expires_at']) && (string) $connection->status !== 'error') {
                return 'reconnected';
            }

            return null;
        }
        if ($event->entity_type === Project::class && $event->entity_id !== null) {
            foreach ((array) ($ctx['sources'] ?? []) as $source) {
                if (! $this->linkHealth->forProjectProvider((string) $event->entity_id, (string) ($source['provider'] ?? ''), $now)['arrived']) {
                    return null;
                }
            }

            return 'yesterday_arrived';
        }

        return 'yesterday_arrived';
    }

    /**
     * LINKING-ALERT-NO-FALSE-EXPIRY-001 — one card per platform IDENTITY: a Google login linked to three projects is three
     * `project_only` connections (Production, 2026-11-12) and was three identical cards. Grouped by provider and the platform's
     * own owner id; a connection with no owner id stands alone.
     *
     * @param  Collection<int, ProviderConnection>  $connections  ordered by id, so the same connection speaks for the group each sweep
     * @return Collection<int, ProviderConnection>
     */
    private static function oneIdentityOnce(Collection $connections): Collection
    {
        return $connections->unique(static fn (ProviderConnection $c): string => $c->provider.'|'.((string) ($c->external_owner_id ?? '') !== '' ? (string) $c->external_owner_id : 'id:'.$c->id))->values();
    }

    /** @return list<array<string,mixed>> */
    private function budgetRisks(AlertRule $rule, Carbon $now): array
    {
        $ratio = (float) ($rule->threshold['ratio'] ?? 0.9); // spend / budget threshold
        $from = $now->copy()->subDays(30);
        $out = [];

        $campaigns = UnifiedCampaign::query()->where('total_budget', '>', 0)
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — a campaign whose budget already ran out, or that is paused or ended, is not «at risk».
            ->filter(fn (UnifiedCampaign $c): bool => self::running($c, $now, $this->clock()))->values();
        $campaigns = $this->delivering($campaigns, $now);

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
                    /*
                     * Master task 2 — a budget the platform's spend has REACHED is a fact, not a risk: «خلصت ميزانية
                     * الحملة», with renewal as the next step. Said only when the measured spend reached the budget
                     * (OWNER_NOTIFICATIONS_RULES §7); below it the alert stays «قربت تخلص».
                     */
                    $exhausted = (float) $consumed >= 1.0;
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => $exhausted ? 'Campaign budget ran out' : 'Budget at risk',
                        'message' => $c->name.' has spent '.round((float) $consumed * 100).'% of its budget.',
                        'context' => [
                            'basis_class' => 'measured',
                            'spend' => $row['spent'],
                            'budget' => $row['budget'],
                            'ratio' => round((float) $consumed, 4),
                            'budget_exhausted' => $exhausted,
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

    /** @return list<array<string,mixed>> */
    private function noResults(AlertRule $rule, Carbon $now): array
    {
        $days = (int) ($rule->threshold['days'] ?? 3);
        [$from, $to] = self::completeDays($now, $days);
        $out = [];
        $state = [];

        $all = UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get();
        /*
         * OBJECTIVE-RESULT-001 — «صرف بدون نتائج» is a PERFORMANCE alert, so only a RUNNING campaign qualifies (MASTER_FINAL §هـ):
         * a paused or completed one, or one past its end date (the Riyadh day — DIGESTS-YESTERDAY-BY-OBJECTIVE-001), is not a
         * performance problem. ALERT-OBJECTIVE-RESULT-001 — and it is recorded why, so its open alert closes quietly.
         */
        $campaigns = $all->filter(function (UnifiedCampaign $c) use ($now, &$state): bool {
            if (self::running($c, $now, $this->clock())) {
                return true;
            }
            $state[(string) $c->getKey()] = ['reason' => 'campaign_inactive', 'result_key' => null];

            return false;
        })->values();
        $figures = $this->campaignMetrics($campaigns)->figuresByCampaign($from, $to);
        $window = ['window_from' => $from->toDateString(), 'window_to' => $to->toDateString()];

        $campaigns->each(function (UnifiedCampaign $c) use ($figures, $days, $window, &$out, &$state) {
            $id = (string) $c->getKey();
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
                    'message' => $c->name.' spent '.round($spend, 2).' with no '.strtolower((string) ($label['en'] ?? 'results')).' in the last '.$days.' days.',
                    // `conversions` keeps its name for the readers of this context; it now holds the objective's result.
                    'context' => ['spend' => $spend, 'conversions' => (float) $result, 'days' => $days,
                        'result_key' => $totals['result_key'], 'result_family' => $totals['result_family'] ?? null, 'result_label' => $totals['result_label'] ?? null] + $window,
                ];
            }
        });
        $this->noResultsState[(string) $rule->id] = $state;

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function roasDrops(AlertRule $rule, Carbon $now): array
    {
        $pct = (float) ($rule->threshold['pct'] ?? 25); // % drop vs previous window
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        [$curFrom, $curTo, $prevFrom, $prevTo] = self::completeDays($now, $days);

        // Running campaigns only, and ROAS only where the campaign was bought for sales (ROAS belongs to sales alone).
        $campaigns = UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            ->filter(fn (UnifiedCampaign $c): bool => self::running($c, $now, $this->clock())
                && ObjectiveResult::familyOf($c->objective === null ? null : (string) $c->objective) === ObjectiveFamily::Sales)
            ->values();
        $metrics = $this->campaignMetrics($campaigns);
        $current = $metrics->figuresByCampaign($curFrom, $curTo);
        $previous = $metrics->figuresByCampaign($prevFrom, $prevTo);
        $window = ['window_from' => $curFrom->toDateString(), 'window_to' => $curTo->toDateString()];

        $campaigns->each(function (UnifiedCampaign $c) use ($current, $previous, $pct, $days, $window, &$out) {
            $cur = $current[(string) $c->getKey()]['roas'];
            $prev = $previous[(string) $c->getKey()]['roas'];
            if ($cur !== null && $prev !== null && $prev > 0 && ($prev - $cur) / $prev * 100 >= $pct) {
                $out[] = [
                    'entity_type' => UnifiedCampaign::class,
                    'entity_id' => (string) $c->id,
                    'project_id' => (string) $c->project_id,
                    'title' => 'ROAS dropped',
                    // EMAIL-ALERT-DETERIORATION-001 — the metric, the threshold and the window, stated.
                    'message' => $c->name.' ROAS fell from '.round($prev, 2).' to '.round($cur, 2).' — '.$this->deteriorationBasis($days, $pct, 'drop'),
                    'context' => ['roas_current' => round($cur, 4), 'roas_previous' => round($prev, 4), 'threshold_pct' => $pct, 'window_days' => $days] + $window,
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
        // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — complete days ending yesterday (Riyadh); today is still being written.
        [$from, $to] = self::completeDays($now, $days);
        $earliest = $to->copy()->subDays(self::ANOMALY_RECENCY_DAYS - 1)->toDateString();

        $out = [];

        $campaigns = UnifiedCampaign::query()
            ->where('status', 'active')
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            // Running (not past its end date) and delivered yesterday — an idle or ended campaign has no «unusual day» to report.
            ->filter(fn (UnifiedCampaign $c): bool => self::running($c, $now, $this->clock()))->values();
        $campaigns = $this->delivering($campaigns, $now);
        $metrics = $this->campaignMetrics($campaigns);
        $series = $metrics->timeseriesByCampaign($from, $to);
        $timeline = new ChangeTimeline($metrics);

        $campaigns
            ->each(function (UnifiedCampaign $c) use ($series, $timeline, $from, $to, $earliest, &$out) {
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
                    'context' => ['points' => $points, 'window_days' => (int) round($from->diffInDays($to)) + 1],
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
        $pct = (float) ($rule->threshold['pct'] ?? 25);
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        [$curFrom, $curTo, $prevFrom, $prevTo] = self::completeDays($now, $days);
        $label = strtoupper($key);

        $campaigns = UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            // DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — a paused or ended campaign's cost is history, not a decision.
            ->filter(fn (UnifiedCampaign $c): bool => self::running($c, $now, $this->clock()))->values();
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
        $curComplete = ($metrics->coverageOfWindow($curFrom, $curTo)['state'] ?? 'complete') === 'complete';
        $prevComplete = ($metrics->coverageOfWindow($prevFrom, $prevTo)['state'] ?? 'complete') === 'complete';

        if (! $curComplete || ! $prevComplete) {
            return [];
        }

        $current = $metrics->figuresByCampaign($curFrom, $curTo);
        $previous = $metrics->figuresByCampaign($prevFrom, $prevTo);
        $window = ['window_from' => $curFrom->toDateString(), 'window_to' => $curTo->toDateString()];

        $campaigns->each(function (UnifiedCampaign $c) use ($current, $previous, $pct, $days, $key, $resultKey, $label, $window, &$out) {
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
                ] + $window,
            ];
        });

        return $out;
    }
}
