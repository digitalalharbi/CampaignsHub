<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

/**
 * A payment gateway adapter. The rest of the app never talks to a concrete gateway — it asks the registry for
 * the configured provider. A real gateway is plugged in by binding a configured implementation; until then the
 * Null adapter reports "not configured" and no money can move.
 *
 * SECURITY: a payment is NEVER marked paid from a createSession() result. Only a webhook that this adapter
 * cryptographically verifies (verifyWebhook → verified=true) may drive an invoice to paid.
 */
interface PaymentProvider
{
    public function name(): string;

    /** True only when real credentials are wired. When false, callers record awaiting_credentials. */
    public function isConfigured(): bool;

    /**
     * Open a checkout session with the gateway. Implementations MUST NOT report a settled payment here — the
     * best they may return is a session/redirect the customer still has to complete.
     *
     * @param  array<string,mixed>  $payload
     * @return array{status: string, session_id?: string|null, checkout_url?: string|null, error?: string|null}
     *                                                                                                          status ∈ created|awaiting_credentials|failed
     */
    public function createSession(array $payload): array;

    /**
     * Verify a raw webhook body against the provider's signature scheme. A malformed or unsigned payload MUST
     * return verified=false so no state transition happens.
     *
     * @param  array<string,string>  $headers
     * @return array{verified: bool, event_id?: string|null, type?: string|null, payment_id?: string|null, status?: string|null}
     *                                                                                                                           status ∈ paid|failed|refunded|pending|processing
     */
    public function verifyWebhook(string $rawBody, array $headers): array;

    /**
     * Does this provider's webhook scheme prove the BODY was not modified? (PAY-CONFIRM-001)
     *
     * The distinction is not academic. Stripe and the sandbox sign the raw payload with an HMAC, so a
     * verified event's amount is attested by the gateway: change one digit and the signature fails.
     * Moyasar authenticates with a SHARED SECRET carried inside the body — which proves the sender
     * knew a token, and nothing whatever about the rest of the JSON around it. Anyone who learns that
     * token can post any figures they like, correctly «verified».
     *
     * Adapters that answer false must be re-asked before their word settles anything, which is what
     * `fetchPayment()` is for. Answering true is a claim about cryptography, not about trust.
     */
    public function confirmsPayloadIntegrity(): bool;

    /**
     * Ask the gateway directly what this payment is — backend to backend (PAY-CONFIRM-001).
     *
     * The authoritative answer, fetched over a connection the customer's browser is not part of and
     * a webhook body cannot influence. Returns null when the provider cannot be asked: no
     * credentials, no endpoint, or the request failed. **Null is not «it is fine»** — a caller that
     * needs this answer must refuse to settle without it.
     *
     * @return array{status: string, amount: ?string, currency: ?string, reference: ?string}|null
     *                                                                                            status ∈ paid|failed|refunded|pending|processing
     */
    public function fetchPayment(string $providerPaymentId): ?array;

    /**
     * Can this provider charge a saved token with nobody present? (PAY-TOKEN-001)
     *
     * The difference between a subscription and a series of invoices somebody has to remember to
     * pay. False here is not a defect — it is the honest state of an adapter whose tokenization is
     * not wired — and the renewal path answers it by opening an attended charge instead, so a
     * customer is asked rather than silently allowed to lapse.
     */
    public function supportsUnattendedCharge(): bool;

    /**
     * Charge a saved token — the renewal nobody has to attend (PAY-TOKEN-001).
     *
     * ## This does NOT settle anything
     *
     * It asks the gateway to take money and reports what the gateway said about the ATTEMPT. The
     * payment becomes paid the same way every other payment in this product does: a webhook the
     * adapter verifies, re-read from the gateway where the signature cannot prove the body, through
     * the single call site in `ApplySubscriptionPaymentEvent`. An adapter that marked a charge paid
     * from this return value would be a second, weaker way to activate an account.
     *
     * `reference` is our idempotency key and must travel with the charge, so a retry of this call
     * cannot take the money twice and the confirming webhook can find what it belongs to.
     *
     * @param  array<string,mixed>  $payload  amount · currency · reference · description
     * @return array{status: string, provider_payment_id?: string|null, error?: string|null}
     *                                                                                       status ∈ submitted|awaiting_credentials|unsupported|failed
     */
    public function chargeStoredMethod(string $token, array $payload): array;

    /**
     * The reusable card a settled payment left behind, or null when the gateway published none
     * (PAY-TOKEN-003).
     *
     * ## The half of unattended billing that was missing
     *
     * `chargeStoredMethod()` takes a token. Nothing in this application ever produced one: the port
     * could spend a card on file and had no way to acquire one, so `subscription_payment_methods`
     * stayed empty in every install, `RecurringBilling::methodFor()` always answered null, and every
     * renewal fell to the hosted invoice a customer has to remember to visit. This is the method that
     * closes the loop, and it is deliberately on the ADAPTER: what a gateway publishes about a card,
     * where it publishes it, and whether it publishes anything at all are facts about that gateway.
     *
     * ## Null is the safe answer and the common one
     *
     * A provider that does not tokenise, an event for a payment made with no reusable source, a
     * merchant account without card-on-file enabled — all of them return null, and null costs the
     * customer nothing: their next renewal is an attended invoice, exactly as it is today. Returning
     * a fabricated token would be worse than useless, because the first thing that happens to it is a
     * charge against somebody's card.
     *
     * Only the `token` is load-bearing. Brand, last four and expiry are labels a person reads to tell
     * one card from another; none of them authorises anything and each may be absent.
     *
     * @param  array<string,mixed>  $payload  the verified event body
     * @return array{token: string, customer_id?: ?string, brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}|null
     */
    public function savedPaymentMethodFrom(array $payload): ?array;

    /**
     * A stable identifier for the PAYMENT METHOD a verified event used, or null when the provider
     * does not publish one (PAY-004).
     *
     * This is what makes "one trial per payment method" enforceable without the system ever seeing a
     * card. Providers differ: Stripe publishes a fingerprint that is stable across customers, Moyasar
     * publishes only the brand and last four digits. An adapter returns what it genuinely has, and
     * null where it has nothing — a fabricated fingerprint would silently either block innocent
     * customers or let the same card open trials forever.
     *
     * @param  array<string,mixed>  $payload  the verified event body
     */
    public function paymentMethodFingerprint(array $payload): ?string;
}
