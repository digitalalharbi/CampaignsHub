<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationSendLog;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Platform\Models\PlatformAlert;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — reads the payment and sign-up records that already exist and says what is stuck.
 *
 * It writes nothing and asks no gateway anything: every fact comes from a row this product already keeps —
 * `subscription_payments` (written only by `ApplySubscriptionPaymentEvent`), the verified webhook ledger
 * `payment_webhook_events`, the audit trail that payment path writes, `subscription_invoices`, `subscriptions`,
 * `registration_requests` with their verification challenges and send log, and the mail ledger. There is no
 * parallel payment model and no inference: where the record does not say, the alert does not say either.
 *
 * Each candidate carries a `dedup_key` that names the ATTEMPT (a payment id, an invoice id, a registration and
 * channel), so the same attempt can only ever be one alert.
 *
 * @phpstan-type Candidate array{kind: string, severity: string, dedup_key: string, subject_type: ?string, subject_id: ?string, subject_tenant_id: ?string, facts: array<string,mixed>}
 */
final class PaymentAlertDetector
{
    /** The audit actions the payment path writes when a gateway's word did not settle a charge. */
    public const VERIFICATION_AUDITS = [
        'subscription.payment.unconfirmed',
        'subscription.payment.event_contradicted',
        'subscription.payment.reference_mismatch',
        'subscription.payment.amount_mismatch',
    ];

    /** Moyasar's own words for a charge the customer completed. */
    private const GATEWAY_PAID = ['paid', 'captured'];

    /** Delivery outcomes that mean the code or link never left for the customer. */
    private const NOT_DELIVERED = ['failed', 'awaiting_provider_credentials', 'suppressed', RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::BLOCKED_APPLICATION];

    /** The states in which an applicant is still waiting on a verification code. */
    private const VERIFYING = [AccountState::EmailVerificationRequired, AccountState::MobileVerificationRequired, AccountState::Draft];

    public function __construct(private readonly SettlementSource $settlements) {}

    /** @return list<Candidate> */
    public function detect(Carbon $now): array
    {
        return [
            ...$this->abandonedCheckouts($now),
            ...$this->unverifiedPayments($now),
            ...$this->activationMismatches($now),
            ...$this->unsettledPayments($now),
            ...$this->blockingErrors($now),
        ];
    }

    public function settlementCheckAvailable(): bool
    {
        return $this->settlements->isAvailable();
    }

    /**
     * Is what this alert reported no longer true? Only the payment kinds can clear on their own; a grouped
     * technical error stays until the owner marks it handled.
     */
    public function hasCleared(PlatformAlert $alert): bool
    {
        if (! in_array($alert->kind, [PlatformAlert::ABANDONED, PlatformAlert::UNVERIFIED, PlatformAlert::MISMATCH], true)) {
            return false;
        }

        $facts = $alert->facts;

        if ($alert->kind === PlatformAlert::MISMATCH && ($facts['variant'] ?? null) === 'invoice_paid_without_payment') {
            $invoice = SubscriptionInvoice::query()->find((string) $alert->subject_id);
            $payment = $invoice?->subscription_payment_id === null ? null : SubscriptionPayment::query()->find($invoice->subscription_payment_id);

            return $invoice === null || $invoice->status !== 'paid' || ($payment !== null && $payment->isSettled());
        }

        $payment = SubscriptionPayment::query()->find((string) ($facts['payment_id'] ?? ''));
        if ($payment === null) {
            return false;
        }

        return match ($alert->kind) {
            PlatformAlert::ABANDONED => $payment->status !== 'pending',
            PlatformAlert::UNVERIFIED => $payment->status === 'paid',
            default => $this->mismatchOf($payment) === null,
        };
    }

    // ── 1. Reached the payment step, never completed ─────────────────────────────────────────

    /** @return list<Candidate> */
    private function abandonedCheckouts(Carbon $now): array
    {
        $window = $this->minutes('abandoned_after_minutes', 30);
        $out = [];

        SubscriptionPayment::query()
            ->where('status', 'pending')
            // A hosted payment page was opened for the customer: that is «reached the payment step».
            ->whereNotNull('checkout_url')
            ->whereBetween('created_at', [$this->since($now), $now->copy()->subMinutes($window)])
            ->orderBy('created_at')
            ->each(function (SubscriptionPayment $payment) use (&$out, $window): void {
                // A verified gateway event about it means the customer DID pay or try to: that is alert 2 or 5, not this.
                if ($this->gatewayEvent($payment) !== null) {
                    return;
                }

                $out[] = $this->candidate(PlatformAlert::ABANDONED, 'warning', 'abandoned:'.$payment->getKey(), $payment, $this->paymentFacts($payment) + [
                    'window_minutes' => $window,
                    'last_step' => 'checkout',
                    'funnel' => $this->funnel($payment),
                ]);
            });

        return $out;
    }

    // ── 2. The gateway took it, the platform has not confirmed it ─────────────────────────────

    /** @return list<Candidate> */
    private function unverifiedPayments(Carbon $now): array
    {
        $window = $this->minutes('unverified_after_minutes', 30);
        $cutoff = $now->copy()->subMinutes($window);
        $seen = [];
        $out = [];

        $raise = function (SubscriptionPayment $payment, ?array $event) use (&$seen, &$out, $window): void {
            if (isset($seen[$payment->getKey()]) || $payment->status === 'paid' || $payment->status === 'refund_due') {
                return;
            }
            $seen[$payment->getKey()] = true;
            $audit = $this->latestVerificationAudit($payment);

            $out[] = $this->candidate(PlatformAlert::UNVERIFIED, 'critical', 'unverified:'.$payment->getKey(), $payment, $this->paymentFacts($payment) + [
                'window_minutes' => $window,
                'our_status' => (string) $payment->status,
                'gateway_status' => $event['status'] ?? null,
                'gateway_event_type' => $event['type'] ?? null,
                'gateway_event_at' => $event['at'] ?? null,
                'audit' => $audit,
            ]);
        };

        // a. The gateway told us «paid» (or «captured») in a verified event, and the payment is still not paid here.
        PaymentWebhookEvent::withoutGlobalScopes()
            ->where('verified', true)
            ->whereBetween('created_at', [$this->since($now), $cutoff])
            ->orderBy('created_at')
            ->each(function (PaymentWebhookEvent $event) use ($raise): void {
                $data = $this->eventData($event);
                if (! in_array($data['status'] ?? null, self::GATEWAY_PAID, true)) {
                    return;
                }
                $payment = $this->paymentForEvent((string) $event->provider, $data);
                if ($payment !== null) {
                    $raise($payment, $this->describeEvent($event));
                }
            });

        // b. The gateway's last word was «initiated» / «authorized» and nothing has moved it since.
        SubscriptionPayment::query()
            ->where('status', 'processing')
            ->whereBetween('updated_at', [$this->since($now), $cutoff])
            ->orderBy('updated_at')
            ->each(fn (SubscriptionPayment $p) => $raise($p, $this->gatewayEvent($p)));

        // c. The payment path refused a paid event: the gateway could not be asked, or disagreed with it.
        AuditLog::query()
            ->whereIn('action', self::VERIFICATION_AUDITS)
            ->where('entity_type', SubscriptionPayment::class)
            ->whereBetween('created_at', [$this->since($now), $cutoff])
            ->orderBy('created_at')
            ->each(function (AuditLog $log) use ($raise): void {
                $payment = SubscriptionPayment::query()->find((string) $log->entity_id);
                if ($payment !== null) {
                    $raise($payment, $this->gatewayEvent($payment));
                }
            });

        return $out;
    }

    // ── 3. Money and activation disagree ─────────────────────────────────────────────────────

    /** @return list<Candidate> */
    private function activationMismatches(Carbon $now): array
    {
        $grace = $this->minutes('activation_grace_minutes', 15);
        $cutoff = $now->copy()->subMinutes($grace);
        $out = [];

        // a. Paid at the gateway, owed back or not activated here.
        SubscriptionPayment::query()
            ->whereIn('status', ['paid', 'refund_due'])
            ->whereNull('refunded_at')
            ->whereBetween('updated_at', [$this->since($now), $cutoff])
            ->orderBy('updated_at')
            ->each(function (SubscriptionPayment $payment) use (&$out, $cutoff, $grace): void {
                // The grace runs from when the money was confirmed, not from when the row last changed.
                if ($payment->paid_at !== null && $payment->paid_at->greaterThan($cutoff)) {
                    return;
                }
                $mismatch = $this->mismatchOf($payment);
                if ($mismatch === null) {
                    return;
                }
                $out[] = $this->candidate(PlatformAlert::MISMATCH, 'critical', 'mismatch:'.$mismatch['variant'].':'.$payment->getKey(), $payment, $this->paymentFacts($payment) + $mismatch + [
                    'grace_minutes' => $grace,
                    'payment_status' => (string) $payment->status,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ]);
            });

        // b. A document says «paid» while the payment behind it is not a settled charge.
        SubscriptionInvoice::query()
            ->where('status', 'paid')
            ->whereNotNull('subscription_payment_id')
            ->whereBetween('paid_at', [$this->since($now), $cutoff])
            ->orderBy('paid_at')
            ->each(function (SubscriptionInvoice $invoice) use (&$out): void {
                $payment = SubscriptionPayment::query()->find((string) $invoice->subscription_payment_id);
                if ($payment !== null && $payment->isSettled()) {
                    return;
                }
                $facts = ($payment === null ? [] : $this->paymentFacts($payment)) + [
                    'variant' => 'invoice_paid_without_payment',
                    'invoice_id' => (string) $invoice->getKey(),
                    'invoice_number' => $invoice->number,
                    'invoice_paid_at' => $invoice->paid_at?->toIso8601String(),
                    'payment_status' => $payment?->status,
                ];
                $out[] = [
                    'kind' => PlatformAlert::MISMATCH,
                    'severity' => 'critical',
                    'dedup_key' => 'mismatch:invoice_paid_without_payment:'.$invoice->getKey(),
                    'subject_type' => 'subscription_invoice',
                    'subject_id' => (string) $invoice->getKey(),
                    'subject_tenant_id' => $invoice->tenant_id === null ? null : (string) $invoice->tenant_id,
                    'facts' => $facts + ['who' => $facts['who'] ?? $this->tenantName($invoice->tenant_id === null ? null : (string) $invoice->tenant_id)],
                ];
            });

        return $out;
    }

    /**
     * What, if anything, is wrong between this confirmed payment and what it should have activated.
     *
     * @return array{variant: string, invoice_id?: string, invoice_number?: ?string, invoice_status?: string, recorded_error?: ?string}|null
     */
    private function mismatchOf(SubscriptionPayment $payment): ?array
    {
        if ($payment->status === 'refund_due') {
            // The payment path refused to activate it and recorded why; the money is owed back by a person.
            return ['variant' => 'refund_due', 'recorded_error' => $payment->error];
        }

        if (! $payment->isSettled()) {
            return null;
        }

        $invoice = SubscriptionInvoice::query()->where('subscription_payment_id', $payment->getKey())->first();
        if ($invoice !== null && ! in_array($invoice->status, ['paid', 'void'], true)) {
            return ['variant' => 'invoice_unpaid', 'invoice_id' => (string) $invoice->getKey(), 'invoice_number' => $invoice->number, 'invoice_status' => (string) $invoice->status];
        }

        if ($payment->registration_request_id !== null) {
            $registration = RegistrationRequest::query()->find($payment->registration_request_id);
            if ($registration === null) {
                return null;
            }
            if ($registration->tenant_id === null) {
                return ['variant' => 'not_provisioned'];
            }
            if (! $this->hasLiveSubscription((string) $registration->tenant_id)) {
                return ['variant' => 'no_active_subscription'];
            }
        }

        return null;
    }

    // ── 4. Confirmed, but not in a settlement ────────────────────────────────────────────────

    /** @return list<Candidate> */
    private function unsettledPayments(Carbon $now): array
    {
        if (! $this->settlements->isAvailable()) {
            return [];
        }

        $days = max(1, (int) config('platform_alerts.settlement_after_days', 4));
        $out = [];

        $byProvider = SubscriptionPayment::query()
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->whereNotNull('provider_payment_id')
            ->whereBetween('paid_at', [$now->copy()->subDays(max($days + 1, (int) config('platform_alerts.settlement_lookback_days', 30))), $now->copy()->subDays($days)])
            ->get()
            ->groupBy('provider');

        foreach ($byProvider as $provider => $payments) {
            $ids = $payments->pluck('provider_payment_id')->map(fn ($id): string => (string) $id)->values()->all();
            $settled = $this->settlements->settled((string) $provider, $ids);
            if ($settled === null) {
                // Could not read the source this time: that says nothing about the money.
                continue;
            }
            foreach ($payments as $payment) {
                if (in_array((string) $payment->provider_payment_id, $settled, true)) {
                    continue;
                }
                $out[] = $this->candidate(PlatformAlert::UNSETTLED, 'critical', 'unsettled:'.$payment->getKey(), $payment, $this->paymentFacts($payment) + [
                    'expected_days' => $days,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ]);
            }
        }

        return $out;
    }

    // ── 5. Technical errors that stop a customer ─────────────────────────────────────────────

    /**
     * One candidate per error type, carrying every affected customer as an item. The recorder merges the items into
     * the day's group and never repeats a customer already reported.
     *
     * @return list<Candidate>
     */
    private function blockingErrors(Carbon $now): array
    {
        $items = [
            'otp_not_delivered' => $this->codesNotDelivered($now),
            'otp_expired' => $this->codesExpired($now),
            'payment_error' => $this->paymentErrors($now),
            'invitation_failed' => $this->invitationFailures($now),
        ];

        $day = $now->copy()->setTimezone($this->zone())->toDateString();
        $out = [];

        foreach ($items as $type => $list) {
            if ($list === []) {
                continue;
            }
            $out[] = [
                'kind' => PlatformAlert::BLOCKING,
                'severity' => 'warning',
                'dedup_key' => 'blocking:'.$type.':'.$day,
                'subject_type' => null,
                'subject_id' => null,
                'subject_tenant_id' => null,
                'facts' => ['type' => $type, 'day' => $day, 'items' => $list],
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function codesNotDelivered(Carbon $now): array
    {
        $items = [];

        RegistrationVerification::query()
            ->whereIn('delivery_status', self::NOT_DELIVERED)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', $this->since($now))
            ->orderBy('created_at')
            ->each(function (RegistrationVerification $v) use (&$items): void {
                $registration = RegistrationRequest::query()->find($v->registration_request_id);
                if ($registration === null || ! $this->stillUnverified($registration, (string) $v->channel)) {
                    return;
                }
                $items['reg:'.$registration->getKey().':'.$v->channel] = $this->registrationItem($registration, (string) $v->channel, (string) $v->delivery_status, $v->created_at);
            });

        // Refused before any code was made: the send cap. The log row is the only trace.
        RegistrationSendLog::query()
            ->whereIn('outcome', [RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::BLOCKED_APPLICATION])
            ->whereNotNull('registration_request_id')
            ->where('created_at', '>=', $this->since($now))
            ->orderBy('created_at')
            ->each(function (RegistrationSendLog $log) use (&$items): void {
                $registration = RegistrationRequest::query()->find($log->registration_request_id);
                $key = 'reg:'.$log->registration_request_id.':'.$log->channel;
                if ($registration === null || isset($items[$key]) || ! $this->stillUnverified($registration, (string) $log->channel)) {
                    return;
                }
                $items[$key] = $this->registrationItem($registration, (string) $log->channel, (string) $log->outcome, $log->created_at);
            });

        return array_values($items);
    }

    /** @return list<array<string,mixed>> */
    private function codesExpired(Carbon $now): array
    {
        $stuck = $this->minutes('otp_stuck_after_minutes', 30);
        $items = [];

        RegistrationVerification::query()
            ->whereNull('consumed_at')
            ->whereNotIn('delivery_status', self::NOT_DELIVERED)
            ->whereBetween('expires_at', [$this->since($now), $now->copy()->subMinutes($stuck)])
            ->orderBy('expires_at')
            ->each(function (RegistrationVerification $v) use (&$items): void {
                $registration = RegistrationRequest::query()->find($v->registration_request_id);
                if ($registration === null || ! $this->stillUnverified($registration, (string) $v->channel)) {
                    return;
                }
                // A newer code on the same channel means the customer asked again: only the latest one counts.
                $newer = RegistrationVerification::query()
                    ->where('registration_request_id', $registration->getKey())
                    ->where('channel', $v->channel)
                    ->where('created_at', '>', $v->created_at)
                    ->exists();
                if ($newer) {
                    return;
                }
                $items['reg:'.$registration->getKey().':'.$v->channel] = $this->registrationItem($registration, (string) $v->channel, 'expired', $v->expires_at) + [
                    'sent_at' => $v->created_at?->toIso8601String(),
                    'attempts' => (int) $v->attempts,
                ];
            });

        return array_values($items);
    }

    /** @return list<array<string,mixed>> */
    private function paymentErrors(Carbon $now): array
    {
        $items = [];

        SubscriptionPayment::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', $this->since($now))
            ->orderBy('updated_at')
            ->each(function (SubscriptionPayment $payment) use (&$items): void {
                // A verified paid event or a verification audit makes this alert 2's, where the detail belongs.
                $event = $this->gatewayEvent($payment);
                if (in_array($event['status'] ?? null, self::GATEWAY_PAID, true) || $this->latestVerificationAudit($payment) !== null) {
                    return;
                }
                // The customer paid afterwards on another attempt: no longer blocked.
                if ($this->laterSettledAttempt($payment)) {
                    return;
                }
                $facts = $this->paymentFacts($payment);
                $items['pay:'.$payment->getKey()] = [
                    'key' => 'pay:'.$payment->getKey(),
                    'who' => $facts['who'],
                    'at' => $payment->updated_at?->toIso8601String(),
                    'status' => $event['status'] ?? 'failed',
                    'gateway_status' => $event['status'] ?? null,
                    'recorded_error' => $this->safeError($payment->error),
                    'payment_id' => (string) $payment->getKey(),
                    'registration_id' => $facts['registration_id'],
                    'tenant_id' => $facts['tenant_id'],
                    'amount' => $facts['amount'],
                    'currency' => $facts['currency'],
                ];
            });

        return array_values($items);
    }

    /** @return list<array<string,mixed>> */
    private function invitationFailures(Carbon $now): array
    {
        $items = [];

        DB::table('mail_deliveries')
            ->where('kind', 'invitation')
            ->where('status', 'failed')
            ->where('created_at', '>=', $this->since($now))
            ->orderBy('created_at')
            ->get(['id', 'tenant_id', 'recipient', 'error', 'created_at'])
            ->each(function (object $row) use (&$items): void {
                $later = DB::table('mail_deliveries')
                    ->where('kind', 'invitation')
                    ->whereRaw('lower(recipient) = ?', [strtolower((string) $row->recipient)])
                    ->whereIn('status', ['sent', 'queued', 'claimed'])
                    ->where('created_at', '>', $row->created_at)
                    ->exists();
                if ($later) {
                    return;
                }
                $items['mail:'.$row->id] = [
                    'key' => 'mail:'.$row->id,
                    'who' => $this->tenantName($row->tenant_id === null ? null : (string) $row->tenant_id),
                    'at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
                    'status' => 'failed',
                    'recipient' => $this->mask((string) $row->recipient),
                    'recorded_error' => $this->safeError($row->error === null ? null : (string) $row->error),
                    'tenant_id' => $row->tenant_id === null ? null : (string) $row->tenant_id,
                ];
            });

        return array_values($items);
    }

    // ── Facts ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $facts
     * @return Candidate
     */
    private function candidate(string $kind, string $severity, string $key, SubscriptionPayment $payment, array $facts): array
    {
        return [
            'kind' => $kind,
            'severity' => $severity,
            'dedup_key' => $key,
            'subject_type' => 'subscription_payment',
            'subject_id' => (string) $payment->getKey(),
            'subject_tenant_id' => $facts['tenant_id'] ?? null,
            'facts' => $facts,
        ];
    }

    /** @return array<string,mixed> what every payment alert states: who, what, how much, when — and nothing about the card */
    private function paymentFacts(SubscriptionPayment $payment): array
    {
        $registration = $payment->registration_request_id === null ? null : RegistrationRequest::query()->find($payment->registration_request_id);
        $tenantId = $payment->tenant_id !== null ? (string) $payment->tenant_id : ($registration?->tenant_id === null ? null : (string) $registration->tenant_id);
        $plan = $payment->plan_code === null ? null : SubscriptionPlan::query()->where('code', $payment->plan_code)->first(['code', 'name', 'name_ar']);

        return [
            'payment_id' => (string) $payment->getKey(),
            'registration_id' => $registration === null ? null : (string) $registration->getKey(),
            'tenant_id' => $tenantId,
            'who' => $registration !== null ? $this->applicantName($registration) : $this->tenantName($tenantId),
            'purpose' => (string) $payment->purpose,
            'plan_code' => $payment->plan_code,
            'plan_name' => $plan?->name,
            'plan_name_ar' => $plan?->name_ar,
            'interval' => $payment->billing_interval,
            'amount' => (string) $payment->amount,
            'currency' => (string) $payment->currency,
            'provider' => (string) $payment->provider,
            'opened_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * The customer's way in, drawn as stages — each one from a recorded fact.
     *
     * @return list<array{key: string, state: string, at: ?string}>
     */
    private function funnel(SubscriptionPayment $payment): array
    {
        $checkout = ['key' => 'checkout', 'state' => 'watch', 'at' => $payment->created_at?->toIso8601String()];

        if ($payment->registration_request_id === null) {
            return [
                ['key' => 'subscription', 'state' => 'ok', 'at' => null],
                $checkout,
                ['key' => 'confirmed', 'state' => 'none', 'at' => null],
            ];
        }

        $registration = RegistrationRequest::query()->find($payment->registration_request_id);
        $verified = true;
        if ($registration !== null) {
            $policy = app(RegistrationPolicy::class)->for($registration);
            $verified = (! $policy['requires_email'] || $registration->emailIsVerified())
                && (! $policy['requires_mobile'] || $registration->mobileIsVerified());
        }

        return [
            ['key' => 'signup', 'state' => 'ok', 'at' => $registration?->created_at?->toIso8601String()],
            ['key' => 'verify', 'state' => $verified ? 'ok' : 'watch', 'at' => null],
            $checkout,
            ['key' => 'active', 'state' => 'none', 'at' => null],
        ];
    }

    /** @return array{status: ?string, type: ?string, at: ?string}|null the latest verified gateway event about this payment */
    private function gatewayEvent(SubscriptionPayment $payment): ?array
    {
        $reference = (string) $payment->idempotency_key;
        $paymentId = (string) ($payment->provider_payment_id ?? '');

        $event = PaymentWebhookEvent::withoutGlobalScopes()
            ->where('provider', $payment->provider)
            ->where('verified', true)
            ->where(function ($q) use ($reference, $paymentId): void {
                $q->whereRaw("payload->'data'->'metadata'->>'reference' = ?", [$reference]);
                if ($paymentId !== '') {
                    $q->orWhereRaw("payload->'data'->>'id' = ?", [$paymentId]);
                }
            })
            ->latest('created_at')
            ->first();

        return $event === null ? null : $this->describeEvent($event);
    }

    /** @return array{status: ?string, type: ?string, at: ?string} the gateway's own status word, verbatim */
    private function describeEvent(PaymentWebhookEvent $event): array
    {
        $status = $this->eventData($event)['status'] ?? null;

        return [
            'status' => is_string($status) && $status !== '' ? mb_substr($status, 0, 40) : null,
            'type' => $event->type === null ? null : mb_substr((string) $event->type, 0, 60),
            'at' => $event->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function eventData(PaymentWebhookEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /** @param  array<string,mixed>  $data */
    private function paymentForEvent(string $provider, array $data): ?SubscriptionPayment
    {
        $reference = (string) ($data['metadata']['reference'] ?? '');
        if ($reference !== '') {
            $payment = SubscriptionPayment::query()->where('idempotency_key', $reference)->first();
            if ($payment !== null) {
                return $payment;
            }
        }
        $id = (string) ($data['id'] ?? '');

        return $id === '' ? null : SubscriptionPayment::query()->where('provider', $provider)
            ->where(fn ($q) => $q->where('provider_payment_id', $id)->orWhere('provider_session_id', $id))->first();
    }

    /** @return array{action: string, gateway_says: ?string, gateway_raw_status: ?string, at: ?string}|null */
    private function latestVerificationAudit(SubscriptionPayment $payment): ?array
    {
        $log = AuditLog::query()
            ->whereIn('action', self::VERIFICATION_AUDITS)
            ->where('entity_type', SubscriptionPayment::class)
            ->where('entity_id', (string) $payment->getKey())
            ->latest('created_at')
            ->first();

        if ($log === null) {
            return null;
        }
        $after = is_array($log->after) ? $log->after : [];

        return [
            'action' => (string) $log->action,
            'gateway_says' => isset($after['gateway_says']) ? mb_substr((string) $after['gateway_says'], 0, 40) : null,
            'gateway_raw_status' => isset($after['gateway_raw_status']) ? mb_substr((string) $after['gateway_raw_status'], 0, 40) : null,
            'at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function laterSettledAttempt(SubscriptionPayment $payment): bool
    {
        $query = SubscriptionPayment::query()->where('status', 'paid')->where('created_at', '>', $payment->created_at);

        if ($payment->registration_request_id !== null) {
            return $query->where('registration_request_id', $payment->registration_request_id)->exists();
        }
        if ($payment->subscription_id !== null) {
            return $query->where('subscription_id', $payment->subscription_id)->exists();
        }

        return false;
    }

    private function hasLiveSubscription(string $tenantId): bool
    {
        return Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();
    }

    private function stillUnverified(RegistrationRequest $registration, string $channel): bool
    {
        if ($registration->tenant_id !== null || ! in_array($registration->state ?? AccountState::Draft, self::VERIFYING, true)) {
            return false;
        }

        return $channel === 'mobile' ? ! $registration->mobileIsVerified() : ! $registration->emailIsVerified();
    }

    /** @return array<string,mixed> */
    private function registrationItem(RegistrationRequest $registration, string $channel, string $status, mixed $at): array
    {
        return [
            'key' => 'reg:'.$registration->getKey().':'.$channel,
            'who' => $this->applicantName($registration),
            'at' => $at instanceof \DateTimeInterface ? Carbon::instance($at)->toIso8601String() : null,
            'status' => mb_substr($status, 0, 48),
            'channel' => $channel,
            'registration_id' => (string) $registration->getKey(),
        ];
    }

    private function applicantName(RegistrationRequest $registration): string
    {
        foreach ([$registration->tenant_name, $registration->name] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return $this->mask((string) $registration->email);
    }

    private function tenantName(?string $tenantId): ?string
    {
        return $tenantId === null ? null : Tenant::query()->whereKey($tenantId)->value('name');
    }

    /** An address the owner can recognise without it being copied whole into an alert: «mo***@example.com». */
    private function mask(string $email): string
    {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }

        return mb_substr($email, 0, min(2, $at)).'***'.mb_substr($email, $at);
    }

    /** The recorded error, cut short, with anything that looks like a card number or a key removed. */
    private function safeError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }
        $clean = (string) preg_replace(['/\b\d{12,19}\b/', '/\b(sk|pk)_(live|test)_[A-Za-z0-9]+/'], ['[redacted]', '[redacted]'], $error);

        return mb_substr(trim($clean), 0, 160);
    }

    private function since(Carbon $now): Carbon
    {
        return $now->copy()->subHours(max(1, (int) config('platform_alerts.lookback_hours', 72)));
    }

    private function minutes(string $key, int $default): int
    {
        return max(1, (int) config('platform_alerts.'.$key, $default));
    }

    private function zone(): string
    {
        return (string) config('platform_alerts.timezone', 'Asia/Riyadh');
    }
}
