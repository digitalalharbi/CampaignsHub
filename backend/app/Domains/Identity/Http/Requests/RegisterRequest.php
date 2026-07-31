<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Tenancy\Enums\Portal;
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

            /*
             * SIGNUP-002. All three are things the applicant ASKS for, and none of them grants
             * anything: the portal is honoured only at provisioning, the plan only decides which
             * policy applies, and the phone number is checked before it counts for anything.
             *
             * `requested_portal` is deliberately limited to the portals someone may apply for.
             * `admin` is not one of them — the platform owner belongs to no tenant and is never
             * created by a public form — and `client_portal` accounts are opened by the workspace
             * that serves them, not by self-registration.
             */
            'requested_portal' => ['sometimes', 'nullable', Rule::in([
                Portal::App->value, Portal::Agency->value, Portal::Influencers->value,
            ])],
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
