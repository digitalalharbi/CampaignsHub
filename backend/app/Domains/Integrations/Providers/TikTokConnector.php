<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * TikTok Marketing API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class TikTokConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok Marketing API';
    }
}
