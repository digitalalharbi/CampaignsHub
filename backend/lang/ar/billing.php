<?php

declare(strict_types=1);

/*
 * Plans, limits and payment answers, in Arabic (I18N-001).
 *
 * Numbers stay in Latin digits: a customer comparing «3 من 3» against what they can see in their own
 * list can do it at a glance, and can paste it into a message to support.
 */

return [
    // PLAN-003 — the refusal carries the numbers, so «you have reached your limit» does not leave
    // somebody guessing what the limit was or how close they had been.
    'plan_limit_reached' => 'لقد بلغت حد باقتك من :metric (:used من :limit). قم بترقية باقتك لإضافة المزيد.',

    /*
     * RUNTIME-100 §10 — a BATCH refusal is a different sentence from a single one.
     *
     * «بلغت حدك» leaves somebody who ticked ten accounts to work out how many to untick. The number
     * they can still choose is the one thing they need, so it is the thing that is said.
     */
    'ad_accounts_selection_exceeds_plan' => 'اخترت :requested حسابًا، وباقتك تسمح بـ:remaining حساب إضافي فقط (الحد :limit). قلّل الاختيار أو رقِّ باقتك.',
    'addon_not_available' => 'هذه الإضافة غير متاحة للطلب بعد.',
    'addon_already_requested' => 'طلبك لهذه الإضافة قيد المراجعة.',
    'addon_requested' => 'استلمنا طلبك. لن يُخصم أي مبلغ ولن تُفعَّل الإضافة قبل تأكيدها.',
    'ad_account_replacements_used' => 'استخدمت حد الاستبدال في باقتك (:allowance كل 30 يومًا). يمكنك ربط حساب مختلف بعد :date، أو ترقية باقتك. إعادة ربط الحسابات نفسها مجانية دائمًا.',
    'migration_confirm_mismatch' => 'اكتب المعرّف المختصر لمساحة العمل كما هو لتأكيد النقل.',
    'migration_bulk_confirm_mismatch' => 'اكتب «MOVE :n» لتأكيد نقل :n مساحة عمل.',

    /*
     * The metric, named in Arabic.
     *
     * Falls back to the raw key for a metric added later — an untranslated «projects» inside an
     * Arabic sentence is poor, but better than a message that renders `billing.metrics.whatever`.
     */
    'metrics' => [
        'campaigns' => 'الحملات',
        'projects' => 'المشاريع',
        'clients' => 'العملاء',
        'team_members' => 'أعضاء الفريق',
        'ad_accounts' => 'الحسابات الإعلانية',
        'connections' => 'الربط',
        'reports_per_month' => 'تقارير هذا الشهر',
    ],

    'plan_not_available' => 'هذه الباقة غير متاحة.',
    'plan_term_not_sold' => 'هذه الباقة غير متاحة بهذه الدورة.',
    'no_payment_due' => 'لا يوجد دفع مستحق على هذا الطلب.',
    'usage_own_key_never_charged' => 'الاستهلاك على مفتاح العميل الخاص يُدفع لمزوّده ولا يُخصم من الرصيد أبدًا.',
    'usage_cost_required' => 'أدخل التكلفة الفعلية للوحدة قبل تفعيل الخصم من الرصيد لهذا العدّاد.',
    'credits_amount_invalid' => 'أدخل مبلغًا غير الصفر.',
    'credits_balance_negative' => 'هذا التعديل يجعل الرصيد أقل من الصفر.',
];
