<?php

declare(strict_types=1);

/** The Connection Center's own words — see the Arabic file for why the lifecycle labels differ. */
return [
    'account_unnamed' => 'Unnamed :provider account',

    'account_type' => [
        'ad_account' => 'ad account',
        'store' => 'store',
        'organization' => 'organisation',
        'business' => 'business account',
        'page' => 'page',
    ],

    'lifecycle' => [
        'discovered' => 'Discovered',
        'enabled' => 'Enabled',
        'excluded' => 'Excluded',
        'assigned' => 'Assigned to a project',
    ],

    'lifecycle_hint' => [
        'discovered' => 'The provider returned it with your authorisation. Nothing has been chosen, and no data is pulled.',
        'enabled' => 'You have claimed it. No data is pulled until it is assigned to a project.',
        'excluded' => 'You removed it from the list. It is kept so it does not return with every refresh.',
        'assigned' => 'A project owns it, and its data appears in that project’s reporting.',
    ],

    'exclude_assigned' => 'An account assigned to a project cannot be excluded. Detach it from the project first.',
    'backfill_unassigned' => 'History cannot be pulled for an account no project owns. Assign it to a project first.',
    'backfill_window_invalid' => 'That window is not valid: the start date must come before the end date.',
    'backfill_window_too_long' => 'History is pulled at most :days days per request.',
    'connection_not_authorized' => 'This connection is no longer authorised. Reconnect it and try again.',
];
