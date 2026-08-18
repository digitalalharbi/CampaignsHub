<?php

declare(strict_types=1);

/** The integrations surface's own words. */
return [
    'account_unnamed' => 'Unnamed :provider account',

    'account_type' => [
        'ad_account' => 'ad account',
        'store' => 'store',
        'organization' => 'organisation',
        'business' => 'business account',
        'page' => 'page',
    ],

    'backfill_unassigned' => 'History cannot be pulled for an account no project owns. Assign it to a project first.',
    'backfill_window_invalid' => 'That window is not valid: the start date must come before the end date.',
    'backfill_window_too_long' => 'History is pulled at most :days days per request.',
    'connection_not_authorized' => 'This connection is no longer authorised. Reconnect it and try again.',
];
