<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Http\Controllers;

use App\Domains\Accounts\Actions\StartRegistration;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Accounts\Services\RegistrationVerificationService;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Identity\Http\Requests\RegisterRequest;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Tenancy\Enums\Portal;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The public face of the gated registration path (SIGNUP-002).
 *
 * Every endpoint here is reachable without a session, because an applicant has no account to sign in
 * to — that is the point. What each one can do is correspondingly narrow: submit an application,
 * read the status of an application whose id you already hold, and answer a challenge with the secret
 * we sent. None of them can advance an application on its own; they record a fact and
 * `AdvanceRegistration` decides what follows.
 */
final class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationVerificationService $verification,
        private readonly RegistrationPolicy $policy,
    ) {}

    /**
     * POST /auth/register — apply.
     *
     * 202, not 201. Nothing was created that the applicant can use, and answering "Created" to a
     * request that produced no account is precisely the false claim this unit exists to remove.
     */
    public function store(RegisterRequest $request, StartRegistration $start): JsonResponse
    {
        $validated = $request->validated();

        $result = $start->execute(
            RegisterData::fromArray($validated),
            requestedPortal: isset($validated['requested_portal'])
                ? Portal::tryFrom((string) $validated['requested_portal'])?->value
                : null,
            planCode: isset($validated['plan_code']) ? (string) $validated['plan_code'] : null,
            billingInterval: isset($validated['billing_interval']) ? (string) $validated['billing_interval'] : null,
        );

        /** @var RegistrationRequest $registration */
        $registration = $result['request'];

        return ApiResponse::success(
            [
                'registration' => $registration->statusPayload($this->ar($request)),
                'verification' => $result['verification'],
                // What still stands between this applicant and a workspace, stated up front so the
                // status screen can say "an administrator will review this" before they wonder.
                'policy' => $this->policy->for($registration),
            ],
            'Your application has been received. Confirm your email address to continue.',
            status: 202,
        );
    }

    /** GET /auth/registration/{registration} — the applicant's own status screen. */
    public function show(Request $request, RegistrationRequest $registration): JsonResponse
    {
        return ApiResponse::success(
            [
                'registration' => $registration->statusPayload($this->ar($request)),
                'policy' => $this->policy->for($registration),
            ],
            'Registration status.',
        );
    }

    /**
     * POST /auth/registration/verify-email — consume an emailed link token.
     *
     * When this clears the last gate the workspace is created, and the applicant is signed in: they
     * have just proved control of the address, which is the same evidence a magic link carries.
     * When it does NOT clear the last gate, no session is created and the response says what is
     * still outstanding.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $registration = $this->verification->verifyEmail($data['token']);

        return $this->afterAdvance($request, $registration, 'Email verified.');
    }

    /** POST /auth/registration/{registration}/verify-mobile — answer the OTP. */
    public function verifyMobile(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $registration = $this->verification->verifyMobile($registration, $data['code']);

        return $this->afterAdvance($request, $registration, 'Mobile number verified.');
    }

    /** POST /auth/registration/{registration}/resend — a new challenge on one channel. */
    public function resend(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:email,mobile']]);

        // A provisioned or finished application has nothing left to prove; re-issuing a challenge
        // for one would mint a token that can only ever be a nuisance.
        if ($registration->isProvisioned() || $registration->state->isTerminal()) {
            return ApiResponse::success(
                ['registration' => $registration->statusPayload($this->ar($request))],
                'There is nothing left to verify for this application.',
            );
        }

        if ($data['channel'] === 'mobile' && $registration->phone === null) {
            return ApiResponse::error(__('accounts.no_mobile_on_file'), status: 422);
        }

        return ApiResponse::success(
            [
                'registration' => $registration->statusPayload($this->ar($request)),
                'verification' => $this->verification->send($registration, $data['channel']),
            ],
            'A new verification was issued.',
        );
    }

    /**
     * The shared answer after a fact has been recorded: where the application now stands, plus a
     * session ONLY if it actually became a workspace.
     */
    private function afterAdvance(Request $request, RegistrationRequest $registration, string $message): JsonResponse
    {
        $payload = [
            'registration' => $registration->statusPayload($this->ar($request)),
            'policy' => $this->policy->for($registration),
            'user' => null,
        ];

        if ($registration->isProvisioned()) {
            /** @var User|null $user */
            $user = User::where('email', $registration->email)->first();

            if ($user !== null) {
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                $payload['user'] = new UserResource($user);
            }
        }

        return ApiResponse::success($payload, $message);
    }

    private function ar(Request $request): bool
    {
        return ! str_starts_with(mb_strtolower($request->header('Accept-Language', 'ar')), 'en');
    }
}
