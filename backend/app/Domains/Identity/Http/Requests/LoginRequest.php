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
        ];
    }
}
