<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * Every message this product can send, named once — MAIL-011.
 *
 * ## Why a catalogue at all
 *
 * Before this class the same question was answered in four places with four vocabularies:
 * `NotificationDispatcher::CATEGORY` mapped six in-app types, `AlertDispatcher::CATEGORY_OF` mapped
 * eight digest notes, `NotificationPreferenceController::CATEGORIES` listed six categories a person
 * could switch, and `NotificationRecipientController::CATEGORIES` listed the same six for a manager.
 * Any new message type joined none of them and quietly fell through to `performance`.
 *
 * The failure that produces is specific and silent: a person switches «الأداء» off to stop
 * performance alerts and also stops receiving conversation messages and subscription notices,
 * because those were unmapped and `performance` is where unmapped things land. Nothing errors. The
 * screen shows six honest-looking switches that control more than they say.
 *
 * ## The rule for what may appear here
 *
 * **Only messages something in this repository actually sends.** A switch for a message that cannot
 * arrive is worse than a missing switch: it is a promise the product does not keep, and the person
 * who turns it on and waits has no way to discover that. Every entry below names its sender in the
 * `sent_by` field, and `MessageCatalogueTest` walks the codebase to check that the sender exists.
 *
 * ## Rhythms are offered only where a rhythm exists
 *
 * `immediate` means `AlertDispatcher` mails it when it finds it. `daily` and `weekly` mean it is
 * held for the digest — which is only truthful for the notes the digest actually carries
 * (`ReportObservations` findings, which `DailyDigest` prints under each project). An invoice, an
 * approval request or a new conversation message is an EVENT: nothing batches those today, so those
 * types offer `immediate` alone rather than a select box with two options that do nothing.
 *
 * ## Mandatory messages
 *
 * A password reset, a sign-in code, an email verification, an invitation and a security alert have
 * no switch. Not because they are important — the digest is important too — but because they are
 * the answer to something the person just did, or the only warning they will get that somebody else
 * is in their account. A product that lets you switch off «your password was changed» has built the
 * attacker a mute button. They appear in the catalogue, and the screen shows them as always-sent
 * with that reason written out, rather than hiding them and letting people wonder.
 *
 * ## Legacy categories
 *
 * The six older categories (`budget`, `performance`, `sync`, `token`, `reports`, `security`) are
 * still stored in thousands of `notification_preferences.categories` maps. Each type below records
 * the legacy category it was ACTUALLY resolved under, so an existing row keeps meaning what its
 * owner meant. `legacy` is null where a type had no honest legacy mapping — it fell through to
 * `performance` by accident, and inheriting an accident is not backwards compatibility.
 */
final class MessageCatalogue
{
    /** The seven a person chooses between, plus `account` — which is shown and cannot be chosen. */
    public const CATEGORIES = [
        'performance', 'budget', 'content', 'integrations', 'reports', 'operations', 'billing', 'account',
    ];

    /** Categories a manager may name on a recipient arrangement — MAIL-010's vocabulary, widened once. */
    public const ARRANGEABLE = ['performance', 'budget', 'content', 'integrations', 'reports'];

    /**
     * Old category → new. Read-only compatibility: `notification_recipients` rows written before
     * MAIL-011 hold `sync` and `token`, and both are integrations questions.
     */
    private const LEGACY_ALIAS = ['sync' => 'integrations', 'token' => 'integrations'];

    public const RHYTHMS = ['immediate', 'daily', 'weekly'];

    /**
     * The two types whose on/off lives in the older `digests` map rather than in `types`.
     *
     * One setting, one home. Storing «send me the daily summary» in both places would give the
     * scheduler and the screen separate answers the first time one of them was written alone.
     */
    public const DIGEST_SWITCH = ['daily_digest' => 'daily', 'weekly_digest' => 'weekly', 'monthly_digest' => 'monthly'];

    /** Types that can be held for a digest, because the digest prints them. */
    private const DIGESTIBLE = ['immediate', 'daily', 'weekly'];

    /** Types that are an event and nothing batches them. */
    private const EVENT_ONLY = ['immediate'];

    /**
     * @return array<string, array{
     *     category: string,
     *     mandatory: bool,
     *     rhythms: list<string>,
     *     legacy: ?string,
     *     default_email: bool,
     *     default_in_app: bool,
     *     sent_by: string
     * }>
     */
    public static function types(): array
    {
        return [
            // ── الأداء ────────────────────────────────────────────────────────────────────────
            'falling_rate' => self::note('performance', 'performance'),
            'period_comparison' => self::note('performance', 'performance'),

            // ── الميزانية ─────────────────────────────────────────────────────────────────────
            // Money moving the wrong way defaults to ON. The rest of `performance` defaults off
            // because it is analysis; this is the one that costs something while nobody is looking.
            'budget_pace' => self::note('budget', 'budget', defaultEmail: true),
            /*
             * BUDGET-ALERT-EMAIL-001 — the workspace's OWN limit, which is a different object from a
             * platform budget and reaches the same inbox.
             *
             * `budget_pace` is a platform budget the platform itself enforces. This is an internal
             * ceiling nothing enforces, so the message has to say so — and it has to arrive by
             * email, because the whole reason somebody sets a limit nothing enforces is that they
             * intend to act on it themselves.
             */
            'internal_spend_limit' => self::note('budget', 'budget', defaultEmail: true),
            'rising_cost' => self::note('budget', 'budget', defaultEmail: true),
            'reallocation' => self::note('budget', 'budget', defaultEmail: true),
            'budget_risk' => self::event('budget', 'budget', 'AlertEvaluator', defaultEmail: true),

            // ── المحتوى ───────────────────────────────────────────────────────────────────────
            /*
             * Frequency saturation moved here from `performance`, where `AlertDispatcher` had it.
             *
             * It is a statement about a creative — «the same people have seen this too many times» —
             * and the decision it asks for is to change the asset. Filing it under performance meant
             * a person who wanted creative warnings had to accept every rate-movement note as well.
             *
             * Its default stays OFF, the same as under `performance`, so nobody's inbox gets louder
             * because of a reclassification.
             */
            'frequency_saturation' => self::note('content', null),

            // ── التكاملات ─────────────────────────────────────────────────────────────────────
            'stale_data' => self::note('integrations', 'sync', defaultEmail: true),
            'data_gap' => self::note('integrations', 'sync', defaultEmail: true),
            'sync_failed' => self::event('integrations', 'sync', 'AlertEvaluator', defaultEmail: true),
            'token_expiring' => self::event('integrations', 'token', 'AlertEvaluator', defaultEmail: true),

            // ── التقارير ──────────────────────────────────────────────────────────────────────
            'daily_digest' => self::rhythmItself('DigestDispatcher::sendDaily'),
            'weekly_digest' => self::rhythmItself('DigestDispatcher::sendWeekly'),
            'monthly_digest' => self::rhythmItself('DigestDispatcher::sendMonthly'),
            'report_ready' => self::event('reports', 'reports', 'AlertEvaluator', defaultEmail: true),
            'report_failed' => self::event('reports', 'reports', 'AlertEvaluator', defaultEmail: true),

            // ── التشغيل ───────────────────────────────────────────────────────────────────────
            /*
             * These all reach the bell today and none of them reaches an inbox, so their email
             * default is OFF and turning it on is a choice somebody makes deliberately. A product
             * that starts emailing every chat message the day it can is one people mute entirely.
             */
            'message' => self::event('operations', null, 'MessagingService'),
            'request' => self::event('operations', null, 'RequestNotifier'),
            'journey_transition' => self::event('operations', null, 'RequestJourneyService'),
            'client_needs_attention' => self::event('operations', null, 'ClientManagementService'),
            /*
             * LEAD-SLA-NOTIFICATION-001 — a promise to a buyer that has not been kept.
             *
             * Email is ON by default, which is deliberate and rare here. The other operations types
             * describe things that happened inside the product, where the bell finds the reader
             * eventually. This one describes somebody waiting for a phone call they were sold: the
             * cost of it arriving late is the client's money, and «eventually» is the failure.
             */
            'lead_sla' => self::event('operations', null, 'AlertEvaluator', defaultEmail: true),

            // ── المالية ───────────────────────────────────────────────────────────────────────
            'subscription' => self::event('billing', null, 'SubscriptionNotifier', defaultEmail: true),

            // ── الحساب ────────────────────────────────────────────────────────────────────────
            'password_reset' => self::mandatory('PasswordResetService'),
            'email_verification' => self::mandatory('RegistrationVerificationService'),
            'sign_in_code' => self::mandatory('CredentialMail::SIGN_IN_CODE'),
            'member_setup' => self::mandatory('PasswordResetService::inviteExistingMember'),
            'invitation' => self::mandatory('InvitationService'),
            'security_alert' => self::mandatory('SecurityAlertMail'),
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::types());
    }

    /** @return array{category: string, mandatory: bool, rhythms: list<string>, legacy: ?string, default_email: bool, default_in_app: bool, sent_by: string}|null */
    public static function get(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    public static function isMandatory(string $type): bool
    {
        return (self::types()[$type]['mandatory'] ?? false) === true;
    }

    /** @return list<string> */
    public static function rhythmsFor(string $type): array
    {
        return self::types()[$type]['rhythms'] ?? self::EVENT_ONLY;
    }

    public static function categoryOf(string $type): ?string
    {
        return self::types()[$type]['category'] ?? null;
    }

    /**
     * The types filed under a category, in catalogue order.
     *
     * @return list<string>
     */
    public static function inCategory(string $category): array
    {
        return array_values(array_keys(array_filter(
            self::types(),
            static fn (array $t): bool => $t['category'] === $category,
        )));
    }

    /**
     * A stored category value in today's vocabulary.
     *
     * `notification_recipients` rows and `notification_preferences.categories` keys written before
     * this class exist with `sync` and `token`. Rewriting them in a migration would be a data change
     * to fix a naming decision; translating on read costs one array lookup and cannot lose a row.
     */
    public static function normaliseCategory(string $category): string
    {
        return self::LEGACY_ALIAS[$category] ?? $category;
    }

    /**
     * The types a digest note of this kind belongs to — the answer `AlertDispatcher` needs.
     *
     * Unmapped notes fall to `performance`, whose email default is OFF, so a finding nobody
     * classified stays quiet rather than loud.
     */
    public static function categoryOfNote(string $kind): string
    {
        return self::categoryOf($kind) ?? 'performance';
    }

    /** @return array{category: string, mandatory: bool, rhythms: list<string>, legacy: ?string, default_email: bool, default_in_app: bool, sent_by: string} */
    private static function note(string $category, ?string $legacy, bool $defaultEmail = false): array
    {
        return [
            'category' => $category,
            'mandatory' => false,
            'rhythms' => self::DIGESTIBLE,
            'legacy' => $legacy,
            'default_email' => $defaultEmail,
            'default_in_app' => true,
            'sent_by' => 'ReportObservations → AlertDispatcher / DailyDigest',
        ];
    }

    /** @return array{category: string, mandatory: bool, rhythms: list<string>, legacy: ?string, default_email: bool, default_in_app: bool, sent_by: string} */
    private static function event(string $category, ?string $legacy, string $sentBy, bool $defaultEmail = false): array
    {
        return [
            'category' => $category,
            'mandatory' => false,
            'rhythms' => self::EVENT_ONLY,
            'legacy' => $legacy,
            'default_email' => $defaultEmail,
            'default_in_app' => true,
            'sent_by' => $sentBy,
        ];
    }

    /**
     * The digests themselves.
     *
     * They are in the catalogue so the screen can list them beside everything else, and they have no
     * rhythm select because they ARE the rhythm. Their on/off lives in the older `digests` map, which
     * `SendDailyDigests` reads directly; duplicating it into `types` would give one setting two
     * homes and eventually two answers.
     *
     * @return array{category: string, mandatory: bool, rhythms: list<string>, legacy: ?string, default_email: bool, default_in_app: bool, sent_by: string}
     */
    private static function rhythmItself(string $sentBy): array
    {
        return [
            'category' => 'reports',
            'mandatory' => false,
            'rhythms' => [],
            'legacy' => null,
            'default_email' => false,
            'default_in_app' => false,
            'sent_by' => $sentBy,
        ];
    }

    /** @return array{category: string, mandatory: bool, rhythms: list<string>, legacy: ?string, default_email: bool, default_in_app: bool, sent_by: string} */
    private static function mandatory(string $sentBy): array
    {
        return [
            'category' => 'account',
            'mandatory' => true,
            'rhythms' => self::EVENT_ONLY,
            'legacy' => 'security',
            'default_email' => true,
            'default_in_app' => true,
            'sent_by' => $sentBy,
        ];
    }
}
