<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * NOTIF-PUSH-001 — the pushes for one notification, off the sweep that raised it.
 *
 * Holds the id, not the model: the row is re-read when the job runs, so a notification closed in between is seen as closed.
 * It never throws into a caller — a push that cannot be sent is logged by the dispatcher and is not a reason to retry the bell.
 */
final class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $notificationId) {}

    public function handle(PushDispatcher $dispatcher): void
    {
        try {
            $n = AppNotification::withoutGlobalScopes()->find($this->notificationId);
            if ($n !== null) {
                $dispatcher->send($n);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
