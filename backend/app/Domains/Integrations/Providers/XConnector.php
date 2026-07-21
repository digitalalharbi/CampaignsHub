<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * X Ads API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class XConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'x';
    }

    public function label(): string
    {
        return 'X Ads API';
    }
}
