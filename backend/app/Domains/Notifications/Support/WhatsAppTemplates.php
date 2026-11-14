<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * Notification type → the pre-approved WhatsApp template that carries it — NOTIFICATION-CHANNELS-001.
 *
 * A business may START a WhatsApp conversation only with a template the platform has approved, so a
 * message here is never free text: it is a template NAME, and an ordered list of values for its
 * `{{1}}`, `{{2}}` … placeholders. Twilio addresses an approved template by its Content SID (`HX…`),
 * one per template and language, which only the account that created it can know — so the SIDs are
 * configuration (`services.twilio.whatsapp.content_sids`, a JSON map of `name.locale` → SID), and a
 * template with no SID configured is `template_missing`, never a free-text fallback.
 *
 * ## What is submitted for approval
 *
 * `body()` renders the exact wording to submit, per language. It follows the vendor's rules for
 * variables: framed by fixed text (a template may not start or end with a variable), no line breaks
 * inside a value, and short. Both values are the notification's own words — the title and the detail —
 * so the template says nothing about a figure that the product did not already say.
 *
 * Only types the policy marks `whatsapp = planned` have one; `NotificationChannelPolicyTest` keeps the
 * two lists equal.
 */
final class WhatsAppTemplates
{
    /** Longest a single variable may be. Longer values are cut with an ellipsis, never split. */
    public const MAX_VALUE = 160;

    /**
     * @var array<string, array{ar: string, en: string}> type → the fixed lead-in of the template, per language
     */
    private const LEAD = [
        'budget_pace' => ['ar' => 'تنبيه ميزانية', 'en' => 'Budget alert'],
        'internal_spend_limit' => ['ar' => 'اقتراب من حد الإنفاق', 'en' => 'Spend limit nearly reached'],
        'budget_risk' => ['ar' => 'خطر على الميزانية', 'en' => 'Budget at risk'],
        'stale_data' => ['ar' => 'بيانات متأخرة', 'en' => 'Data is late'],
        'sync_failed' => ['ar' => 'تعذّرت المزامنة', 'en' => 'Sync failed'],
        'token_expiring' => ['ar' => 'ربط على وشك الانتهاء', 'en' => 'Connection expiring'],
        'report_ready' => ['ar' => 'تقرير جاهز', 'en' => 'Report ready'],
        'report_failed' => ['ar' => 'تعذّر إنشاء تقرير', 'en' => 'Report failed'],
        'request' => ['ar' => 'تحديث على طلب', 'en' => 'Request update'],
        'client_needs_attention' => ['ar' => 'عميل يحتاج متابعة', 'en' => 'Client needs attention'],
        'lead_sla' => ['ar' => 'عميل محتمل ينتظر', 'en' => 'A lead is waiting'],
        'delivery_reminder' => ['ar' => 'تذكير تسليم', 'en' => 'Delivery reminder'],
        'subscription' => ['ar' => 'تنبيه اشتراك', 'en' => 'Subscription notice'],
        'security_alert' => ['ar' => 'تنبيه أمني', 'en' => 'Security alert'],
        'role_changed' => ['ar' => 'تغيّرت صلاحيتك', 'en' => 'Your role changed'],
        'automation_rule' => ['ar' => 'طابقت قاعدة أتمتة', 'en' => 'An automation rule matched'],
        'project_budget' => ['ar' => 'ميزانية المشروع', 'en' => 'Project budget'],
        'test_notification' => ['ar' => 'رسالة اختبار', 'en' => 'Test message'],
    ];

    /** The values every template takes, in order. */
    public const PARAMS = ['title', 'detail'];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::LEAD);
    }

    /** @return array{name: string, params: list<string>}|null */
    public static function for(string $type): ?array
    {
        return isset(self::LEAD[$type]) ? ['name' => 'ch_'.$type, 'params' => self::PARAMS] : null;
    }

    /** The wording to submit for approval. Null for a type with no template. */
    public static function body(string $type, string $locale): ?string
    {
        $lead = self::LEAD[$type][$locale === 'en' ? 'en' : 'ar'] ?? null;

        if ($lead === null) {
            return null;
        }

        return $locale === 'en'
            ? "CampaignsHub — {$lead}: {{1}}. {{2}} Open the platform for the details."
            : "CampaignsHub — {$lead}: {{1}}. {{2}} افتح المنصة لمراجعة التفاصيل.";
    }

    /** The Content SID the account created for this template in this language, or null while there is none. */
    public static function contentSid(string $name, string $locale): ?string
    {
        $sids = config('services.twilio.whatsapp.content_sids');
        $sid = is_array($sids) ? ($sids[$name.'.'.($locale === 'en' ? 'en' : 'ar')] ?? null) : null;

        return is_string($sid) && preg_match('/^HX[0-9a-f]{32}$/i', $sid) === 1 ? $sid : null;
    }

    /**
     * The `{{n}}` values for one message: numbered from 1, newlines and runs of spaces collapsed (the
     * vendor rejects them inside a variable), cut to `MAX_VALUE`.
     *
     * @param  array<string, string>  $values  keyed by the names in `PARAMS`
     * @return array<int, string> PHP turns the numeric string keys into ints; `json_encode((object) …)` writes them back as «1», «2»
     */
    public static function variables(array $values): array
    {
        $out = [];

        foreach (self::PARAMS as $i => $key) {
            $clean = trim((string) preg_replace('/\s+/u', ' ', (string) ($values[$key] ?? '')));
            $out[(string) ($i + 1)] = mb_strlen($clean) > self::MAX_VALUE ? mb_substr($clean, 0, self::MAX_VALUE - 1).'…' : ($clean === '' ? '-' : $clean);
        }

        return $out;
    }
}
