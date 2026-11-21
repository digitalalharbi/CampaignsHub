<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The one place a reviewer's decision on an application is taken (SIGNUP-DECISION-001).
 *
 * Commercial treatment and access scope are two independent answers on one decision. The service records the decision
 * under a row lock and then lets `AdvanceRegistration` and `ProvisionWorkspace` do the rest, so there is still exactly one
 * provisioner and it still checks everything itself. A waiver is a decision of its own, recorded as `waived_by_admin`:
 * it creates no payment, no provider transaction and no invoice, and nothing here ever marks anything paid.
 */
final class RegistrationDecisionService
{
    /** Roles a person joining somebody's workspace may not be handed by this path. */
    private const NEVER_BY_JOINING = ['tenant-owner', 'platform-admin', 'super-admin'];

    public function __construct(
        private readonly PlanCatalogue $catalogue,
        private readonly RegistrationCommercialGate $gate,
        private readonly AdvanceRegistration $advance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Exactly what this decision would do. Writes nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(RegistrationRequest $request, array $input): array
    {
        $outcome = (string) $input['outcome'];
        $access = $this->access($request, $input);

        return [
            'outcome' => $outcome,
            'terms' => in_array($outcome, RegistrationDecision::APPROVALS, true) ? $this->terms($request, $input, previewing: true) : null,
            'access' => $this->describeAccess($request, $access),
            // Said separately from the decision: approving with no gateway must not read as a dead end or as paid.
            'payment_provider_ready' => $this->providerReady(),
        ];
    }

    /**
     * Take the decision.
     *
     * @param  array<string, mixed>  $input
     * @return array{registration: RegistrationRequest, decision: RegistrationDecision|null, replayed: bool, no_change: bool}
     */
    public function decide(RegistrationRequest $request, array $input, User $actor): array
    {
        return DB::transaction(function () use ($request, $input, $actor): array {
            /** @var RegistrationRequest $locked */
            $locked = RegistrationRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $outcome = (string) $input['outcome'];
            $key = isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null;
            $fingerprint = $this->fingerprint($outcome, $input);

            if ($key !== null) {
                $earlier = RegistrationDecision::query()->where('idempotency_key', $key)->first();

                if ($earlier !== null) {
                    if ((string) $earlier->registration_request_id !== (string) $locked->getKey()
                        || (($earlier->terms ?? [])['fingerprint'] ?? null) !== $fingerprint) {
                        throw new ConflictHttpException('That idempotency key belongs to a different decision.');
                    }

                    return ['registration' => $locked, 'decision' => $earlier, 'replayed' => true, 'no_change' => false];
                }
            }

            $current = $this->gate->inForce($locked);

            // Pressing approve twice is one decision and one email.
            if ($current !== null && (($current->terms ?? [])['fingerprint'] ?? null) === $fingerprint) {
                return ['registration' => $locked, 'decision' => $current, 'replayed' => true, 'no_change' => $outcome === RegistrationDecision::PAYMENT_REQUIRED];
            }

            // The plain answer on a row that already holds it: nothing changes, so nothing is written, and the caller says so.
            if ($this->alreadyAwaitingPayment($locked, $current, $outcome, $input)) {
                return ['registration' => $locked, 'decision' => $current, 'replayed' => false, 'no_change' => true];
            }

            abort_if($locked->isProvisioned(), 422, 'This application has already become a workspace. Manage the account from Tenants instead.');
            abort_if($locked->state->isTerminal(), 422, 'This application has already been closed and cannot be reviewed again.');

            if (array_key_exists('expected_decision_id', $input)
                && (string) ($input['expected_decision_id'] ?? '') !== (string) ($current?->getKey() ?? '')) {
                throw new ConflictHttpException('Another administrator has decided this application. Reload it and decide again.');
            }

            return match (true) {
                $outcome === RegistrationDecision::REJECT => $this->reject($locked, $current, $input, $actor, $fingerprint),
                in_array($outcome, RegistrationDecision::APPROVALS, true) => $this->approve($locked, $current, $input, $actor, $fingerprint),
                default => $this->record($locked, $input, $actor, $fingerprint),
            };
        });
    }

    /**
     * «Approved, payment still owed» said of an application already approved and waiting on that payment.
     *
     * This click wrote `registration.approved` every time, while nothing moved: eight identical lines on the Owner's own
     * application, and the button appeared broken. Narrowing the access, or going back from a concession, is a real change.
     *
     * @param  array<string, mixed>  $input
     */
    private function alreadyAwaitingPayment(RegistrationRequest $request, ?RegistrationDecision $current, string $outcome, array $input): bool
    {
        if ($outcome !== RegistrationDecision::PAYMENT_REQUIRED) {
            return false;
        }

        if (! in_array($request->state, [AccountState::ApprovedAwaitingPayment, AccountState::PaymentPending], true) || $request->isProvisioned()) {
            return false;
        }

        $access = (array) ($input['access'] ?? []);
        if (($access['portal'] ?? null) !== null || ($access['modules'] ?? null) !== null) {
            return false;
        }

        // No decision on the row: a person approved it before decisions existed (the Owner's own), or nobody has yet.
        if ($current === null) {
            return $request->approved_at !== null || $request->reviewed_at !== null;
        }

        $held = (array) $current->access;

        return $current->outcome === RegistrationDecision::PAYMENT_REQUIRED && ($held['portal'] ?? null) === null && ($held['modules'] ?? null) === null;
    }

    /**
     * The second administrator's confirmation of a settlement that really happened outside the gateways.
     *
     * The platform has no finance role, so the separation here is two people: whoever entered the evidence cannot also
     * confirm it. There is no way to reach this state without evidence, so there is no bare «mark paid».
     *
     * @return array{registration: RegistrationRequest, decision: RegistrationDecision}
     */
    public function verifySettlement(RegistrationRequest $request, RegistrationDecision $decision, User $verifier, string $reason): array
    {
        return DB::transaction(function () use ($request, $decision, $verifier, $reason): array {
            /** @var RegistrationRequest $locked */
            $locked = RegistrationRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            /** @var RegistrationDecision $row */
            $row = RegistrationDecision::query()->lockForUpdate()->whereKey($decision->getKey())
                ->where('registration_request_id', $locked->getKey())->firstOrFail();

            abort_if($row->status !== RegistrationDecision::AWAITING_FINANCE, 422, 'This settlement is not waiting for confirmation.');
            abort_if((int) $row->actor_id === (int) $verifier->getKey(), 403, 'Whoever entered the evidence cannot also confirm it.');
            abort_if($locked->isProvisioned() || $locked->state->isTerminal(), 422, 'This application has already been settled.');

            $this->gate->inForce($locked)?->forceFill(['status' => RegistrationDecision::SUPERSEDED])->save();

            $row->forceFill([
                'status' => RegistrationDecision::IN_FORCE,
                'verified_by' => $verifier->getKey(),
                'verified_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'registration.settlement_verified',
                entityType: RegistrationRequest::class,
                entityId: (string) $locked->getKey(),
                after: ['decision' => (string) $row->getKey(), 'evidence' => ($row->terms ?? [])['evidence'] ?? null],
                reason: $reason,
                userId: (int) $verifier->getKey(),
            );

            $registration = $this->advance->approved($locked, (int) $verifier->getKey(), $reason, notify: false);
            $this->advance->tellDecision($registration, $row);

            return ['registration' => $registration, 'decision' => $row->refresh()];
        });
    }

    /**
     * What an applicant and a reviewer are told about the commercial terms in force.
     *
     * @return array<string, mixed>|null
     */
    public function standing(RegistrationRequest $request): ?array
    {
        $decision = $this->gate->inForce($request);

        if ($decision === null || ! in_array($decision->outcome, RegistrationDecision::APPROVALS, true)) {
            return null;
        }

        $terms = (array) $decision->terms;
        unset($terms['fingerprint'], $terms['evidence']);

        $standing = ['outcome' => $decision->outcome] + $terms;

        if ($decision->outcome === RegistrationDecision::DISCOUNT) {
            $standing['quote'] = $this->discountedQuote($request, $decision);
        }

        return $standing;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{registration: RegistrationRequest, decision: RegistrationDecision|null, replayed: bool, no_change: bool}
     */
    private function approve(RegistrationRequest $request, ?RegistrationDecision $current, array $input, User $actor, string $fingerprint): array
    {
        $outcome = (string) $input['outcome'];

        // An address that already holds an account is somebody else's sign-in. Never attached by a decision.
        abort_if(
            User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $request->email)])->exists(),
            422,
            'An account already uses this email address, so this application cannot create one.',
        );

        $access = $this->access($request, $input);
        $terms = $this->terms($request, $input) + ['fingerprint' => $fingerprint];

        if (in_array($outcome, [RegistrationDecision::DISCOUNT, RegistrationDecision::WAIVER, RegistrationDecision::TRIAL, RegistrationDecision::COMPLIMENTARY], true)
            && $this->gate->hasSettledPayment($request)) {
            abort(422, 'A payment for this application has already settled, so there is nothing left to concede.');
        }

        // Evidence waits for a second person and changes nothing until then.
        if ($outcome === RegistrationDecision::EXTERNAL_SETTLEMENT) {
            $decision = $this->store($request, $outcome, RegistrationDecision::AWAITING_FINANCE, $input, $actor, $terms, $access);
            $this->auditDecision($request, $current, $decision, $actor);

            return ['registration' => $request, 'decision' => $decision, 'replayed' => false, 'no_change' => false];
        }

        $current?->forceFill(['status' => RegistrationDecision::SUPERSEDED])->save();

        $decision = $this->store($request, $outcome, RegistrationDecision::IN_FORCE, $input, $actor, $terms, $access);
        $this->auditDecision($request, $current, $decision, $actor);

        $request->forceFill(['info_requested_at' => null])->save();

        $registration = $this->advance->approved($request, (int) $actor->getKey(), $input['note'] ?? null, notify: false);
        $this->advance->tellDecision($registration, $decision);

        return ['registration' => $registration, 'decision' => $decision, 'replayed' => false, 'no_change' => false];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{registration: RegistrationRequest, decision: RegistrationDecision|null, replayed: bool, no_change: bool}
     */
    private function reject(RegistrationRequest $request, ?RegistrationDecision $current, array $input, User $actor, string $fingerprint): array
    {
        $current?->forceFill(['status' => RegistrationDecision::SUPERSEDED])->save();

        $decision = $this->store($request, RegistrationDecision::REJECT, RegistrationDecision::IN_FORCE, $input, $actor, ['fingerprint' => $fingerprint], null);

        return [
            'registration' => $this->advance->rejected($request, (string) $input['reason'], (int) $actor->getKey()),
            'decision' => $decision,
            'replayed' => false,
            'no_change' => false,
        ];
    }

    /**
     * Request info and defer: on the record, and they move no state and no commercial term.
     *
     * @param  array<string, mixed>  $input
     * @return array{registration: RegistrationRequest, decision: RegistrationDecision|null, replayed: bool, no_change: bool}
     */
    private function record(RegistrationRequest $request, array $input, User $actor, string $fingerprint): array
    {
        $decision = $this->store($request, (string) $input['outcome'], RegistrationDecision::RECORDED, $input, $actor, ['fingerprint' => $fingerprint], null);

        $this->audit->log(
            action: 'registration.decision.'.$input['outcome'],
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            after: ['decision' => (string) $decision->getKey()],
            reason: (string) $input['reason'],
            userId: (int) $actor->getKey(),
        );

        return ['registration' => $request, 'decision' => $decision, 'replayed' => false, 'no_change' => false];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $terms
     * @param  array<string, mixed>|null  $access
     */
    private function store(RegistrationRequest $request, string $outcome, string $status, array $input, User $actor, array $terms, ?array $access): RegistrationDecision
    {
        $decision = new RegistrationDecision;
        $decision->forceFill([
            'registration_request_id' => $request->getKey(),
            'outcome' => $outcome,
            'status' => $status,
            'actor_id' => $actor->getKey(),
            'reason' => (string) $input['reason'],
            'terms' => $terms,
            'access' => $access,
            'idempotency_key' => $input['idempotency_key'] ?? null,
        ])->save();

        return $decision->refresh();
    }

    private function auditDecision(RegistrationRequest $request, ?RegistrationDecision $before, RegistrationDecision $after, User $actor): void
    {
        $this->audit->log(
            action: 'registration.decision.made',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            before: $before === null ? null : ['decision' => (string) $before->getKey(), 'outcome' => $before->outcome, 'terms' => $before->terms, 'access' => $before->access],
            after: ['decision' => (string) $after->getKey(), 'outcome' => $after->outcome, 'status' => $after->status, 'terms' => $after->terms, 'access' => $after->access],
            reason: $after->reason,
            userId: (int) $actor->getKey(),
        );
    }

    /**
     * The commercial terms, computed from the catalogue on the server. A price from a browser is only ever compared.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function terms(RegistrationRequest $request, array $input, bool $previewing = false): array
    {
        $outcome = (string) $input['outcome'];

        if ($outcome === RegistrationDecision::JOIN_EXISTING) {
            return ['covered_by' => 'existing_subscription'];
        }

        $interval = (string) ($request->billing_interval ?? 'monthly');
        $plan = $this->catalogue->byCode($request->plan_code);
        $quote = $plan === null ? null : $this->catalogue->quote($plan, $interval);

        // Nothing is charged, so a plan sold only through sales (no list price on this term) is as good as any other.
        if ($outcome === RegistrationDecision::COMPLIMENTARY && $plan !== null && $quote === null) {
            return $this->complimentaryTerms(['plan_code' => $plan->code, 'interval' => $interval, 'currency' => $plan->currency], $input, $previewing);
        }

        if ($plan === null || $quote === null) {
            // Nothing to pay against is fine for plain approval, and a refusal for anything that names an amount.
            abort_if($outcome !== RegistrationDecision::PAYMENT_REQUIRED, 422, 'This application names no plan with a price on this term.');

            return ['plan_code' => $request->plan_code, 'interval' => $interval];
        }

        $due = number_format((float) $quote['due_now'], 2, '.', '');
        $base = [
            'plan_code' => $plan->code,
            'interval' => $interval,
            'currency' => $quote['currency'],
            'list_amount' => $due,
            'renewal_amount' => number_format((float) $plan->priceFor($interval), 2, '.', ''),
        ];

        return match ($outcome) {
            RegistrationDecision::DISCOUNT => $this->discountTerms($base, $due, (int) ($input['discount_percent'] ?? 0)),
            RegistrationDecision::WAIVER => $this->waiverTerms($base, $due, $interval, $input, $previewing),
            RegistrationDecision::TRIAL => $this->trialTerms($base, $input),
            RegistrationDecision::COMPLIMENTARY => $this->complimentaryTerms(array_diff_key($base, ['renewal_amount' => 1]), $input, $previewing),
            RegistrationDecision::EXTERNAL_SETTLEMENT => $this->settlementTerms($base, $due, $input),
            default => $base + ['amount_due' => $due, 'vat' => $this->vat($due)],
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function discountTerms(array $base, string $due, int $percent): array
    {
        if ($percent < 1 || $percent > 99) {
            throw ValidationException::withMessages(['discount_percent' => 'A discount is between 1 and 99 percent.']);
        }

        $amount = number_format(round((float) $due * (100 - $percent) / 100, 2), 2, '.', '');

        return $base + ['discount_percent' => $percent, 'amount_due' => $amount, 'vat' => $this->vat($amount), 'first_period_only' => true];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function waiverTerms(array $base, string $due, string $interval, array $input, bool $previewing): array
    {
        // Confirmed twice, on the server: the yes itself, and the exact amount this person believes they are giving away.
        // A preview is how they learn that amount, so it asks for neither.
        if (! $previewing) {
            if (($input['confirm_waiver'] ?? false) !== true) {
                throw ValidationException::withMessages(['confirm_waiver' => 'A fee waiver has to be confirmed explicitly.']);
            }

            if (($input['confirm_amount'] ?? null) === null) {
                throw ValidationException::withMessages(['confirm_amount' => 'State the exact amount being waived.']);
            }

            if (number_format((float) $input['confirm_amount'], 2, '.', '') !== $due) {
                throw new ConflictHttpException("The fee is now {$due} {$base['currency']}. Review it and confirm again.");
            }
        }

        $end = Carbon::now()->{$interval === 'annual' ? 'addYear' : 'addMonth'}();

        return $base + [
            'waived_by_admin' => true,
            'scope' => 'one_period',
            'fee' => $due,
            'amount_due' => '0.00',
            'period_start' => now()->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'renewal_on' => $end->toDateString(),
        ];
    }

    /**
     * «دخول مباشر دون دفع». Deliberately no period, no renewal date and no renewal price: there is nothing to come due.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function complimentaryTerms(array $base, array $input, bool $previewing): array
    {
        if (! $previewing && ($input['confirm_complimentary'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirm_complimentary' => 'Direct entry with no payment has to be confirmed explicitly.']);
        }

        return $base + ['complimentary' => true, 'scope' => 'open_ended', 'amount_due' => '0.00'];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function trialTerms(array $base, array $input): array
    {
        $ends = isset($input['trial_ends_on']) ? Carbon::parse((string) $input['trial_ends_on'])->endOfDay() : null;

        if ($ends === null || $ends->isPast() || $ends->gt(Carbon::now()->addYear())) {
            throw ValidationException::withMessages(['trial_ends_on' => 'A trial needs an end date within the coming year.']);
        }

        return $base + [
            'amount_due' => '0.00',
            'trial_ends_at' => $ends->toIso8601String(),
            'renewal_on' => $ends->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function settlementTerms(array $base, string $due, array $input): array
    {
        $evidence = (array) ($input['evidence'] ?? []);

        foreach (['reference', 'amount', 'currency', 'paid_on'] as $field) {
            if (blank($evidence[$field] ?? null)) {
                throw ValidationException::withMessages(["evidence.{$field}" => 'A settlement needs its reference, amount, currency and date.']);
            }
        }

        if (strtoupper((string) $evidence['currency']) !== $base['currency'] || (float) $evidence['amount'] < (float) $due) {
            throw ValidationException::withMessages(['evidence.amount' => "The evidence does not cover {$due} {$base['currency']}."]);
        }

        return $base + ['amount_due' => $due, 'vat' => $this->vat($due), 'evidence' => $evidence];
    }

    /** @return array{treatment: string, rate: float, tax: string} */
    private function vat(string $amount): array
    {
        $treatment = TaxTreatment::DEFAULT;

        return [
            'treatment' => $treatment,
            'rate' => TaxTreatment::rate($treatment),
            'tax' => number_format(TaxTreatment::taxFor($treatment, (float) $amount), 2, '.', ''),
        ];
    }

    /** @return array<string, mixed>|null */
    private function discountedQuote(RegistrationRequest $request, RegistrationDecision $decision): ?array
    {
        $plan = $this->catalogue->byCode($request->plan_code);
        $quote = $plan === null ? null : $this->catalogue->quote($plan, (string) ($request->billing_interval ?? 'monthly'));

        if ($quote === null) {
            return null;
        }

        $terms = (array) $decision->terms;
        $saved = (float) $terms['list_amount'] - (float) $terms['amount_due'];
        $quote['due_now'] = $terms['amount_due'];

        if (($quote['total_committed'] ?? null) !== null) {
            $quote['total_committed'] = number_format((float) $quote['total_committed'] - $saved, 2, '.', '');
        }

        return $quote;
    }

    /**
     * The access scope. Everything named is checked to exist in the target, and nothing here can reach the platform.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function access(RegistrationRequest $request, array $input): array
    {
        $access = (array) ($input['access'] ?? []);
        $joining = ($input['outcome'] ?? null) === RegistrationDecision::JOIN_EXISTING;
        $mode = $joining ? 'join_existing' : 'new_workspace';

        // The two go together: a mode that disagreed with the outcome would be a decision nobody can read.
        if (($access['mode'] ?? $mode) !== $mode) {
            throw ValidationException::withMessages(['access.mode' => 'Joining an existing workspace is its own decision.']);
        }

        $offered = collect(Portal::offeredMembershipPortals())
            ->reject(fn (Portal $portal) => $portal === Portal::ClientPortal)
            ->map(fn (Portal $portal) => $portal->value)->values()->all();

        $portal = $access['portal'] ?? null;
        if ($portal !== null && ! in_array($portal, $offered, true)) {
            throw ValidationException::withMessages(['access.portal' => 'That portal cannot be granted here.']);
        }

        if (! $joining) {
            $modules = $access['modules'] ?? null;
            if ($modules !== null && array_diff((array) $modules, ['paid_media', 'influencer_marketing']) !== []) {
                throw ValidationException::withMessages(['access.modules' => 'Unknown module.']);
            }
            if ($modules === []) {
                throw ValidationException::withMessages(['access.modules' => 'A workspace needs at least one module.']);
            }

            return ['mode' => $mode, 'portal' => $portal, 'modules' => $modules === null ? null : array_values((array) $modules)];
        }

        $tenantId = (string) ($access['tenant_id'] ?? '');
        $tenant = Str::isUuid($tenantId) ? Tenant::query()->withoutGlobalScopes()->find($tenantId) : null;

        if ($tenant === null || ! $this->gate->tenantInGoodStanding($tenantId)) {
            throw ValidationException::withMessages(['access.tenant_id' => 'That workspace is not active with a subscription in good standing.']);
        }

        // A workspace always keeps an accountable owner, and this path never makes a new one.
        $hasOwner = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', fn ($join) => $join->on('memberships.user_id', '=', 'role_user.user_id')->where('memberships.tenant_id', '=', $tenantId))
            ->where('roles.tenant_id', $tenantId)->where('roles.slug', 'tenant-owner')->where('memberships.status', 'active')
            ->exists();
        if (! $hasOwner) {
            throw ValidationException::withMessages(['access.tenant_id' => 'That workspace has no active owner to answer for this person.']);
        }

        $slug = (string) ($access['role'] ?? '');
        if (in_array($slug, self::NEVER_BY_JOINING, true)
            || ! Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['access.role' => 'That role cannot be granted in this workspace.']);
        }

        if (! app(SubscriptionService::class)->withinLimit($tenant, 'team_members')) {
            throw ValidationException::withMessages(['access.tenant_id' => 'That workspace has no team seat left on its plan.']);
        }

        $projectIds = array_values(array_map('strval', (array) ($access['project_ids'] ?? [])));
        $clientIds = array_values(array_map('strval', (array) ($access['client_ids'] ?? [])));

        // withoutGlobalScopes and an explicit tenant: an id from another workspace is refused, and never reached.
        if (Project::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $projectIds)->count() !== count($projectIds)) {
            throw ValidationException::withMessages(['access.project_ids' => 'A project does not belong to that workspace.']);
        }
        if (ClientWorkspace::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $clientIds)->count() !== count($clientIds)) {
            throw ValidationException::withMessages(['access.client_ids' => 'A client does not belong to that workspace.']);
        }

        return [
            'mode' => $mode,
            'tenant_id' => $tenantId,
            'portal' => $portal ?? Portal::forAccountType($tenant->account_type)->value,
            'role' => $slug,
            'project_ids' => $projectIds,
            'client_ids' => $clientIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $access
     * @return array<string, mixed>
     */
    private function describeAccess(RegistrationRequest $request, array $access): array
    {
        if ($access['mode'] === 'new_workspace') {
            return [
                'mode' => 'new_workspace',
                'portal' => $access['portal'] ?? $request->requested_portal,
                'role' => 'owner',
                'modules' => $access['modules'] ?? null,
                'permissions' => 'all_in_own_workspace',
                'creates' => ['tenant' => 1, 'workspace' => 1, 'membership' => 1, 'project' => 1],
                'who_has_access' => [(string) $request->email],
            ];
        }

        $tenantId = (string) $access['tenant_id'];
        /** @var Role $role */
        $role = Role::query()->where('tenant_id', $tenantId)->where('slug', $access['role'])->firstOrFail();

        return [
            'mode' => 'join_existing',
            'tenant_id' => $tenantId,
            'tenant_name' => Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->value('name'),
            'portal' => $access['portal'],
            'role' => $access['role'],
            'permissions' => $role->permissions()->pluck('key')->all(),
            'projects' => Project::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $access['project_ids'])->pluck('name')->all(),
            'clients' => ClientWorkspace::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $access['client_ids'])->pluck('name')->all(),
            'creates' => ['tenant' => 0, 'workspace' => 0, 'membership' => 1, 'project' => 0],
            'who_has_access' => [(string) $request->email],
        ];
    }

    private function providerReady(): bool
    {
        $registry = app(SubscriptionProviderRegistry::class);

        return $registry->for($registry->defaultKey())->isConfigured();
    }

    /**
     * What makes two decisions the same decision, leaving out the clock.
     *
     * @param  array<string, mixed>  $input
     */
    private function fingerprint(string $outcome, array $input): string
    {
        return hash('sha256', (string) json_encode([
            $outcome,
            $input['discount_percent'] ?? null,
            $input['trial_ends_on'] ?? null,
            $input['evidence'] ?? null,
            $input['access'] ?? null,
            in_array($outcome, [RegistrationDecision::REQUEST_INFO, RegistrationDecision::DEFER], true) ? $input['reason'] ?? null : null,
        ]));
    }
}
