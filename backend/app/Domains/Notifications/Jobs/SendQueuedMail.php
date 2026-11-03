<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Services\TransactionalMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One queued account notice — EMAIL-DELIVERY-LEDGER-001.
 *
 * The retry is a release rather than a throw: a thrown exception would reach the caller on the `sync`
 * queue, and a message about a role change must never be able to undo the role change. The ledger row,
 * not this job, decides whether anything is sent, so a redelivered job is harmless.
 */
final class SendQueuedMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Above the ledger's own ceiling, so the ledger stops the retries and records why. */
    public int $tries = 5;

    public function __construct(
        public readonly string $deliveryId,
        public readonly Mailable $mail,
    ) {}

    public function handle(TransactionalMailer $mailer): void
    {
        if ($mailer->deliverQueued($this->deliveryId, $this->mail) === 'retry') {
            $this->release(60 * (TransactionalMailer::BACKOFF_MINUTES[max(0, $this->attempts() - 1)] ?? 15));
        }
    }

    public function failed(Throwable $e): void
    {
        app(TransactionalMailer::class)->abandon($this->deliveryId, $e);
    }
}
