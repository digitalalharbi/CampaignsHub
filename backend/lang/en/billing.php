<?php

declare(strict_types=1);

/* Plans, limits and payment answers, in English (I18N-001). Same keys as `lang/ar/billing.php`. */

return [
    'plan_limit_reached' => 'You have reached your plan limit for :metric (:used of :limit). Upgrade your plan to add more.',

    'metrics' => [
        'campaigns' => 'campaigns',
        'projects' => 'projects',
    ],

    'plan_not_available' => 'That plan is not available.',
    'plan_term_not_sold' => 'This plan is not sold on that term.',
    'no_payment_due' => 'This application is not waiting on a payment.',
];
