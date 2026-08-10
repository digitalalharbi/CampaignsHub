<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LOGIN-OTP-001 — signing in with an email address and a one-time code.
 *
 * ## What this is
 *
 * The production door. `/login` shows one field and one button, and this is what is behind them for
 * a platform user. It is the email twin of `PhoneSignInController`, and it exists for the same
 * reason that one does: the client portal's `/client/login/verify` opens a PORTAL session for a
 * contact, so pointing a platform user's address at it would sign them into `/portal` — a space
 * they hold nothing in — and the page would look like it had worked.
 *
 * The password endpoint is untouched and still live. It is no longer offered by the production UI
 * (the owner's decision, 2026-08-10) and remains the DEV/E2E path and the way an existing
 * integration keeps working. Deleting it would have broken the demo accounts and the whole suite to
 * gain nothing this endpoint does not already give.
 *
 * ## The OTP contract, item by item
 *
 * - **single-use** — the challenge is marked `consumed_at` here, before it can buy a second session.
 * - **hashed at rest** — only `sha256(code)` is ever stored; the plaintext exists for one request.
 * - **expiring** — `REQUESTS_OTP_TTL_MINUTES`, ten minutes by default.
 * - **resend cooldown** — enforced in the service, per destination, not in the browser.
 * - **rate limited** — `throttle:otp-request` / `throttle:otp-check` at the route, per IP.
 * - **maximum attempts** — five, counted on the row; the sixth is refused even if correct.
 * - **previous codes invalidated** — asking for a new code retires every live one for that address.
 * - **session rotation** — `session()->regenerate()` on success, so the pre-auth id cannot be fixed.
 * - **audited** — issue, success and failure are all recorded, with no code and no hash.
 * - **fail-closed** — with no mail provider the code is NOT sent and nothing pretends it was; in
 *   production, where `dev_code` is hard-gated off, that means sign-in cannot complete. That is the
 *   honest outcome of an unconfigured provider, and it is `READY_FOR_CREDENTIALS`, not «Live».
 *
 * ## The message is real, and so is the state that reports it
 *
 * `TransactionalMailer` composes `CredentialMail::SIGN_IN_CODE` and records a `NotificationDelivery`
 * row before attempting anything. `delivery_status` on the response is that ledger's own answer —
 * `sent` only after a transport accepted it, `sandbox` when the driver reaches nobody,
 * `awaiting_credentials` when the channel reports no provider, `failed` with the transport's message
 * when it threw. The moment real credentials are entered, the same code path starts delivering; no
 * second implementation has to be written and nothing has to be switched on.
 *
 * ## No enumeration
 *
 * `start` answers identically for an address nobody holds: a verification id and a delivery status.
 * A code is issued either way. `verify` then fails for a code that was never tied to a real account
 * with the SAME message a wrong code gets — «بيانات الدخول غير صحيحة» — so nothing in the pair of
 * responses says whether the address is registered here.
 */
final class EmailSignInController extends Controller
{
    /** Kept distinct so a portal or registration code can never be replayed as a platform credential. */
    private const PURPOSE = 'email_sign_in';

    /** The resend window, in seconds. The browser counts the same number down; this one is binding. */
    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private readonly ContactVerificationService $verification,
        private readonly TransactionalMailer $mailer,
    ) {}

    /** POST /auth/email/start — send a one-time code to an email address. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:160'],
        ]);

        $email = Str::lower(trim((string) $data['email']));

        /*
         * The code is issued whether or not the address belongs to anybody — the same trade this
         * platform already makes on the phone path. Issuing only for known addresses turns the
         * endpoint into a directory of who has an account here, answerable by anybody with a mailing
         * list. The cost is one message to a stranger, which the throttle and the cooldown bound.
         */
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $ttl = (int) config('requests.verification.code_ttl_minutes', 10);

        $result = $this->verification->start(
            'email',
            $email,
            self::PURPOSE,
            null,
            cooldownSeconds: self::RESEND_COOLDOWN,
            invalidatePrevious: true,
            /*
             * The message itself, composed where the plaintext lives and nowhere else.
             *
             * `TransactionalMailer` is the product's one way to send an account message: it asks
             * `ProviderRegistry` — not `mail.default` — whether email is configured, writes a
             * `NotificationDelivery` row before attempting anything, and returns the OUTCOME rather
             * than an intention. `sent` is written only after the transport accepted it.
             *
             * The verification id is the dedup key, so a retried request cannot mail the same code
             * twice. There is no automatic retry by design: re-sending is «إعادة إرسال الرمز», a
             * decision somebody makes, not a background job that mails a stranger four times.
             */
            deliver: fn (string $code, string $id): string => $this->mailer->send(
                recipient: $email,
                mail: new CredentialMail(
                    purpose: CredentialMail::SIGN_IN_CODE,
                    lang: $locale,
                    code: $code,
                    expiresInMinutes: $ttl,
                ),
                kind: CredentialMail::SIGN_IN_CODE,
                template: 'credential.sign_in_code',
                locale: $locale,
                dedupKey: $id,
            ),
        );

        $this->audit($request, 'auth.email_code.requested', [
            'email' => $email,
            'delivery_status' => $result['delivery_status'],
        ]);

        return ApiResponse::success([
            'verification_id' => $result['id'],
            /*
             * Honest, and load-bearing. `awaiting_provider_credentials` means nothing was sent —
             * the page must not say «check your inbox» as though something were on its way.
             */
            'delivery_status' => $result['delivery_status'],
            'resend_after' => self::RESEND_COOLDOWN,
            // Non-production only; hard-gated inside the verification service.
            'dev_code' => $result['dev_code'],
        ], __('auth.code_sent'));
    }

    /**
     * POST /auth/email/verify — check the code and open a session.
     *
     * Three things must hold, checked in this order: the code is the one we sent, it was minted for
     * this purpose and has not been spent, and the address belongs to an account that may sign in.
     * Every failure answers the same way, because «the code was right but you have no account» is a
     * sentence that tells a stranger the address is not registered.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $verification = $this->verification->verify($data['verification_id'], (string) $data['code']);

        // A code minted for registration or for the client portal is not a platform credential.
        abort_unless($verification->purpose === self::PURPOSE, 422);

        /*
         * Spent here, before it can buy a second session.
         *
         * `verify()` marks a challenge VERIFIED and leaves it usable, which is right for the flows
         * that verify once and consume the proof later and wrong for a credential. Without this the
         * same six digits opened a session every time they were posted.
         */
        if ($verification->consumed_at !== null) {
            $this->audit($request, 'auth.email_code.replayed', ['email' => (string) $verification->destination]);

            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $verification->forceFill(['consumed_at' => now()])->save();

        $email = Str::lower((string) $verification->destination);

        /** @var User|null $user */
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->audit($request, 'auth.email_code.no_account', ['email' => $email]);

            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $this->assertMaySignIn($user);

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));

        // Session rotation: the id that existed before authentication must not survive it.
        $request->session()->regenerate();

        $this->audit($request, 'auth.email_code.signed_in', ['email' => $email], $user);

        /*
         * No portal is claimed here, exactly as on the password and phone paths. The destination
         * comes from real memberships once the session exists, so a code cannot ask for access the
         * account does not hold.
         */
        return ApiResponse::success(['user' => new UserResource($user)], __('auth.signed_in'));
    }

    /**
     * A disabled account, or one whose every workspace is suspended, may not sign in by code either.
     *
     * The SAME rule the password path applies, through the same helper — a second credential with a
     * laxer opinion about suspension would be a way around it rather than a second way in.
     */
    private function assertMaySignIn(User $user): void
    {
        $suspended = $user->disabled_at !== null
            || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user));

        abort_if($suspended, 403, __('auth.unavailable'));
    }

    /**
     * The security trail.
     *
     * The address and the outcome, never the code and never its hash: a log that carries the secret
     * is a second copy of the credential, sitting somewhere with a longer retention than the ten
     * minutes the code itself is worth anything for.
     *
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, string $action, array $after, ?User $user = null): void
    {
        AuditLog::create([
            'tenant_id' => null,
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $user?->id === null ? null : (string) $user->id,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
