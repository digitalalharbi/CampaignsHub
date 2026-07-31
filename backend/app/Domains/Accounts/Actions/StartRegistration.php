<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Actions;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\RegistrationVerificationService;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Enums\Portal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * What pressing "create account" now does (SIGNUP-002).
 *
 * It records an APPLICATION and sends a verification challenge. That is the whole of it. No tenant,
 * no workspace, no user row, no membership — the four things registration used to create in the same
 * breath, which is why "an account awaiting approval" previously had nowhere to exist.
 *
 * Re-applying is deliberately allowed to reuse the pending row rather than fail: someone who closed
 * the tab before verifying and came back is not an error, and the alternative is an applicant locked
 * out of their own address by a partial attempt they cannot see or cancel. The live-email unique
 * index means there is only ever one such row to find.
 */
final class StartRegistration
{
    /** Mirrors OnboardingController::SERVICE_MODULES — one service choice, one module set. */
    public function __construct(
        private readonly RegistrationVerificationService $verification,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{request: RegistrationRequest, verification: array<string, mixed>}
     */
    public function execute(RegisterData $data, ?string $requestedPortal = null, ?string $planCode = null): array
    {
        $request = DB::transaction(function () use ($data, $requestedPortal, $planCode): RegistrationRequest {
            $existing = RegistrationRequest::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($data->email)])
                ->whereNull('tenant_id')
                ->whereNotIn('state', [
                    AccountState::Rejected->value,
                    AccountState::Cancelled->value,
                    AccountState::Expired->value,
                ])
                ->first();

            $portal = $requestedPortal
                ?? Portal::forAccountType($data->accountType)->value;

            $attributes = [
                'email' => $data->email,
                'name' => $data->name,
                'tenant_name' => $data->tenantName,
                'account_type' => $data->accountType,
                'requested_portal' => $portal,
                'plan_code' => $planCode,
                'service' => $data->service,
                'phone' => $data->phone,
            ];

            if ($existing !== null) {
                /*
                 * A second attempt from the same address, still unverified.
                 *
                 * The details are refreshed — people correct a typo and try again — but the STATE is
                 * not touched. Resetting it here would let an applicant who is sitting in
                 * `PendingApproval` walk themselves back to the start of the queue, and one who had
                 * cleared a payment gate re-enter it unpaid.
                 */
                $existing->forceFill($attributes + ['password' => Hash::make($data->password)])->save();

                return $existing->refresh();
            }

            $fresh = new RegistrationRequest;
            $fresh->forceFill($attributes + [
                // Hashed on the way in. A pending registration is still somebody's password.
                'password' => Hash::make($data->password),
                'state' => AccountState::EmailVerificationRequired->value,
                'state_changed_at' => now(),
            ])->save();

            return $fresh->refresh();
        });

        $this->audit->log(
            action: 'registration.started',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            after: [
                'email' => $request->email,
                'requested_portal' => $request->requested_portal,
                'plan_code' => $request->plan_code,
            ],
        );

        return [
            'request' => $request,
            'verification' => $this->verification->send($request, 'email'),
        ];
    }
}
