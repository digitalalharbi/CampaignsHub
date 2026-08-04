<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PhoneNumberRule;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * LOGIN-PATHS-001 — signing in with a mobile number and a one-time code.
 *
 * The second of the two paths `/login` offers, and the reason it needed its own endpoints rather than
 * reusing the client portal's: `/client/login/verify` opens a PORTAL session for a contact. Pointing a
 * platform user's phone at it would have signed them into `/portal` — a space they hold nothing in —
 * and the page would have looked like it worked.
 *
 * ## What it is, precisely
 *
 * A second credential for an account that already exists, not a second way to create one. The number
 * must already be on a user, verified during registration (PHONE-VERIFY-001), which is what makes it
 * safe to treat a code sent to it as proof of who is holding the phone.
 *
 * ## No enumeration
 *
 * `start` answers the same way for a number nobody holds as for one somebody does: a verification id
 * and an honest delivery status. It has to — the alternative tells anybody with a phone book which
 * numbers have accounts here. What differs is `verify`, which fails for a code that was never sent to
 * a real account, exactly as a wrong code does.
 *
 * ## Every reading of a number is the same number
 *
 * `05…`, `9665…`, `+9665…`, Arabic-Indic digits, spaces and dashes all normalise to E.164 before
 * anything is looked up or sent — so the code goes to one destination and matches one account, rather
 * than to whichever spelling the customer happened to use today.
 */
final class PhoneSignInController extends Controller
{
    /** The verification purpose, kept distinct so a portal code cannot be replayed here. */
    private const PURPOSE = 'phone_sign_in';

    public function __construct(private readonly ContactVerificationService $verification) {}

    /** POST /auth/phone/start — send a one-time code to a mobile number. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40', new PhoneNumberRule],
        ]);

        $e164 = PhoneNumber::normalise((string) $data['phone']);

        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => [__('validation.phone_number')]]);
        }

        /*
         * The code is issued whether or not the number belongs to anybody.
         *
         * Deliberate. Issuing only for known numbers would turn this endpoint into a directory of who
         * has an account here, answerable by anybody with a list of numbers. The cost is a code sent
         * to a stranger's phone once — which the throttle bounds — against telling everybody which of
         * their contacts is a customer.
         */
        $result = $this->verification->start('sms', $e164, self::PURPOSE);

        return ApiResponse::success([
            'verification_id' => $result['id'],
            'delivery_status' => $result['delivery_status'],
            // Non-production only; hard-gated inside the verification service.
            'dev_code' => $result['dev_code'],
        ], __('auth.code_sent'));
    }

    /**
     * POST /auth/phone/verify — check the code and open a session.
     *
     * Two things have to hold, and they are checked in this order: the code is genuinely the one we
     * sent to that number, and the number belongs to an account that may sign in. A failure in either
     * gives the same answer, because "the code was right but you have no account" is a sentence that
     * confirms a number is not registered.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $verification = $this->verification->verify($data['verification_id'], (string) $data['code']);

        // A code minted for the client portal is not a platform credential, whatever it verifies.
        abort_unless($verification->purpose === self::PURPOSE, 422);

        /*
         * The code is spent here, before it can buy a second session.
         *
         * `verify()` marks a challenge VERIFIED and leaves it usable — which is right for the flows
         * that verify once and then consume the proof later, but wrong for a credential. Without this
         * the same six digits opened a session every time they were posted, so a code read over
         * somebody's shoulder stayed live for its whole TTL.
         */
        if ($verification->consumed_at !== null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $verification->forceFill(['consumed_at' => now()])->save();

        $e164 = PhoneNumber::normalise((string) $verification->destination);

        /** @var User|null $user */
        $user = $e164 === null ? null : User::query()->where('phone', $e164)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $this->assertMaySignIn($user);

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        /*
         * No portal is claimed here, exactly as with the password path.
         *
         * The destination comes from real memberships once the session exists, so a phone code cannot
         * be used to ask for access the account does not hold.
         */
        return ApiResponse::success(['user' => new UserResource($user)], __('auth.signed_in'));
    }

    /**
     * A disabled account, or one whose every workspace is suspended, may not sign in by phone either.
     *
     * The SAME rule the password path applies, expressed through the same helper — a second
     * credential with a second, laxer opinion about suspension would be a way around it rather than
     * a second way in.
     */
    private function assertMaySignIn(User $user): void
    {
        $suspended = $user->disabled_at !== null
            || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user));

        abort_if($suspended, 403, __('auth.unavailable'));
    }
}
