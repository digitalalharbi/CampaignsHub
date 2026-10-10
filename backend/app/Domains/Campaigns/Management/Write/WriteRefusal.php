<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

/**
 * Why an action is not offered — said as one of a closed set, so the interface can name it.
 *
 * PROVIDER_UNSUPPORTED is a fact about the platform (Google has no copy endpoint; no platform lets
 * an objective change after creation). NOT_IMPLEMENTED is a fact about CampaignsHub. The two are
 * never collapsed: telling an operator «the platform does not allow this» when it is our gap would
 * send them to the platform's own interface for something we simply have not built.
 */
final class WriteRefusal
{
    public const PROVIDER_UNSUPPORTED = 'provider_unsupported';

    public const NOT_IMPLEMENTED = 'not_implemented';

    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    public const NOT_CONNECTED = 'not_connected';

    public const ACCOUNT_NOT_SELECTED = 'account_not_selected';

    public const NO_PERMISSION = 'no_permission';
}
