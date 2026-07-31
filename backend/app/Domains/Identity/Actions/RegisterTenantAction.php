<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Accounts\Actions\StartRegistration;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\DTOs\RegisterData;
use App\Models\User;
use RuntimeException;

/**
 * The AUTO-ACTIVATE branch, and nothing else (SIGNUP-002).
 *
 * This class used to BE registration: it created the tenant, the workspace, the user and the
 * membership in one transaction, so every signup was an immediate grant and there was no moment at
 * which verification, approval or payment could be asked for. The contract still permits
 * auto-activation — but as a policy a plan opts into, not as the only road in. So the class survives
 * with its meaning narrowed to exactly that, and the public `/auth/register` endpoint no longer
 * calls it.
 *
 * Two things make it safe to keep:
 *
 * 1. It provisions NOTHING itself. It opens a registration request like everyone else and lets
 *    `AdvanceRegistration` walk the gates, so there is one provisioner in the system and one place
 *    where the conditions are checked. If a plan later requires approval, callers of this class stop
 *    at the queue instead of quietly bypassing it.
 * 2. It refuses outright when the policy is not auto-activate. A caller cannot use it to skip a gate
 *    that has been configured, which is the failure mode a retained shortcut would otherwise invite.
 *
 * The caller must be able to say WHY the email address is already proven — an OAuth provider that
 * asserted it, an administrator creating a workspace by hand. That reason is required and audited,
 * because it is the one step this branch skips.
 */
final class RegisterTenantAction
{
    public function __construct(
        private readonly StartRegistration $start,
        private readonly AdvanceRegistration $advance,
        private readonly RegistrationPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $emailAlreadyProvenBecause  why no email challenge is needed (audited)
     */
    public function execute(
        RegisterData $data,
        string $emailAlreadyProvenBecause,
        ?string $requestedPortal = null,
        ?string $planCode = null,
    ): User {
        $started = $this->start->execute($data, $requestedPortal, $planCode);

        /** @var RegistrationRequest $request */
        $request = $started['request'];

        if (! $this->policy->isAutoActivate($request)) {
            /*
             * Deliberately fatal rather than a silent fall-back to the gated path.
             *
             * A caller reaching for this class has told us it expects a usable account back. Quietly
             * returning one that is sitting in a review queue would surface the failure somewhere
             * far away from the decision that caused it; refusing here says so at the point of use.
             */
            throw new RuntimeException(
                'Auto-activation is not permitted for this application: the registration policy for plan ['
                .($request->plan_code ?? 'default').'] requires verification, approval or payment. '
                .'Use the gated registration path.'
            );
        }

        $this->audit->log(
            action: 'registration.auto_activated',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            after: ['email' => $request->email],
            reason: $emailAlreadyProvenBecause,
        );

        // The email counts as proven for the reason given above; every other gate is off by policy,
        // so this reaches Active through the same provisioner as any other application.
        $request = $this->advance->emailVerified($request);

        $user = User::where('email', $request->email)->first();

        if ($user === null || ! $request->isProvisioned()) {
            throw new RuntimeException('Auto-activation did not produce a workspace — see the registration request.');
        }

        return $user;
    }
}
