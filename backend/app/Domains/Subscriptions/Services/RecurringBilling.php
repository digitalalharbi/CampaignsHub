<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;

/**
 * PAY-TOKEN-001 — can this renewal be taken without the customer, and if not, why not?
 *
 * ## The state this exists to stop being invisible
 *
 * A renewal today opens an INVOICE: a page the customer must visit and pay. Nobody visits it, so the
 * period lapses, `markPastDue` fires, and somebody who fully intended to keep paying is told their
 * account is past due. Read from the outside that looks like dunning working; it is actually the
 * absence of unattended billing, and nothing in the product said so.
 *
 * This class is the thing that says so. It answers one question — «could we charge this renewal
 * ourselves?» — and, when the answer is no, which of the three reasons applies:
 *
 *  - `no_gateway` — no payment provider is configured at all.
 *  - `provider_unsupported` — the configured gateway's unattended charging is not wired here.
 *  - `no_saved_method` — the gateway could, and this customer has no usable card on file.
 *
 * Those are different problems with different owners: the first two belong to whoever deploys this,
 * the third to the customer. Collapsing them into «renewal failed» sends the wrong person a message.
 *
 * ## What this deliberately does NOT do
 *
 * It does not charge anything. Switching the renewal sweep onto the token is a separate change and a
 * careful one: `SubscriptionCheckout::open()` creates a gateway invoice as part of opening a charge,
 * so a naive «also charge the token» would ask the customer to pay a page AND take the money from
 * their card. Until `open()` can be told to skip the invoice, this reports readiness and the renewal
 * stays attended — which asks the customer rather than silently letting them lapse.
 */
final class RecurringBilling
{
    public function __construct(private readonly SubscriptionProviderRegistry $providers) {}

    /**
     * The saved card this subscription's renewals would use.
     *
     * Scoped to the subscription's OWN provider: a token is meaningless to any gateway but the one
     * that minted it, and charging a Moyasar token through Stripe is a lookup against the wrong
     * vault whose answer — «no such token» — reads to everybody downstream as a declined card.
     *
     * Expired cards are skipped rather than returned-and-failed. The card is known to be no good
     * before a charge is attempted, so attempting one would spend a decline on the customer's
     * account for information we already had.
     */
    public function methodFor(Subscription $subscription): ?SubscriptionPaymentMethod
    {
        $provider = (string) ($subscription->provider ?? $this->providers->defaultKey());

        return SubscriptionPaymentMethod::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('provider', $provider)
            ->whereNull('detached_at')
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get()
            ->first(fn (SubscriptionPaymentMethod $m) => ! $m->isExpired());
    }

    /**
     * Whether this renewal can be taken unattended, and the reason when it cannot.
     *
     * @return array{unattended: bool, reason: string, method: ?string}
     */
    public function modeFor(Subscription $subscription): array
    {
        $providerKey = (string) ($subscription->provider ?? $this->providers->defaultKey());
        $adapter = $this->providers->for($providerKey);

        if (! $adapter->isConfigured()) {
            return ['unattended' => false, 'reason' => 'no_gateway', 'method' => null];
        }

        if (! $adapter->supportsUnattendedCharge()) {
            return ['unattended' => false, 'reason' => 'provider_unsupported', 'method' => null];
        }

        $method = $this->methodFor($subscription);

        if ($method === null) {
            return ['unattended' => false, 'reason' => 'no_saved_method', 'method' => null];
        }

        return ['unattended' => true, 'reason' => 'ready', 'method' => $method->label()];
    }

    /**
     * The platform-wide answer, for `/admin` and `production:check`.
     *
     * `ready` here means «this install could charge a renewal by itself», which is a statement about
     * the gateway and nothing else — a customer with no saved card still cannot be charged, and
     * `modeFor()` is what answers that per subscription. Kept apart on purpose: an operator reading
     * «recurring billing: ready» must not conclude that every subscriber will renew silently.
     *
     * @return array{ready: bool, provider: string, reason: string, saved_methods: int}
     */
    public function readiness(): array
    {
        $providerKey = $this->providers->defaultKey();
        $adapter = $this->providers->for($providerKey);

        $reason = match (true) {
            ! $adapter->isConfigured() => 'no_gateway',
            ! $adapter->supportsUnattendedCharge() => 'provider_unsupported',
            default => 'ready',
        };

        return [
            'ready' => $reason === 'ready',
            'provider' => $providerKey,
            'reason' => $reason,
            'saved_methods' => SubscriptionPaymentMethod::query()
                ->where('provider', $providerKey)
                ->whereNull('detached_at')
                ->count(),
        ];
    }

    /**
     * Record a card the gateway tokenised, and make it the one renewals would use.
     *
     * The token is the whole credential and the model encrypts it; brand, last four and expiry are
     * labels. Nothing here accepts a PAN or a CVC, and the schema has nowhere to put one.
     *
     * Re-saving the same token updates the existing row rather than adding a second: a customer who
     * re-enters the same card should end with one card on file, not two that decline together.
     *
     * @param  array{token: string, customer_id?: ?string, brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}  $card
     */
    public function remember(string $tenantId, string $provider, array $card): SubscriptionPaymentMethod
    {
        $existing = SubscriptionPaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->whereNull('detached_at')
            ->get()
            ->first(fn (SubscriptionPaymentMethod $m) => $m->provider_token === $card['token']);

        $method = $existing ?? new SubscriptionPaymentMethod;

        $method->forceFill([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'provider_token' => $card['token'],
            'provider_customer_id' => $card['customer_id'] ?? $method->provider_customer_id,
            'brand' => $card['brand'] ?? null,
            'last4' => $card['last4'] ?? null,
            'exp_month' => $card['exp_month'] ?? null,
            'exp_year' => $card['exp_year'] ?? null,
            'is_default' => true,
            'detached_at' => null,
        ])->save();

        /*
         * Exactly one default, enforced after the new row exists rather than before.
         *
         * Unsetting the old default first would leave a window with no default at all, and a renewal
         * sweep running in that window would report `no_saved_method` for a customer who has one.
         */
        SubscriptionPaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->whereKeyNot($method->getKey())
            ->update(['is_default' => false]);

        return $method->refresh();
    }

    /**
     * Retire a card without losing the history that it paid for things.
     *
     * `detached_at`, never a delete: the row is why a charge in the ledger exists, and removing it
     * attributes last month's payment to nothing.
     */
    public function forget(SubscriptionPaymentMethod $method): void
    {
        $method->forceFill(['detached_at' => now(), 'is_default' => false])->save();
    }
}
