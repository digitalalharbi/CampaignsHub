<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * INTEG-WEBHOOK-ENGINE-001 — an operator re-runs ONE failed or dead-lettered delivery.
 *
 * It does not invent a new path: the row goes back to `queued` and the same idempotent handler runs.
 * Replaying a delivery that is already processed (or ignored, superseded, rejected, or still in
 * flight) changes nothing and says so — which is also what makes a double click harmless. The claim is
 * a conditional UPDATE, so two operators racing re-queue it once.
 *
 * Salla's app lifecycle events (`app.*`) are not replayed: their handlers hand over and withdraw
 * tokens, and running them again is a decision about the merchant's installation, not a retry.
 */
final class WebhookReplayer
{
    public function __construct(
        private readonly WebhookEventProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{outcome: string, status: string} */
    public function replay(IntegrationWebhookEvent $event, ?int $userId): array
    {
        $replayable = in_array($event->status, [IntegrationWebhookEvent::FAILED, IntegrationWebhookEvent::DEAD_LETTER], true);

        if (! $replayable) {
            return ['outcome' => 'noop', 'status' => $event->status];
        }

        if ($event->payload === null || $this->processor->isLifecycle($event)) {
            return ['outcome' => 'not_replayable', 'status' => $event->status];
        }

        $before = $event->status;

        $claimed = DB::transaction(static fn (): int => IntegrationWebhookEvent::query()
            ->whereKey($event->getKey())
            ->whereIn('status', [IntegrationWebhookEvent::FAILED, IntegrationWebhookEvent::DEAD_LETTER])
            ->update([
                'status' => IntegrationWebhookEvent::QUEUED,
                'error' => null,
                'dead_lettered_at' => null,
                'replayed_count' => DB::raw('replayed_count + 1'),
            ]));

        if ($claimed === 0) {
            return ['outcome' => 'noop', 'status' => (string) $event->fresh()?->status];
        }

        $this->audit->log(
            'webhook.event.replayed',
            'integration_webhook_event',
            (string) $event->getKey(),
            ['status' => $before],
            ['status' => IntegrationWebhookEvent::QUEUED],
            null,
            $userId,
            $event->tenant_id,
        );

        ProcessWebhookEventJob::dispatch((string) $event->getKey());

        return ['outcome' => 'requeued', 'status' => IntegrationWebhookEvent::QUEUED];
    }
}
