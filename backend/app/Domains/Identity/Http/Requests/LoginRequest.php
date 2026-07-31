<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
            // "Keep me signed in" — drives Auth::login($user, $remember) and the long-lived cookie.
            'remember' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            /*
             * The portal the visitor chose on the sign-in page (LOGIN-003).
             *
             * A PREFERENCE, never a grant: naming a portal here cannot open one, and the only thing
             * it can do is get the sign-in refused when the account does not hold it. Absent means
             * "no claim", which is the normal case — a plain `/login` asks for nothing in particular
             * and the server decides where the user goes from their memberships.
             *
             * Deliberately not validated against the enum: an unknown value must behave exactly like
             * no value at all, rather than becoming a validation error that says something about
             * which portals exist.
             */
            'portal' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
