<?php

declare(strict_types=1);

/* Plans, limits and payment answers, in English (I18N-001). Same keys as `lang/ar/billing.php`. */

return [
    'plan_limit_reached' => 'You have reached your plan limit for :metric (:used of :limit). Upgrade your plan to add more.',

    // RUNTIME-100 §10 — how many MORE they may choose is the number that lets them act.
    'ad_accounts_selection_exceeds_plan' => 'You selected :requested accounts and your plan has room for :remaining more (limit :limit). Reduce the selection or upgrade your plan.',
    'ad_account_replacements_used' => 'You have used your plan\'s replacement allowance (:allowance every 30 days). You can connect a different account after :date, or upgrade your plan. Reconnecting the same accounts is always free.',

    'metrics' => [
        'campaigns' => 'campaigns',
        'projects' => 'projects',
    ],

    'plan_not_available' => 'That plan is not available.',
    'plan_term_not_sold' => 'This plan is not sold on that term.',
    'no_payment_due' => 'This application is not waiting on a payment.',
];
