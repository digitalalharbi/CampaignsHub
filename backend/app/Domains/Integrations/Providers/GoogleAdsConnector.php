<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Contracts\AwaitingCredentialsConnector;

/**
 * Google Ads API connector. Awaiting credentials/app approval — see
 * INTEGRATION_CREDENTIALS_CHECKLIST.md. Wire real OAuth + SDK calls once provisioned.
 */
final class GoogleAdsConnector extends AwaitingCredentialsConnector
{
    public function key(): string
    {
        return 'google_ads';
    }

    public function label(): string
    {
        return 'Google Ads API';
    }
}
