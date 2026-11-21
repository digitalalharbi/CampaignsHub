<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use Illuminate\Support\Carbon;

/**
 * «Something happened» — what a domain hands the engine (spec §3: one event → several deliveries).
 *
 * The de-dup identity is event + object + threshold + day (+ recipient + channel, added by the engine per delivery).
 * `payload` carries the facts the templates need; it must never carry another workspace's data.
 */
final class DeliveryIntent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventKey,
        public readonly string $tenantId,
        public readonly ?string $projectId = null,
        public readonly ?string $objectType = null,
        public readonly ?string $objectId = null,
        public readonly ?string $threshold = null,
        public readonly array $payload = [],
        public readonly ?Carbon $occurredAt = null,
        public readonly ?int $actorUserId = null,
        public readonly ?string $subscriptionId = null,
        public readonly int $contentVersion = 1,
    ) {}

    public function at(): Carbon
    {
        return $this->occurredAt ?? Carbon::now();
    }

    /** The de-dup base: event + object + threshold + day (in the workspace timezone). */
    public function dedupBase(string $timezone): string
    {
        return implode('|', [
            $this->eventKey,
            $this->objectType ?? 'project',
            $this->objectId ?? $this->projectId ?? '-',
            $this->threshold ?? '-',
            $this->at()->copy()->setTimezone($timezone)->toDateString(),
        ]);
    }
}
