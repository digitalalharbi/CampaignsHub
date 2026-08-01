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
     * The metric, named in Arabic.
     *
     * Falls back to the raw key for a metric added later — an untranslated «projects» inside an
     * Arabic sentence is poor, but better than a message that renders `billing.metrics.whatever`.
     */
    'metrics' => [
        'campaigns' => 'الحملات',
        'projects' => 'المشاريع',
    ],

    'plan_not_available' => 'هذه الباقة غير متاحة.',
    'plan_term_not_sold' => 'هذه الباقة غير متاحة بهذه الدورة.',
    'no_payment_due' => 'لا يوجد دفع مستحق على هذا الطلب.',
];
