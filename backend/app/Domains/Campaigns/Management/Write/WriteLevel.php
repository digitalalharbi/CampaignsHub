<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * CAMPAIGN-MGMT-WRITE-001 — the three rungs a provider write can address.
 *
 * Named by the product's own words (ad set), not any one platform's (ad squad, ad group): the adapter
 * translates, so a surface never has to know that Snapchat calls the middle rung something else.
 */
enum WriteLevel: string
{
    case Campaign = 'campaign';
    case AdSet = 'ad_set';
    case Ad = 'ad';
}
