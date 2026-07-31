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
