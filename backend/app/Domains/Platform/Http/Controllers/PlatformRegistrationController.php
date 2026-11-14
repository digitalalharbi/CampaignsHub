<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationSendLog;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Accounts\Services\RegistrationDecisionService;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Accounts\Services\RegistrationVerificationService;
use App\Domains\Accounts\Services\RegistrationVerificationSettings;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The registration review queue (SIGNUP-003).
 *
 * The other half of the gated path: an application that stops at `pending_approval` has to be
 * decidable by a person, and until this existed the only way to approve one was to edit the database
 * by hand — which is another way of saying the approval gate could not actually be turned on.
 *
 * A reviewer can approve, refuse, ask for more, or change the terms. What they cannot do is activate
 * an account: `approve` clears the APPROVAL gate and nothing else, so an application that also owes
 * money moves to `approved_awaiting_payment` and waits there for a confirmed payment. That
 * separation is the point of having two states rather than one, and it is why this controller never
 * touches `ProvisionWorkspace` itself.
 */
final class PlatformRegistrationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AdvanceRegistration $advance,
        private readonly RegistrationPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly SubscriptionNotifier $notify,
        private readonly RegistrationVerificationSettings $verificationSettings,
        private readonly RegistrationDecisionService $decisions,
        private readonly RegistrationVerificationService $verification,
    ) {}

    /** GET /api/v1/admin/registrations */
    public function index(Request $request): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $query = RegistrationRequest::query()->latest('created_at');

        $state = (string) $request->query('state', 'open');
        if ($state === 'open') {
            // The default view is "what is waiting for me" — everything a decision could still move.
            $query->whereIn('state', [
                AccountState::PendingApproval->value,
                AccountState::MobileVerificationRequired->value,
                AccountState::EmailVerificationRequired->value,
                AccountState::ApprovedAwaitingPayment->value,
                AccountState::PaymentPending->value,
            ])->whereNull('tenant_id');
        } elseif ($state !== 'all' && $state !== '') {
            $query->where('state', $state);
        }

        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(tenant_name) like ?', [$like])
                    ->orWhereRaw('lower(name) like ?', [$like]);
            });
        }

        $page = $query->paginate(25);

        return ApiResponse::success([
            'registrations' => collect($page->items())
                ->map(fn (RegistrationRequest $r) => $this->row($r, $request))->all(),
            'meta' => [
                'total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(),
            ],
            // The queue's own headline: how many applications are held at each gate right now.
            'counts' => $this->counts(),
        ], 'Registration requests.');
    }

    /** GET /api/v1/admin/registrations/{registration} */
    public function show(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success([
            'registration' => $this->row($registration, $request),
            'policy' => $this->policy->for($registration),
            // Every recorded decision about this application, in order. A reviewer deciding whether
            // to approve should be able to see what has already been done and by whom.
            'transitions' => $this->transitions($registration),
        ], 'Registration request.');
    }

    /** POST /api/v1/admin/registrations/{registration}/approve */
    public function approve(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        // The plain answer on the decision form: approved, and the payment is still owed.
        $result = $this->decisions->decide($registration, [
            'outcome' => RegistrationDecision::PAYMENT_REQUIRED,
            'reason' => $data['note'] ?? 'Approved: payment required.',
            'note' => $data['note'] ?? null,
        ], $request->user());
        $registration = $result['registration'];

        return ApiResponse::success(
            ['registration' => $this->row($registration, $request), 'policy' => $this->policy->for($registration), 'no_change' => $result['no_change']],
            match (true) {
                // Said in words: an unchanged screen after a click reads as a broken button.
                $result['no_change'] => 'Already approved. Nothing changed: what is outstanding is the payment.',
                $registration->isProvisioned() => 'Approved. The workspace has been created.',
                default => 'Approved. The application is now waiting on payment.',
            },
        );
    }

    /** POST /api/v1/admin/registrations/{registration}/reject */
    public function reject(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        // A reason is REQUIRED, because the applicant is shown it. "Rejected" with no explanation is
        // the kind of dead end that turns into a support ticket we cannot answer either.
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->refuseIfSettled($registration);

        $registration = $this->decisions->decide($registration, [
            'outcome' => RegistrationDecision::REJECT, 'reason' => $data['reason'],
        ], $request->user())['registration'];

        return ApiResponse::success(['registration' => $this->row($registration, $request)], 'Application rejected.');
    }

    /**
     * POST /api/v1/admin/registrations/{registration}/request-info
     *
     * Hands the application back to the applicant without deciding it. The state does not change —
     * it is still pending approval — but the note becomes their next step, so the queue stops
     * waiting on a reviewer who is waiting on the customer.
     */
    public function requestInfo(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->refuseIfSettled($registration);

        $registration->forceFill([
            'review_note' => $data['note'],
            'info_requested_at' => now(),
            'reviewed_by' => $request->user()?->id,
        ])->save();

        // The applicant is told what is needed — otherwise the queue stalls with neither side
        // expecting to move, which is the failure "request information" exists to avoid.
        try {
            $this->notify->notifyApplicant($registration->refresh(), 'registration_information_requested', [
                'reason' => $data['note'],
                'url' => Frontend::origin()
                    .'/signup/status?request='.$registration->getKey(),
            ], occasion: $registration->getKey().':'.now()->toDateString());
        } catch (Throwable $e) {
            report($e);
        }

        $this->audit->log(
            action: 'registration.info_requested',
            entityType: RegistrationRequest::class,
            entityId: (string) $registration->getKey(),
            after: ['note' => $data['note']],
        );

        return ApiResponse::success(
            ['registration' => $this->row($registration->refresh(), $request)],
            'The applicant has been asked for more information.',
        );
    }

    /** @return array<string, mixed> */
    private function decisionInput(Request $request): array
    {
        return $request->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', [...RegistrationDecision::APPROVALS, RegistrationDecision::REQUEST_INFO, RegistrationDecision::DEFER, RegistrationDecision::REJECT])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'expected_decision_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'discount_percent' => ['required_if:outcome,'.RegistrationDecision::DISCOUNT, 'nullable', 'integer', 'min:1', 'max:99'],
            'trial_ends_on' => ['required_if:outcome,'.RegistrationDecision::TRIAL, 'nullable', 'date'],
            'confirm_waiver' => ['sometimes', 'boolean'],
            'confirm_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'confirm_complimentary' => ['sometimes', 'boolean'],
            'evidence' => ['required_if:outcome,'.RegistrationDecision::EXTERNAL_SETTLEMENT, 'nullable', 'array'],
            'evidence.reference' => ['sometimes', 'string', 'max:190'],
            'evidence.amount' => ['sometimes', 'numeric', 'min:0'],
            'evidence.currency' => ['sometimes', 'string', 'size:3'],
            'evidence.paid_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'evidence.note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'access' => ['sometimes', 'nullable', 'array'],
            'access.mode' => ['sometimes', 'string', 'in:new_workspace,join_existing'],
            'access.portal' => ['sometimes', 'nullable', 'string', 'max:32'],
            'access.modules' => ['sometimes', 'nullable', 'array'],
            'access.modules.*' => ['string', 'max:64'],
            'access.tenant_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'access.role' => ['sometimes', 'nullable', 'string', 'max:64'],
            'access.project_ids' => ['sometimes', 'nullable', 'array'],
            'access.project_ids.*' => ['string', 'uuid'],
            'access.client_ids' => ['sometimes', 'nullable', 'array'],
            'access.client_ids.*' => ['string', 'uuid'],
        ]);
    }

    /** GET /api/v1/admin/tenants/{tenant}/decision-targets — what a person joining this workspace could be given. */
    public function decisionTargets(Tenant $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $tenantId = (string) $tenant->getKey();

        return ApiResponse::success([
            'roles' => Role::query()->where('tenant_id', $tenantId)->where('slug', '!=', 'tenant-owner')->get(['slug', 'name'])
                ->map(fn (Role $r) => ['slug' => $r->slug, 'name' => $r->name])->values()->all(),
            'projects' => Project::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'name'])
                ->map(fn (Project $p) => ['id' => (string) $p->id, 'name' => $p->name])->values()->all(),
            'clients' => ClientWorkspace::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'name'])
                ->map(fn (ClientWorkspace $c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->all(),
        ], 'Decision targets.');
    }

    /** POST /api/v1/admin/registrations/{registration}/decision/preview — what a decision would do. Writes nothing. */
    public function previewDecision(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success($this->decisions->preview($registration, $this->decisionInput($request)), 'Decision preview.');
    }

    /** POST /api/v1/admin/registrations/{registration}/decision — the one decision form (SIGNUP-DECISION-001). */
    public function decide(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $input = $this->decisionInput($request);

        if ($input['outcome'] === RegistrationDecision::REQUEST_INFO) {
            $request->merge(['note' => $input['reason']]);

            return $this->requestInfo($request, $registration);
        }

        $result = $this->decisions->decide($registration, $input, $request->user());
        $registration = $result['registration']->refresh();

        return ApiResponse::success([
            'registration' => $this->row($registration, $request),
            'policy' => $this->policy->for($registration),
            'decision' => $result['no_change'] && $result['decision'] === null ? null : [
                'id' => (string) $result['decision']?->getKey(), 'outcome' => $result['decision']?->outcome, 'status' => $result['decision']?->status,
            ],
            'replayed' => $result['replayed'],
            'no_change' => $result['no_change'],
        ], $result['no_change'] ? 'Already approved. Nothing changed: what is outstanding is the payment.' : 'The decision has been recorded.');
    }

    /** POST /api/v1/admin/registrations/{registration}/decisions/{decision}/verify-settlement */
    public function verifySettlement(Request $request, RegistrationRequest $registration, RegistrationDecision $decision): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $result = $this->decisions->verifySettlement($registration, $decision, $request->user(), $data['reason']);

        return ApiResponse::success([
            'registration' => $this->row($result['registration']->refresh(), $request),
            'policy' => $this->policy->for($result['registration']),
        ], 'The settlement has been confirmed.');
    }

    /**
     * PATCH /api/v1/admin/registrations/{registration}
     *
     * The terms of THIS application: which plan it is for, and which gates it must clear. Recorded as
     * a concession with a reason rather than applied to a state column, so the decision keeps its
     * author and its justification.
     */
    public function updateTerms(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $data = $request->validate([
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'requires_mobile' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'requires_payment' => ['sometimes', 'boolean'],
            'discount_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->refuseIfSettled($registration);

        /*
         * A commercial concession is a scoped decision now (SIGNUP-DECISION-001). These three only ever stored a value that
         * nothing read, or a bare `false` with no plan, period or amount, so they are refused rather than appearing to work.
         */
        abort_if(
            ($data['requires_payment'] ?? null) === false
                || (int) ($data['discount_percent'] ?? 0) > 0
                || (int) ($data['trial_days'] ?? 0) > 0,
            422,
            'Discounts, fee waivers and trials are recorded through the registration decision, with their exact terms.',
        );

        // A mobile gate with no number on file is a step nobody could ever finish.
        abort_if(
            ($data['requires_mobile'] ?? false) === true && $registration->phone === null,
            422,
            __('accounts.no_mobile_on_file'),
        );

        $concessions = $registration->review_concessions ?? [];
        $overrides = (array) ($concessions['policy'] ?? []);

        foreach (['requires_mobile', 'requires_approval', 'requires_payment'] as $gate) {
            if (array_key_exists($gate, $data)) {
                $overrides[$gate] = (bool) $data[$gate];
            }
        }

        $concessions['policy'] = $overrides;
        $concessions['reason'] = $data['reason'];
        $concessions['decided_by'] = $request->user()?->id;
        // Stamped by the caller rather than read from the row, so the record says WHEN as well as what.
        $concessions['decided_at'] = now()->toIso8601String();
        foreach (['discount_percent', 'trial_days'] as $commercial) {
            if (array_key_exists($commercial, $data)) {
                $concessions[$commercial] = $data[$commercial];
            }
        }

        $registration->forceFill([
            'review_concessions' => $concessions,
        ] + (array_key_exists('plan_code', $data) ? ['plan_code' => $data['plan_code']] : []))->save();

        $this->audit->log(
            action: 'registration.terms_changed',
            entityType: RegistrationRequest::class,
            entityId: (string) $registration->getKey(),
            after: $concessions,
            reason: $data['reason'],
        );

        $registration->refresh();

        return ApiResponse::success(
            ['registration' => $this->row($registration, $request), 'policy' => $this->policy->for($registration)],
            'The terms of this application have been updated.',
        );
    }

    /**
     * POST /api/v1/admin/registrations/{registration}/reevaluate-verification
     *
     * An application keeps the verification policy it was opened under (SIGNUP-VERIFY-POLICY-001). This is the one way
     * to move it to today's, for this application only and with a reason: a global switch never does it silently.
     */
    public function reevaluateVerification(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $this->refuseIfSettled($registration);

        $snapshot = $this->verificationSettings->snapshot();

        $registration = DB::transaction(function () use ($registration, $snapshot, $data, $request): RegistrationRequest {
            /** @var RegistrationRequest $locked */
            $locked = RegistrationRequest::query()->lockForUpdate()->findOrFail($registration->getKey());
            $this->refuseIfSettled($locked);

            // A mobile gate with no number on file is a step nobody could ever finish, so it is refused rather than made.
            $probe = clone $locked;
            $probe->effective_policy = $snapshot;
            abort_if(
                $locked->phone === null && $this->policy->for($probe)['requires_mobile'],
                422,
                __('accounts.no_mobile_on_file'),
            );

            return $this->advance->reevaluateVerification($locked, $snapshot, $data['reason'], $request->user()?->id);
        });

        return ApiResponse::success(
            ['registration' => $this->row($registration, $request), 'policy' => $this->policy->for($registration)],
            'This application now follows the current verification policy.',
        );
    }

    /**
     * POST /api/v1/admin/registrations/{registration}/resend-verification — PLATFORM-INBOX-ATTENTION-001.
     *
     * Three applications sat at «بانتظار تأكيد الجوال» on Production for months with nothing the console could do but
     * look at them. This issues a fresh challenge on the step the application is actually held at, through the same
     * `RegistrationVerificationService::send()` the applicant's own «resend» uses — so the same cooldown, the same
     * per-application and platform send caps, and the same honest delivery outcome.
     *
     * What it never does: verify anything, create an account, or show the administrator the code or link. The
     * applicant still has to prove the address or number themselves. Audited with the outcome.
     */
    public function resendVerification(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $this->refuseIfSettled($registration);

        $channel = match ($registration->state) {
            AccountState::EmailVerificationRequired => 'email',
            AccountState::MobileVerificationRequired => 'mobile',
            default => null,
        };

        abort_if($channel === null, 422, __('accounts.nothing_to_verify'));
        abort_if($channel === 'mobile' && $registration->phone === null, 422, __('accounts.no_mobile_on_file'));

        /*
         * The applicant's own path has a cooldown on mobile only. From the console, every channel is bounded: three per
         * application per hour, so a held application cannot be turned into a stream of SMS (cost) or of replaced email
         * links (each send retires the link the applicant holds). `hit()` returns the new count — one step.
         */
        $key = 'admin-resend-verification:'.$registration->getKey();
        if (RateLimiter::hit($key, 3600) > 3) {
            abort(429, __('api.too_many_requests'));
        }

        try {
            $issued = $this->verification->send($registration, $channel);
        } catch (Throwable $e) {
            // A refusal before anything was sent (the mobile cooldown) does not use up one of the three.
            RateLimiter::decrement($key, 3600);

            throw $e;
        }

        $this->audit->log('registration.verification_resent', RegistrationRequest::class, (string) $registration->getKey(),
            null, ['channel' => $channel, 'delivery_status' => $issued['delivery_status']]);

        return ApiResponse::success([
            'registration' => $this->row($registration->refresh(), $request),
            // The outcome only. `dev_code` / `dev_link` are the applicant's secret even outside production.
            'verification' => [
                'channel' => $channel,
                'delivery_status' => $issued['delivery_status'],
                'expires_at' => $issued['expires_at'],
            ],
        ], $issued['delivery_status'] === RegistrationVerificationService::BLOCKED
            ? 'No message was sent: the sending limit has been reached.'
            : 'A new verification was issued.');
    }

    /**
     * Where an application held at a verification step actually is: which step, since when, how many times a
     * challenge went out and what the provider answered the last time. Null when it is not held at one.
     *
     * @return array{channel: string, since: string|null, sends: int, last_sent_at: string|null, last_outcome: string|null}|null
     */
    private function verificationStage(RegistrationRequest $registration): ?array
    {
        $channel = match ($registration->state) {
            AccountState::EmailVerificationRequired => 'email',
            AccountState::MobileVerificationRequired => 'mobile',
            default => null,
        };

        if ($channel === null || $registration->isProvisioned()) {
            return null;
        }

        $sends = RegistrationSendLog::query()->where('registration_request_id', $registration->getKey())->where('channel', $channel);
        /** @var RegistrationSendLog|null $last */
        $last = (clone $sends)->latest('created_at')->latest('id')->first();

        return [
            'channel' => $channel,
            'since' => ($registration->state_changed_at ?? $registration->created_at)?->toIso8601String(),
            'sends' => (clone $sends)->count(),
            'last_sent_at' => $last?->created_at?->toIso8601String(),
            'last_outcome' => $last?->outcome,
        ];
    }

    /**
     * A decided application is not re-decidable here.
     *
     * Approving something that already has a workspace would run the whole path again; rejecting one
     * would leave a live tenant behind a `rejected` record. Both are recoverable states to be in and
     * neither is one this queue should be able to create by accident.
     */
    private function refuseIfSettled(RegistrationRequest $registration): void
    {
        abort_if(
            $registration->isProvisioned(),
            422,
            'This application has already become a workspace. Manage the account from Tenants instead.',
        );
        abort_if(
            $registration->state->isTerminal(),
            422,
            'This application has already been closed and cannot be reviewed again.',
        );
    }

    /** @return array<string, mixed> */
    private function row(RegistrationRequest $registration, Request $request): array
    {
        $ar = ! str_starts_with(mb_strtolower($request->header('Accept-Language', 'ar')), 'en');

        return $registration->statusPayload($ar) + [
            // What a reviewer needs and an applicant is not shown.
            'name' => $registration->name,
            'tenant_name' => $registration->tenant_name,
            'account_type' => $registration->account_type,
            'phone' => $registration->phone,
            'review_note' => $registration->review_note,
            'info_requested' => $registration->info_requested_at !== null,
            'reviewed_at' => $registration->reviewed_at?->toIso8601String(),
            'reviewed_by' => $registration->reviewed_by,
            'concessions' => $registration->review_concessions,
            'effective_policy' => $registration->effective_policy,
            // Every decision, newest first, so a second administrator can find a settlement waiting on them.
            'decisions' => RegistrationDecision::query()->where('registration_request_id', $registration->getKey())
                ->latest('created_at')->limit(20)->get()
                ->map(fn (RegistrationDecision $d) => [
                    'id' => (string) $d->getKey(), 'outcome' => $d->outcome, 'status' => $d->status,
                    'actor_id' => $d->actor_id, 'reason' => $d->reason, 'terms' => $d->terms, 'access' => $d->access,
                    'verified_by' => $d->verified_by, 'created_at' => $d->created_at?->toIso8601String(),
                ])->all(),
            'created_at' => $registration->created_at?->toIso8601String(),
            'tenant_id' => $registration->tenant_id,
            'verification_stage' => $this->verificationStage($registration),
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        /** @var array<string, int> $rows */
        $rows = RegistrationRequest::query()->whereNull('tenant_id')
            ->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state')->all();

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function transitions(RegistrationRequest $registration): array
    {
        return AuditLog::query()
            ->where('entity_type', RegistrationRequest::class)
            ->where('entity_id', (string) $registration->getKey())
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'at' => $log->created_at?->toIso8601String(),
                'user_id' => $log->user_id,
                'reason' => $log->reason,
                'detail' => $log->after,
            ])->all();
    }
}
