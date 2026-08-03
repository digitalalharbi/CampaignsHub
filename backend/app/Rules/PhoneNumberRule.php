<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PHONE-001 — «is there a readable phone number in this field?»
 *
 * Deliberately permissive, because the alternative was worse. The regex this replaces in several
 * places (`/^\+[1-9]\d{7,14}$/`) demanded a leading `+`, which rejected `0501234567` — the way most
 * Saudi customers write their own number — with a message that told them nothing about what to change.
 * A form that refuses the national format of its own market is not validating, it is failing.
 *
 * So: anything `PhoneNumber::normalise()` can read is accepted, and what gets STORED is the E.164 form
 * regardless of which shape arrived. Validation and normalisation are the same question asked twice,
 * and answering it in two places is how they drift apart.
 */
final class PhoneNumberRule implements ValidationRule
{
    public function __construct(private readonly string $defaultCountry = PhoneNumber::DEFAULT_COUNTRY) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // An absent optional field is not a malformed one — `nullable` decides whether it is allowed.
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || PhoneNumber::normalise($value, $this->defaultCountry) === null) {
            $fail(__('validation.phone_number'));
        }
    }
}
