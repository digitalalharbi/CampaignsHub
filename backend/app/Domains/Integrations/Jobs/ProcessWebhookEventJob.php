<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Webhooks\WebhookEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * INTEG-WEBHOOK-ENGINE-001 — works one recorded delivery.
 *
 * Bounded: five attempts with a growing wait (30 s, 2 min, 10 min, 30 min). A transient provider failure
 * has time to clear; a permanent one ends in `dead_letter` on the row rather than retrying for ever or
 * vanishing. Carries the event id only, so every attempt reads current state.
 */
final class ProcessWebhookEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function uniqueId(): string
    {
        return 'webhook-event:'.$this->eventId;
    }

    public function __construct(public readonly string $eventId)
    {
        // PERF-SYNC-ISOLATION-001 — provider work runs on the serialised sync queue, never beside a user's request.
        $this->onQueue('sync');
    }

    public function handle(WebhookEventProcessor $processor): void
    {
        $processor->process($this->eventId);
    }

    public function failed(Throwable $e): void
    {
        app(WebhookEventProcessor::class)->deadLetter($this->eventId, $e->getMessage());
    }
}
