<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * LinkedIn Marketing API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class LinkedInConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn Marketing API';
    }
}
