<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use Illuminate\Support\Carbon;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the one place a webhook turns into a read.
 *
 * It is the SAME syncer the scheduler runs, over a short window; a webhook never becomes a second path by
 * which numbers arrive (see `IntegrationWebhookController`). The store window is wider than the ad one
 * because a store event is frequently about an OLDER order: a refund on a three-week-old purchase is a
 * delivery today.
 *
 * Not final, so a test can stand a recording runner in for the two final syncers.
 */
class WebhookSyncRunner
{
    private const COMMERCE_WINDOW_DAYS = 30;

    private const ADS_WINDOW_DAYS = 2;

    public function __construct(
        private readonly StoreSyncer $stores,
        private readonly AccountMetricsSyncer $metrics,
    ) {}

    /** @return object{status: mixed, error?: mixed}|null the run the syncer recorded, or null when the kind has no webhook sync */
    public function run(ExternalAccount $account, ProviderKind $kind, IntegrationWebhookEvent $event): ?object
    {
        $meta = ['source' => 'webhook', 'topic' => $event->topic, 'webhook_event_id' => (string) $event->getKey()];

        return match ($kind) {
            ProviderKind::Commerce => $this->stores->sync(
                $account,
                Carbon::now()->subDays(self::COMMERCE_WINDOW_DAYS),
                Carbon::now(),
                $meta,
            ),
            ProviderKind::Advertising => $this->metrics->sync(
                $account,
                Carbon::now()->subDays(self::ADS_WINDOW_DAYS),
                Carbon::now(),
                $meta + ['queued_at' => Carbon::parse($event->received_at)->toIso8601String()],
            ),
            // A measurement source has no webhook to arrive from (GA4 is polling only).
            ProviderKind::Measurement => null,
        };
    }
}
