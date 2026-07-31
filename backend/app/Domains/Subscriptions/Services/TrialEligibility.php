<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\TrialClaim;
use App\Domains\Tenancy\Models\Tenant;

/**
 * One trial per customer, counted against every identity we can establish (PAY-004).
 *
 * A paid trial is still a trial: the fee is symbolic, so the cost of letting somebody take it fifty
 * times is fifty times the product and fifty times nothing. The defence is not a single check but a
 * set of them — an abuser has to defeat ALL of the identities they present, and the ones that are
 * cheap to change (an email address) are the ones that matter least.
 *
 * Every value is HASHED before it is stored or compared. The question is "has this been seen before?",
 * which needs no plaintext, and a table of customer emails, phone numbers and card fingerprints is
 * exactly the thing not to keep in recoverable form.
 *
 * The payment-method check depends on what the gateway publishes. Stripe gives a card fingerprint that
 * is stable across customers; Moyasar gives nothing usable, and its adapter says so by returning null
 * rather than inventing one out of the last four digits — which thousands of cards share, and which
 * would block innocent customers. This is what "according to the provider's capabilities" means.
 */
final class TrialEligibility
{
    /**
     * Why a trial would be refused, or an empty list when it may proceed.
     *
     * Returns REASONS rather than a boolean so the review queue can show an operator what matched.
     * A trial blocked with no explanation is a support ticket nobody can answer.
     *
     * @return list<string>
     */
    public function reasonsToRefuse(RegistrationRequest $request, ?string $paymentFingerprint = null): array
    {
        $reasons = [];

        foreach ($this->identities($request, $paymentFingerprint) as $kind => $value) {
            if ($value === null) {
                continue;
            }
            if (! (bool) config("subscriptions.trial.one_per.{$kind}", true)) {
                continue;
            }
            if (TrialClaim::query()->where('kind', $kind)->where('value_hash', $this->hash($value))->exists()) {
                $reasons[] = $kind;
            }
        }

        return $reasons;
    }

    public function mayStartTrial(RegistrationRequest $request, ?string $paymentFingerprint = null): bool
    {
        return $this->reasonsToRefuse($request, $paymentFingerprint) === [];
    }

    /**
     * Record that these identities have now had their trial.
     *
     * `firstOrCreate` on a unique index, so a webhook delivered twice writes one row and a race
     * between two deliveries loses at the database rather than in application logic.
     */
    public function claim(RegistrationRequest $request, ?Tenant $tenant = null, ?string $paymentFingerprint = null): void
    {
        foreach ($this->identities($request, $paymentFingerprint) as $kind => $value) {
            if ($value === null) {
                continue;
            }

            TrialClaim::query()->firstOrCreate(
                ['kind' => $kind, 'value_hash' => $this->hash($value)],
                [
                    'registration_request_id' => $request->getKey(),
                    'tenant_id' => $tenant?->getKey(),
                ],
            );
        }
    }

    /**
     * The identities a trial is counted against.
     *
     * `company` is the workspace name normalised — weaker than the others, and deliberately included
     * anyway: an abuser who varies the email and the phone usually keeps the company name, because
     * that is the thing they actually want an account for.
     *
     * @return array<string, string|null>
     */
    private function identities(RegistrationRequest $request, ?string $paymentFingerprint): array
    {
        return [
            'email' => $this->normaliseEmail($request->email),
            'phone' => $this->normalisePhone($request->phone),
            'company' => $this->normaliseCompany($request->tenant_name),
            'payment_method' => $paymentFingerprint,
        ];
    }

    /**
     * Gmail-style dots and `+tags` make one mailbox look like infinitely many addresses.
     *
     * Normalising them is the difference between "one trial per email" and "one trial per email you
     * have not thought to vary yet".
     */
    private function normaliseEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');

        if ($domain === '') {
            return null;
        }

        $local = explode('+', $local)[0];
        $local = str_replace('.', '', $local);

        return $local.'@'.$domain;
    }

    /** Digits only: +966 50 000 0000 and 0500000000 are the same phone. */
    private function normalisePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Saudi numbers are written both with the country code and with a leading zero.
        $digits = preg_replace('/^(00966|966|0)/', '', $digits) ?? $digits;

        return $digits === '' ? null : $digits;
    }

    private function normaliseCompany(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($name))) ?? '';

        return $normalised === '' ? null : $normalised;
    }

    /**
     * Keyed with the application's own secret, so the hashes are useless in another install and a
     * leaked table cannot be attacked with a precomputed dictionary of common email addresses.
     */
    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
