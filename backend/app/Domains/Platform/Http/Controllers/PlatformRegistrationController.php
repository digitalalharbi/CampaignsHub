<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $this->refuseIfSettled($registration);

        $registration->forceFill(['info_requested_at' => null])->save();

        $registration = $this->advance->approved(
            $registration->refresh(),
            reviewerId: $request->user()?->id,
            note: $data['note'] ?? null,
        );

        return ApiResponse::success(
            ['registration' => $this->row($registration, $request), 'policy' => $this->policy->for($registration)],
            $registration->isProvisioned()
                ? 'Approved. The workspace has been created.'
                : 'Approved. The application is now waiting on payment.',
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

        $registration = $this->advance->rejected($registration, $data['reason'], $request->user()?->id);

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
        } catch (\Throwable $e) {
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
            'created_at' => $registration->created_at?->toIso8601String(),
            'tenant_id' => $registration->tenant_id,
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
