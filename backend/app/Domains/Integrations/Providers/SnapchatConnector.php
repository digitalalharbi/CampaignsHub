<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * Snapchat Marketing API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class SnapchatConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'snapchat';
    }

    public function label(): string
    {
        return 'Snapchat Marketing API';
    }
}
