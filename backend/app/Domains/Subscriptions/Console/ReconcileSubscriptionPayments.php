<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\ApplySubscriptionPaymentEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * PAY-RECONCILE-001 — ask the gateway about pending payments whose webhook never settled them.
 *
 * Younger than ten minutes is the webhook's to settle; older than seven days is past any checkout a customer is still completing.
 * It opens nothing and charges nothing: a gateway that is not configured is not asked.
 */
final class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'subscriptions:reconcile-payments';

    protected $description = 'Settle pending payments the gateway reports paid when their webhook never arrived.';

    public function handle(ApplySubscriptionPaymentEvent $events): int
    {
        $settled = 0;

        SubscriptionPayment::withoutGlobalScopes()
            ->where('status', 'pending')
            ->whereNotNull('provider_session_id')
            ->whereBetween('created_at', [Carbon::now()->subDays(7), Carbon::now()->subMinutes(10)])
            ->orderBy('created_at')
            ->each(function (SubscriptionPayment $payment) use ($events, &$settled): void {
                if ($events->reconcile($payment)) {
                    $settled++;
                }
            });

        app(ScheduledRunRows::class)->report($settled);
        $this->line("Settled {$settled} payment(s) the gateway reports paid.");

        return self::SUCCESS;
    }
}
