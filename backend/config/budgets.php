<?php

declare(strict_types=1);

return [

    /*
     * BUDGET-LIMIT-ALERTS-001 — who is mailed, besides the people in the system, when a spend limit passes 75%, 90% or 100%.
     * One address (or a comma-separated list). The in-system alert is raised regardless of this value; an empty value
     * turns the extra mail off without touching the alert.
     */
    'limit_alert_email' => env('BUDGET_LIMIT_ALERT_EMAIL', 'info@campaignshub.io'),

];
