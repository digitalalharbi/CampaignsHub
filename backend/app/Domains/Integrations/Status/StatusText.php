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
        'record_fx_rate' => ['يسجّل المشغّل سعر الصرف فتظهر المبالغ تلقائيًا', 'The operator records the exchange rate; the amounts then appear by themselves'],
        'create_ad_groups' => ['أضف مجموعة إعلانية وإعلانًا في المنصة وفعّلهما', 'Add and activate an ad group and an ad at the provider'],
        'await_first_delivery' => ['لا إجراء في المنصة الآن؛ يظهر الرقم تلقائيًا عند أول صرف', 'Nothing to do at the provider; figures appear by themselves at first delivery'],
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

    /** @var array<string, string> currency code => Arabic name, for the sentence that says what is being waited for */
    private const CURRENCY_AR = [
        'EUR' => 'اليورو', 'USD' => 'الدولار', 'GBP' => 'الجنيه الإسترليني', 'SAR' => 'الريال',
        'AED' => 'الدرهم', 'KWD' => 'الدينار الكويتي', 'EGP' => 'الجنيه المصري', 'TRY' => 'الليرة',
    ];

    /**
     * KARAM-SYNC-HONESTY-001 — a state's label when its DETAIL says something the state's word cannot.
     *
     * «محجوبة» is true of a figure waiting for an exchange rate and of one waiting for a currency, and the
     * two are different things to do. The detail picks the sentence; the state keeps its tone.
     *
     * @param  array<string,mixed>  $extra
     * @return array{label_ar:string,label_en:string}|null
     */
    public static function detailLabel(string $dimension, ?string $detail, array $extra = []): ?array
    {
        if ($dimension !== 'freshness') {
            return null;
        }

        if ($detail === 'rate_missing') {
            $code = strtoupper((string) ($extra['withheld_currency'] ?? ''));
            $name = self::CURRENCY_AR[$code] ?? ($code !== '' ? $code : 'العملة');

            return [
                'label_ar' => "ينتظر سعر صرف {$name} — الإنفاق محجوب",
                'label_en' => 'Waiting for an exchange rate'.($code !== '' ? " ({$code})" : '').' — spend withheld',
            ];
        }

        if ($detail === 'no_ad_groups') {
            return [
                'label_ar' => 'الحملة بلا مجموعات إعلانية أو إعلانات — لا صرف بعد',
                'label_en' => 'The campaign has no ad groups or ads — no delivery yet',
            ];
        }

        if ($detail === 'no_delivery_yet') {
            return [
                'label_ar' => 'لا صرف بعد — المنصة لا تُرجع أرقامًا رغم اكتمال الحملة والإعلانات',
                'label_en' => 'No delivery yet — the provider returns no figures although the campaign and ads are in place',
            ];
        }

        return null;
    }

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
