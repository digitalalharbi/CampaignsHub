<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Jobs;

use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Notifications\MailTransportState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The only thing that decides whether a message actually went anywhere (NOTIF-SUB-001).
 *
 * Three outcomes, kept apart because all three look like success from the caller's side and only one
 * of them means a person received something:
 *
 *   - **awaiting_credentials** — no transport is configured at all. Nothing was sent.
 *   - **sandbox** — a transport IS configured but it is a local one (`log`, `array`). Something
 *     happened; it did not leave the machine.
 *   - **sent** — a real transport accepted it.
 *
 * The distinction is the whole point of the ledger. A system that recorded all three as "sent" would
 * be reporting a delivery rate that means nothing, and the first anybody would know is a customer
 * saying they were never told.
 */
final class SendSubscriptionNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Mail throttling is the reason this exists.
     *
     * SES and every SMTP relay answer a burst with a temporary refusal, and three immediate attempts
     * against one is not a retry policy — it is the same rejection three times inside a second,
     * spending the attempts while the limiter is still counting, and turning a transient 421 into a
     * subscription notice the customer never receives. The ladder is minutes rather than seconds
     * because that is the timescale a sending limit resets on, and because nothing about a renewal
     * notice needs to arrive in the next ten seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(private readonly string $notificationId) {}

    public function handle(): void
    {
        $notification = SubscriptionNotification::query()->find($this->notificationId);

        if ($notification === null || $notification->status === 'sent') {
            // Already delivered, or the row is gone. Re-sending on a queue retry would mean a customer
            // receives the same message twice for no reason.
            return;
        }

        $attempts = $notification->attempts + 1;

        /*
         * Asked once, of the thing that knows.
         *
         * Not `config('mail.default') === 'log'`: Laravel ships `smtp` and `ses` in `mail.mailers`
         * whether or not anybody supplied keys, so the presence of a driver proves nothing. See
         * MailTransportState — it asks each driver for the credential it cannot work without.
         */
        $state = MailTransportState::current();

        // No credentials: the honest state, and the one this install ships in.
        if ($state === MailTransportState::AWAITING_CREDENTIALS) {
            $notification->forceFill([
                'status' => 'awaiting_credentials',
                'attempts' => $attempts,
                'error' => null,
            ])->save();

            return;
        }

        if ($state === MailTransportState::SANDBOX) {
            /*
             * A configured but local transport. The message IS written — to the log, or to an array a
             * test can read — so the journey is walkable end to end. It is not called `sent`, because
             * nobody received it.
             */
            $this->deliver($notification);

            $notification->forceFill([
                'status' => 'sandbox',
                'attempts' => $attempts,
                'sent_at' => now(),
                'error' => null,
            ])->save();

            return;
        }

        try {
            $this->deliver($notification);

            $notification->forceFill([
                'status' => 'sent',
                'attempts' => $attempts,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            /*
             * Recorded as failed, then re-thrown so the queue's own retry applies.
             *
             * Swallowing it would leave a row saying `failed` that nothing will ever try again, which
             * is indistinguishable from a message we decided not to send.
             */
            $notification->forceFill([
                'status' => 'failed',
                'attempts' => $attempts,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }
    }

    /**
     * Plain text on purpose.
     *
     * These are transactional messages about money and access; the body was rendered and stored at
     * dispatch, and re-rendering it through a view here would reintroduce exactly the drift the stored
     * body exists to prevent.
     */
    private function deliver(SubscriptionNotification $notification): void
    {
        Mail::raw($notification->body, function ($message) use ($notification): void {
            $message->to($notification->to_email)->subject($notification->subject);
        });
    }
}
