<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Requests\Models\ExternalRequest;

/**
 * In-app notifications for request lifecycle events. Email delivery stays "Awaiting Mail Credentials"
 * (a queue job + delivery log would carry it once configured) — we never claim an email was sent.
 */
final class RequestNotifier
{
    public function notify(ExternalRequest $request, string $type, string $title, ?string $message = null, ?int $userId = null): void
    {
        AppNotification::create([
            'tenant_id' => $request->tenant_id,
            'user_id' => $userId ?? $request->assigned_to,
            'type' => $type,
            'severity' => str_contains($type, 'breach') ? 'critical' : (str_contains($type, 'warning') ? 'warning' : 'info'),
            'title' => $title,
            'message' => $message,
            'source' => 'requests',
            'entity_type' => 'external_request',
            'entity_id' => $request->id,
            'action_url' => "/app/requests/{$request->id}",
            'status' => 'unread',
        ]);
    }
}
