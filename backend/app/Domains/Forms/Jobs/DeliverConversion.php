<?php

declare(strict_types=1);

namespace App\Domains\Forms\Jobs;

use App\Domains\Forms\Conversions\ConversionTransport;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * FORMS-DISPATCH-001 — sends one queued conversion, retrying what can succeed later and recording what cannot.
 *
 * The job carries an id and nothing else: the payload, the destination and its secret are read when it RUNS, so a
 * destination switched off or edited after the job was queued is honoured, and no identifier or secret sits in the
 * queue's own storage where every job is visible.
 *
 * Retries back off 1 min, 5 min, 15 min, 1 h. A conversion older than 72 hours is refused rather than sent: GA4 and
 * Meta both drop or mis-date an event that old, and reporting a conversion the platform will ignore is worse than
 * saying plainly that it was too late.
 */
final class DeliverConversion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(ConversionTransport $transport): void
    {
        $delivery = ConversionDelivery::withoutGlobalScopes()->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== ConversionDelivery::QUEUED) {
            return;
        }

        $destination = ConversionDestination::withoutGlobalScopes()->find($delivery->conversion_destination_id);
        if ($destination === null || $destination->status !== ConversionDestination::LIVE) {
            $this->settle($delivery, ConversionDelivery::FAILED, null, 'The destination is not live, so nothing was sent.');

            return;
        }

        if ($delivery->created_at !== null && $delivery->created_at->lt(Carbon::now()->subHours(72))) {
            $this->settle($delivery, ConversionDelivery::FAILED, null, 'The conversion is older than 72 hours; the platforms ignore events that old.');

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();
        $result = $transport->send($destination, (array) $delivery->payload);

        if ($result['ok']) {
            $delivery->forceFill(['status' => ConversionDelivery::SENT, 'response_code' => $result['code'], 'error' => null, 'sent_at' => Carbon::now(), 'next_attempt_at' => null])->save();

            return;
        }

        if ($result['retryable'] && $this->attempts() < $this->tries) {
            $wait = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)];
            $delivery->forceFill(['response_code' => $result['code'], 'error' => $result['error'], 'next_attempt_at' => Carbon::now()->addSeconds($wait)])->save();
            $this->release($wait);

            return;
        }

        $this->settle($delivery, ConversionDelivery::FAILED, $result['code'], (string) $result['error']);
    }

    private function settle(ConversionDelivery $delivery, string $status, ?int $code, string $error): void
    {
        $delivery->forceFill(['status' => $status, 'response_code' => $code, 'error' => mb_substr($error, 0, 300), 'next_attempt_at' => null])->save();
    }
}
