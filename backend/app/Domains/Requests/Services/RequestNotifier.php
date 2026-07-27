<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Requests\Models\ExternalRequest;

/**
 * In-app notifications for request lifecycle events, now routed through the central NotificationDispatcher
 * (dedup + preferences + quiet hours + per-channel delivery log). Email delivery stays "awaiting_credentials"
 * until a real mail provider is wired — we never claim an email was sent.
 */
final class RequestNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function notify(ExternalRequest $request, string $type, string $title, ?string $message = null, ?int $userId = null): ?AppNotification
    {
        return $this->dispatcher->dispatch([
            'tenant_id' => $request->tenant_id,
            'user_id' => $userId ?? $request->assigned_to,
            'client_workspace_id' => $request->client_id,
            'type' => $type,
            'severity' => str_contains($type, 'breach') ? 'critical' : (str_contains($type, 'warning') ? 'warning' : 'info'),
            'title' => $title,
            'message' => $message,
            'source' => 'requests',
            'entity_type' => 'external_request',
            'entity_id' => $request->id,
            'action_url' => "/app/requests/{$request->id}",
        ]);
    }
}
