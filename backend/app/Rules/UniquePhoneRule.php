<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PHONE-VERIFY-001 — «has this number already been used to open an account?»
 *
 * A `unique:users,phone` rule could not answer that, and this is the whole reason the class exists:
 * the column holds E.164 and the form holds whatever the customer typed. `0501234567` against a
 * stored `+966501234567` is not a duplicate as far as the database is concerned, so the same person
 * could open a second account simply by writing their own number in its national form — which is the
 * form most of them use.
 *
 * So the comparison happens in the canonical shape, on both sides. `PhoneNumber::normalise()` is the
 * one reading of a number this product has, and this rule is that reading applied to a question.
 *
 * ## What counts as "already used"
 *
 * A real user, or an application that is still live. A REJECTED or abandoned application does not
 * hold a number hostage — somebody refused once must be able to apply again with the same phone,
 * which is exactly the situation `AccountState::isTerminal()` describes.
 */
final class UniquePhoneRule implements ValidationRule
{
    /**
     * @param  int|null  $ignoreUserId  the user being updated, so their own number is not a clash
     */
    public function __construct(
        private readonly ?int $ignoreUserId = null,
        private readonly ?string $ignoreRegistrationId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // `required` / `nullable` decides whether an absent value is allowed.
        }

        $e164 = PhoneNumber::normalise($value);

        if ($e164 === null) {
            return; // Unreadable is `PhoneNumberRule`'s complaint, not this one's.
        }

        $takenByUser = User::query()
            ->where('phone', $e164)
            ->when($this->ignoreUserId !== null, fn ($q) => $q->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($takenByUser) {
            $fail(__('validation.phone_taken'));

            return;
        }

        /*
         * A live application counts too.
         *
         * Without this, two applications could be opened on one number and both would clear their
         * mobile gate — the second one provisioning a user whose phone the first already holds, at
         * which point the failure surfaces as a database error rather than as a form message.
         */
        $takenByApplication = RegistrationRequest::query()
            ->where('phone', $e164)
            ->whereNotIn('state', array_map(
                static fn (AccountState $s) => $s->value,
                array_filter(AccountState::cases(), static fn (AccountState $s) => $s->isTerminal()),
            ))
            ->when($this->ignoreRegistrationId !== null, fn ($q) => $q->whereKeyNot($this->ignoreRegistrationId))
            ->exists();

        if ($takenByApplication) {
            $fail(__('validation.phone_taken'));
        }
    }
}
