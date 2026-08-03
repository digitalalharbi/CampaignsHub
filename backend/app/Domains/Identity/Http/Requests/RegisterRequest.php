<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Tenancy\Enums\Portal;
use App\Rules\PhoneNumberRule;
use Closure;
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
            // The influencer module is only offerable while its portal is (INFL-OFF-001). A form that
            // cannot show the option must not accept it from a hand-written payload either.
            'service' => ['sometimes', 'nullable', Rule::in(
                Portal::Influencers->isEnabled()
                    ? ['paid_media', 'influencer_marketing', 'combined']
                    : ['paid_media']
            )],

            /*
             * SIGNUP-002. All three are things the applicant ASKS for, and none of them grants
             * anything: the portal is honoured only at provisioning, the plan only decides which
             * policy applies, and the phone number is checked before it counts for anything.
             *
             * `requested_portal` is deliberately limited to the portals someone may apply for.
             * `admin` is not one of them — the platform owner belongs to no tenant and is never
             * created by a public form — and `client_portal` accounts are opened by the workspace
             * that serves them, not by self-registration.
             *
             * A portal that is switched off drops out of this list too (INFL-OFF-001). Hiding the
             * option in the form and still accepting it here is how a "removed" choice keeps being
             * granted to whoever posts the payload directly.
             */
            'requested_portal' => ['sometimes', 'nullable', Rule::in(
                collect(Portal::offeredMembershipPortals())
                    ->reject(fn (Portal $p) => $p === Portal::ClientPortal)
                    ->map(fn (Portal $p) => $p->value)
                    ->values()->all()
            )],
            /*
             * A plan may only be one that is actually on sale.
             *
             * Checked against the catalogue rather than a list of strings here, because "which plans
             * may somebody sign up for?" is a question the platform owner answers from /admin — and
             * a payload naming a withdrawn or private plan would otherwise decide it instead.
             */
            'plan_code' => [
                'sometimes', 'nullable', 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && ! app(PlanCatalogue::class)->isOffered((string) $value)) {
                        $fail('That plan is not available.');
                    }
                },
            ],
            'billing_interval' => ['sometimes', 'nullable', 'in:monthly,annual'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', new PhoneNumberRule],
        ];
    }
}
