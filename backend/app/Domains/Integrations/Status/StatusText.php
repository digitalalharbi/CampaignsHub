<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

/**
 * INTEG-STATUS-MODEL-001 — the words, kept apart from the rules so a rule change never edits a sentence
 * and a sentence change never edits a rule.
 *
 * Two vocabularies live here: what a STATE is called (`label`) and what a person should DO about it
 * (`action`). Both are given in Arabic and English because the product is Arabic-first and a remediation
 * step only helps if it is read. No sentence quotes a provider's own message: those can carry identifiers,
 * and the category is what decides who acts.
 */
final class StatusText
{
    /** @var array<string, array{0:string,1:string}> action code => [ar, en] */
    private const ACTIONS = [
        'reauthorize' => ['أعد المصادقة', 'Re-authorise the connection'],
        'reauthorize_before_expiry' => ['أعد المصادقة قبل انتهاء صلاحية الرمز', 'Re-authorise before the token expires'],
        'replace_api_key' => ['استبدل مفتاح API بمفتاح صالح', 'Replace the API key with a valid one'],
        'grant_read_scope' => ['امنح المستخدم صلاحية قراءة الإحصاءات على هذا الحساب في المنصة ثم أعد المزامنة', 'Grant the authorising user read access to this account at the provider, then re-sync'],
        'restore_access' => ['أعد منح الوصول إلى هذا الحساب في المنصة ثم أعد المزامنة', 'Restore access to this account at the provider, then re-sync'],
        'choose_accounts' => ['اختر الحسابات التي تريد ربطها', 'Choose the accounts to link'],
        'assign_project' => ['اربط الحساب بمشروع', 'Link the account to a project'],
        'reconnect' => ['اربط الحساب من جديد', 'Connect it again'],
        'set_currency' => ['حدّد عملة الحساب ليظهر الإنفاق', 'Set the account currency so spend can be shown'],
        'review_unmapped' => ['راجع الحملات التي لم تُطابَق', 'Review the campaigns that could not be matched'],
        'retry_sync' => ['أعد المزامنة', 'Run the sync again'],
        'run_first_sync' => ['شغّل المزامنة الأولى', 'Run the first sync'],
        'resume_sync' => ['فعّل المزامنة لهذا الربط', 'Turn sync back on for this link'],
        'add_platform_credentials' => ['أضف بيانات اعتماد المنصة (إجراء للمشغّل)', 'Add the platform credentials (operator action)'],
        'fix_provider_prerequisite' => ['أصلح شرط المنصة الموضح ثم أعد الاكتشاف', 'Fix the provider prerequisite, then discover again'],
        'wait' => ['لا إجراء مطلوب الآن؛ ستكتمل المزامنة تلقائيًا', 'Nothing to do now; the sync will finish by itself'],
    ];

    /** @var array<string, array<string, array{0:string,1:string}>> dimension => state => [ar, en] */
    private const LABELS = [
        'authorisation' => [
            'connected' => ['مفوَّض', 'Authorised'],
            'expiring' => ['ينتهي التفويض قريبًا', 'Authorisation expiring'],
            'reauth_required' => ['يلزم إعادة المصادقة', 'Re-authorisation required'],
            'scope_not_granted' => ['صلاحية القراءة غير ممنوحة لهذا الحساب', 'Read access not granted for this account'],
            'access_lost' => ['فُقد الوصول إلى هذا الحساب', 'Access to this account was lost'],
            'api_key_invalid' => ['مفتاح API غير صالح', 'API key invalid'],
            'revoked' => ['أُلغي التفويض', 'Authorisation revoked'],
            'removed' => ['أُزيل الربط', 'Connection removed'],
        ],
        'binding' => [
            'discovered_unbound' => ['مكتشف وغير مرتبط', 'Discovered, not linked'],
            'none_discovered' => ['لم يُكتشف أي مورد', 'Nothing discovered'],
            'bound' => ['مرتبط بمشروع', 'Linked to a project'],
            'deselected' => ['أُلغي اختياره', 'Deselected'],
            'detached' => ['فُصل مع الربط', 'Detached'],
        ],
        'sync' => [
            'never_started' => ['لم تبدأ المزامنة', 'Never synced'],
            'running' => ['المزامنة جارية', 'Syncing'],
            'succeeded' => ['نجحت آخر مزامنة', 'Last sync succeeded'],
            'partial' => ['اكتملت جزئيًا', 'Completed partially'],
            'failed' => ['فشلت آخر مزامنة', 'Last sync failed'],
            'paused' => ['المزامنة متوقفة', 'Sync paused'],
        ],
        'freshness' => [
            'fresh' => ['حديثة', 'Fresh'],
            'stale' => ['متأخرة', 'Stale'],
            'partial' => ['ناقصة', 'Incomplete'],
            'withheld' => ['محجوبة', 'Withheld'],
            'unknown' => ['غير معروفة', 'Unknown'],
        ],
        'error' => [
            'none' => ['لا خطأ', 'No error'],
            'attention' => ['يحتاج إجراءً', 'Needs action'],
            'error' => ['خطأ', 'Error'],
        ],
    ];

    /** @return array{code:string,label_ar:string,label_en:string}|null */
    public static function action(?string $code): ?array
    {
        if ($code === null || ! isset(self::ACTIONS[$code])) {
            return null;
        }

        return ['code' => $code, 'label_ar' => self::ACTIONS[$code][0], 'label_en' => self::ACTIONS[$code][1]];
    }

    /** @return array{label_ar:string,label_en:string} */
    public static function label(string $dimension, string $state): array
    {
        $pair = self::LABELS[$dimension][$state] ?? [$state, $state];

        return ['label_ar' => $pair[0], 'label_en' => $pair[1]];
    }

    /** @return list<string> */
    public static function states(string $dimension): array
    {
        return array_keys(self::LABELS[$dimension]);
    }

    /** @return list<string> */
    public static function actionCodes(): array
    {
        return array_keys(self::ACTIONS);
    }
}
