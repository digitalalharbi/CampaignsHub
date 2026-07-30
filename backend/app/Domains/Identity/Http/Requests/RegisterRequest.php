<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Accounts\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            // The path chosen on the public site. Optional — a direct visit to /register has none — but when
            // present it is answered ONCE here instead of being asked again by the onboarding wizard.
            'account_type' => ['sometimes', 'nullable', Rule::in(AccountType::values())],
            'service' => ['sometimes', 'nullable', Rule::in(['paid_media', 'influencer_marketing', 'combined'])],
        ];
    }
}
