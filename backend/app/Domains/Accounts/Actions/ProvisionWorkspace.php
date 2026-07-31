<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The crossing: an application becomes a workspace (SIGNUP-002).
 *
 * Everything the old `RegisterTenantAction` did at the moment someone pressed "create account" now
 * happens HERE, and only when the account has actually reached Active. That is the difference the
 * whole unit is about — before, submitting a form produced a tenant, a workspace, a membership and
 * portal access, so there was no such thing as an application that had not been granted anything.
 *
 * It refuses to run early, and that refusal is the enforcement. A caller that has not verified,
 * approved and taken payment cannot get a workspace out of this by asking nicely.
 */
final class ProvisionWorkspace
{
    /**
     * Mirrors OnboardingController::SERVICE_MODULES — one service choice, one module set.
     *
     * `combined` is why this is a map and not `[$service]`: treating the choice as its own module
     * name enabled a module called "combined" that nothing in the system knows about, so a workspace
     * that asked for both services got neither.
     *
     * @var array<string, list<string>>
     */
    private const SERVICE_MODULES = [
        'paid_media' => ['paid_media'],
        'influencer_marketing' => ['influencer_marketing'],
        'combined' => ['paid_media', 'influencer_marketing'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly GrantMembership $grants,
        private readonly TransitionAccountState $transitions,
        private readonly RegistrationPolicy $policy,
    ) {}

    /**
     * Has this application actually been paid for?
     *
     * `paid` and never reversed. A refund or a chargeback leaves the row saying `refunded`, and an
     * application whose fee came back has not been paid for however it once looked.
     */
    private function hasSettledPayment(RegistrationRequest $request): bool
    {
        return SubscriptionPayment::query()
            ->where('registration_request_id', $request->getKey())
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->exists();
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    public function execute(RegistrationRequest $request): array
    {
        /*
         * The gate, stated once.
         *
         * Not `$request->state === Active`: this runs as PART of reaching Active, so the caller has
         * satisfied the conditions and is about to record it. What must be true is that every
         * condition the path imposes has been met — anything else and we would be creating a
         * workspace for an unverified, unapproved or unpaid application.
         */
        if ($request->isProvisioned()) {
            // Idempotent: a webhook delivered twice must not mint a second workspace.
            return ['tenant' => $request->tenant, 'user' => User::where('email', $request->email)->firstOrFail()];
        }

        if (! $request->emailIsVerified()) {
            throw new RuntimeException('A workspace cannot be created before the email address is verified.');
        }

        if (! in_array($request->state, [AccountState::ApprovedAwaitingPayment, AccountState::PaymentPending], true)) {
            throw new RuntimeException(
                "A workspace cannot be created from state {$request->state->value} — the account has not been approved."
            );
        }

        /*
         * The money, checked HERE rather than trusted from the caller (PAY-002).
         *
         * The state check above is not enough on its own, and that was a real hole: `PaymentPending`
         * is a legal state to provision from — it is the anchor a webhook activates out of — so any
         * caller could have handed this a request sitting in it and got a workspace without a penny
         * having moved. `AdvanceRegistration` only reaches this after `paymentConfirmed()`, but "only
         * one caller does the right thing" is a convention, not a guarantee.
         *
         * So the ledger is consulted directly: when the policy for this application requires payment,
         * a SETTLED charge must exist for it. A refunded one does not count, because money that came
         * back is money we do not have.
         */
        if ($this->policy->for($request)['requires_payment'] && ! $this->hasSettledPayment($request)) {
            throw new RuntimeException(
                'A workspace cannot be created for an application that owes payment: no settled charge exists for it.'
            );
        }

        return DB::transaction(function () use ($request): array {
            $tenant = Tenant::create([
                'name' => $request->tenant_name,
                'slug' => $this->uniqueSlug($request->tenant_name),
                // Created inactive. `provision()` below moves it to Active with a reason, so the
                // audit trail records the crossing rather than a workspace that was simply born
                // switched on.
                'status' => 'inactive',
                'account_type' => $request->account_type,
                'subscription_plan' => $request->plan_code ?? 'trial',
                /*
                 * The wizard resumes at the first question this applicant has NOT answered.
                 *
                 * Never `verify_email`: by the time a workspace exists the address has been proven,
                 * and re-asking would be the wizard contradicting the gate that let them in. Someone
                 * who chose their path on the public site already answered the first two questions
                 * and lands on `workspace`.
                 */
                'onboarding_step' => $this->firstUnansweredStep($request),
                'enabled_modules' => self::SERVICE_MODULES[$request->service] ?? null,
            ]);

            $this->context->setTenantId((string) $tenant->id);

            Workspace::create([
                'tenant_id' => $tenant->id,
                'name' => 'Default Workspace',
                'slug' => 'default',
            ]);

            // The password was captured at application time and hashed then — this is the same
            // credential the applicant chose, not a new one they have to be told about.
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'phone' => $request->phone,
            ]);

            // forceFill, because `email_verified_at` is not mass-assignable — marking an address
            // verified is not something a payload may do. It was verified during the gated path, and
            // asking the customer to prove it again after they have paid would be absurd.
            $user->forceFill(['email_verified_at' => $request->email_verified_at])->save();

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($ownerRole);

            /*
             * The membership — the thing that did NOT exist while the application was pending.
             *
             * The portal comes from what the applicant asked for, honoured only because they have
             * now been approved for it. `forAccountType` is the fallback for an application that
             * named no portal.
             */
            $portal = Portal::tryFrom((string) $request->requested_portal)
                ?? Portal::forAccountType($request->account_type);

            $this->grants->execute(new MembershipGrant(
                user: $user, tenant: $tenant, portal: $portal, role: 'owner',
            ));

            $this->transitions->provision(
                $tenant,
                AccountState::Active,
                'Provisioned from registration request '.$request->getKey().' after verification and approval.',
            );

            $request->forceFill([
                'tenant_id' => $tenant->id,
                'provisioned_at' => now(),
                'state' => AccountState::Active->value,
                'state_changed_at' => now(),
            ])->save();

            return ['tenant' => $tenant->refresh(), 'user' => $user];
        });
    }

    /** The first onboarding question this application has not already answered. */
    private function firstUnansweredStep(RegistrationRequest $request): string
    {
        if ($request->account_type === null) {
            return 'account_type';
        }
        if ($request->service === null) {
            return 'service';
        }

        return 'workspace';
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $i = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
