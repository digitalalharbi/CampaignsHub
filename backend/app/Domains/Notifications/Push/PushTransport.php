<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

/** NOTIF-PUSH-001 — the one seam between us and a push service, so the rest is tested without one. */
interface PushTransport
{
    /** Is a VAPID key pair configured? With none, nothing is sent and the settings page says so. */
    public function configured(): bool;

    /**
     * Deliver one encrypted message to one device.
     *
     * @param  array{ttl?: int, urgency?: string, topic?: string}  $options
     */
    public function send(PushSubscription $subscription, string $payload, array $options = []): PushResult;
}
