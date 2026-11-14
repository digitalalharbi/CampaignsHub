<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * Which channels each notification travels on, stated once — NOTIFICATION-CHANNELS-001.
 *
 * Owner, 2026-11-06: «هل الأتمتة والتنبيهات توصل داخل النظام أو على الإيميل؟ أحتاج كل شيء يكون
 * إشعارات على الإيميل + النظام + لاحقًا على الواتساب.»
 *
 * ## Why this is a second class beside `MessageCatalogue`
 *
 * `MessageCatalogue` answers «what may a PERSON switch» — a category, a rhythm, a default. This answers
 * «what does the PRODUCT do with it» — is there a bell item, who composes the email, may it ever go to
 * WhatsApp. The two had drifted: `automation_rule` was raised by `RuleEvaluator`, was in neither the
 * catalogue nor the dispatcher's two hand-kept lists, and so wrote `in_app_only` to the ledger for as
 * long as it had existed. Nothing failed. A list nobody is forced to extend is a list that is wrong
 * by omission.
 *
 * `NotificationChannelPolicyTest` is the force: it reads `app/` for every type a dispatcher call can
 * raise, and fails for one this class cannot resolve. The dispatcher now asks THIS class where the
 * email goes, so a type missing here cannot reach the dispatcher's email branch at all.
 *
 * ## Fields
 *
 * - `in_app` — the product raises a bell item for it. FALSE for the analysis notes and the digests,
 *   which reach a person by email only; the settings screen shows a dash there instead of a switch
 *   that controls nothing.
 * - `email` — `immediate` (mailed when it happens), `digest` (is itself a digest), `mandatory`
 *   (account and security, no switch), `off` (no email form at all).
 * - `via` — who composes that email: `dispatcher` (NotificationDispatcher itself), `own_sender`
 *   (a sender with its own ledger), `alert_mailer` (AlertEventMailer; the alert engine posts these
 *   and the mailer reads the event), `none`.
 * - `digestible` — may be held for a daily/weekly/monthly digest because the digest prints it.
 * - `whatsapp` — `planned` (has a template in `WhatsAppTemplates`) or `never`.
 * - `spend_bearing` — names a campaign or an amount; never mailed to a member whose membership
 *   withholds `money.view` (client audiences keep the client-report rules).
 *
 * ## The audit — every type, as found on 2026-11-06 and what changed
 *
 * | type | in-app | email (before → now) | WhatsApp |
 * |---|---|---|---|
 * | automation_rule | yes | in_app_only → mailed by dispatcher (default on) | planned |
 * | request.* (assigned, status_changed, info_requested, sla_*; received) | yes | in_app_only → mailed by dispatcher as `request` (default off) | planned |
 * | request.journey.* | yes | in_app_only → mailed by dispatcher as `journey_transition` (default off) | never |
 * | message, client_needs_attention | yes | mailed by dispatcher (default off) — unchanged | message never, attention planned |
 * | report_ready, report_failed | yes | handled_elsewhere, but only an ALERT post has a mailer, so the finished-report notice was never mailed → mailed by dispatcher unless the post came from the alert engine (default on) | planned |
 * | budget_risk, sync_failed, token_expiring, lead_sla, performance, security | yes | AlertEventMailer — unchanged | budget/sync/token/lead planned |
 * | subscription | yes | SubscriptionNotifier — unchanged | planned |
 * | the eight analysis notes (falling_rate … frequency_saturation, budget_pace …) | no | AlertDispatcher / digests — unchanged | budget ones planned |
 * | daily/weekly/monthly digest | no | DigestDispatcher — unchanged | never |
 * | scheduled_report, client_request, delivery_reminder | no | own senders — unchanged | delivery planned |
 * | password_reset, email_verification, sign_in_code, oauth_link, member_setup, invitation, member_welcome | no | mandatory — unchanged | never (sign-in codes ride Twilio Verify) |
 * | security_alert, role_changed | no | mandatory — unchanged | planned |
 * | test_notification | yes | the settings screen's own test button | planned |
 */
final class NotificationChannelPolicy
{
    /**
     * Every file under `app/` that raises a bell notification, or calls the notifier that does.
     * `NotificationChannelPolicyTest` fails for a file that does and is not listed — add it here AND
     * its types below, in the same change.
     *
     * @var list<string>
     */
    public const SENDERS = [
        'Domains/Alerts/Services/AlertEvaluator.php',
        'Domains/Automation/Services/RuleEvaluator.php',
        'Domains/ClientWorkspaces/Services/ClientManagementService.php',
        'Domains/Funding/Services/FundingNotifier.php',
        'Domains/Messaging/Services/MessagingService.php',
        'Domains/Reports/Jobs/GenerateReportJob.php',
        'Domains/Requests/Services/RequestJourneyService.php',
        'Domains/Requests/Services/RequestNotifier.php',
        'Domains/Settings/Http/Controllers/NotificationChannelTestController.php',
        'Domains/Subscriptions/Notifications/SubscriptionNotifier.php',
    ];

    /** Dispatched type → the type its preferences, mail kind and policy are filed under. */
    private const FAMILIES = [
        'request.journey.' => 'journey_transition',
        'request.' => 'request',
    ];

    private const ALIASES = ['received' => 'request'];

    /** Mails one person at most this many times an hour per type; the rest stay in the bell. */
    public const EMAIL_CEILING_PER_HOUR = 5;

    /**
     * Per-type facts the catalogue does not hold. `in_app`, `via`, `whatsapp`, `severity`, `spend`.
     *
     * @var array<string, array{in_app: bool, via: string, whatsapp: string, severity: string, spend?: bool}>
     */
    private const ROUTES = [
        'falling_rate' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'warning'],
        'period_comparison' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'budget_pace' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning', 'spend' => true],
        'internal_spend_limit' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning', 'spend' => true],
        'rising_cost' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'warning', 'spend' => true],
        'reallocation' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info', 'spend' => true],
        'budget_risk' => ['in_app' => true, 'via' => 'alert_mailer', 'whatsapp' => 'planned', 'severity' => 'critical', 'spend' => true],
        'frequency_saturation' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'warning'],
        'stale_data' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'data_gap' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'warning'],
        'sync_failed' => ['in_app' => true, 'via' => 'alert_mailer', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'token_expiring' => ['in_app' => true, 'via' => 'alert_mailer', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'daily_digest' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'weekly_digest' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'monthly_digest' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        // Raised by the alert engine (mailed by AlertEventMailer) AND by the report job (mailed here): see `routeFor()`.
        'report_ready' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'info'],
        'report_failed' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'critical'],
        'scheduled_report' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'message' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'never', 'severity' => 'info'],
        'request' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'info'],
        'journey_transition' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'never', 'severity' => 'info'],
        'client_needs_attention' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'lead_sla' => ['in_app' => true, 'via' => 'alert_mailer', 'whatsapp' => 'planned', 'severity' => 'critical'],
        'delivery_reminder' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'client_request' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'subscription' => ['in_app' => true, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'password_reset' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'email_verification' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'sign_in_code' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'oauth_link' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'member_setup' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'invitation' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'security_alert' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'critical'],
        'role_changed' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'planned', 'severity' => 'warning'],
        'member_welcome' => ['in_app' => false, 'via' => 'own_sender', 'whatsapp' => 'never', 'severity' => 'info'],
        'automation_rule' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'warning', 'spend' => true],
        // FIN-LOOP-001 — a project's approved budget: added, running low, over. Recipients are chosen by FundingNotifier.
        'project_budget' => ['in_app' => true, 'via' => 'dispatcher', 'whatsapp' => 'planned', 'severity' => 'warning', 'spend' => true],
    ];

    /**
     * Types raised in-app that have no catalogue entry because a person has no switch for them.
     *
     * @var array<string, array{category: string, in_app: bool, email: string, via: string, whatsapp: string, severity: string, note: string}>
     */
    private const EXTRAS = [
        // What `AlertEvaluator` posts for a rule with no notification type of its own (GA4, store, anomaly rules …).
        'performance' => ['category' => 'performance', 'in_app' => true, 'email' => 'immediate', 'via' => 'alert_mailer', 'whatsapp' => 'never', 'severity' => 'warning', 'note' => 'AlertEventMailer mails the event; the bell post is the same finding'],
        // `sla_warning` is filed under `security` by the alert engine.
        'security' => ['category' => 'account', 'in_app' => true, 'email' => 'immediate', 'via' => 'alert_mailer', 'whatsapp' => 'never', 'severity' => 'critical', 'note' => 'alert-engine post of the SLA rule; AlertEventMailer mails it'],
        // The settings screen's own test button: a bell item for the signed-in person, nothing else.
        'test_notification' => ['category' => 'account', 'in_app' => true, 'email' => 'off', 'via' => 'none', 'whatsapp' => 'planned', 'severity' => 'info', 'note' => 'the email test is sent by the settings controller as a marked test message, never through the dispatcher'],
    ];

    /**
     * @return array<string, array{
     *     category: string, severity: string, in_app: bool, email: string, via: string,
     *     digestible: bool, mandatory: bool, whatsapp: string, spend_bearing: bool,
     *     default_email: bool, default_in_app: bool
     * }>
     */
    public static function types(): array
    {
        $out = [];

        foreach (MessageCatalogue::types() as $type => $def) {
            $route = self::ROUTES[$type] ?? null;

            if ($route === null) {
                continue; // `NotificationChannelPolicyTest` fails on this: a catalogue type with no route.
            }

            $digest = isset(MessageCatalogue::DIGEST_SWITCH[$type]);
            $out[$type] = [
                'category' => $def['category'],
                'severity' => $route['severity'],
                'in_app' => $route['in_app'],
                'email' => $def['mandatory'] ? 'mandatory' : ($digest ? 'digest' : 'immediate'),
                'via' => $route['via'],
                'digestible' => count($def['rhythms']) > 1,
                'mandatory' => $def['mandatory'],
                'whatsapp' => $def['mandatory'] && MessageCatalogue::carriesSecret($type) ? 'never' : $route['whatsapp'],
                'spend_bearing' => $route['spend'] ?? false,
                'default_email' => $def['default_email'],
                'default_in_app' => $def['default_in_app'],
            ];
        }

        foreach (self::EXTRAS as $type => $x) {
            $out[$type] = [
                'category' => $x['category'], 'severity' => $x['severity'], 'in_app' => $x['in_app'], 'email' => $x['email'],
                'via' => $x['via'], 'digestible' => false, 'mandatory' => false, 'whatsapp' => $x['whatsapp'],
                'spend_bearing' => false, 'default_email' => $x['email'] !== 'off', 'default_in_app' => true,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    /**
     * The policy key a DISPATCHED type belongs to, or null for a string nobody has classified.
     *
     * `request.assigned`, `request.sla_breached` … are generated per state and cannot be listed, so a
     * family prefix files them under `request`; `request.journey.<stage>` under `journey_transition`.
     */
    public static function resolve(string $type): ?string
    {
        $types = self::types();

        if (isset($types[$type])) {
            return $type;
        }

        if (isset(self::ALIASES[$type])) {
            return self::ALIASES[$type];
        }

        foreach (self::FAMILIES as $prefix => $key) {
            if (str_starts_with($type, $prefix)) {
                return $key;
            }
        }

        return null;
    }

    /** @return array{category: string, severity: string, in_app: bool, email: string, via: string, digestible: bool, mandatory: bool, whatsapp: string, spend_bearing: bool, default_email: bool, default_in_app: bool}|null */
    public static function for(string $type): ?array
    {
        $key = self::resolve($type);

        return $key === null ? null : self::types()[$key];
    }

    /**
     * The key a person's per-type switch is stored under for this dispatched type — null when they
     * have none, in which case the older category route applies unchanged.
     */
    public static function switchKey(string $type): ?string
    {
        $key = self::resolve($type);

        return $key !== null && MessageCatalogue::has($key) ? $key : null;
    }

    /**
     * Who sends the email half of this bell notification.
     *
     * - `dispatcher` — NotificationDispatcher composes and queues it.
     * - `elsewhere` — a sender with its own ledger does; the dispatcher only says so.
     * - `none` — there is no email form, or the type is unclassified. An unclassified type is NEVER
     *   mailed by default: that is a decision somebody makes in this file, not a side effect.
     *
     * `report_ready` / `report_failed` are raised twice: by the alert engine (`source = alerts`, mailed
     * by AlertEventMailer from the event) and by the report job (mailed here). Before this the ledger
     * said `handled_elsewhere` for both, and the second had no elsewhere.
     */
    public static function routeFor(string $type, ?string $source = null): string
    {
        $entry = self::for($type);

        if ($entry === null || $entry['email'] === 'off') {
            return 'none';
        }

        if ($entry['via'] === 'dispatcher') {
            $key = (string) self::resolve($type);

            return in_array($key, ['report_ready', 'report_failed'], true) && $source === 'alerts' ? 'elsewhere' : 'dispatcher';
        }

        return $entry['via'] === 'none' ? 'none' : 'elsewhere';
    }
}
