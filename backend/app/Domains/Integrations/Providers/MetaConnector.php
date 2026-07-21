<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * Meta Marketing API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class MetaConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Meta Marketing API';
    }
}
