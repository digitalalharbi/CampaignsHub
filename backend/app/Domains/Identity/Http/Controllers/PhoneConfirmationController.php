<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PhoneNumberRule;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-PHONE-001 — proving a mobile number from Account security.
 *
 * ## Why this endpoint exists
 *
 * A number reaches a user in two ways. Through registration, where the mobile gate proves it, and
 * through a profile edit, where nothing does. Since a proved number is a sign-in credential, the
 * second route cannot be allowed to mint one — so `MeController` clears the proof whenever the number
 * changes, and this is how somebody puts it back.
 *
 * It is also the door to everything the owner asked WhatsApp to be: a recovery channel, a place to
 * send security alerts, and — once a provider is configured — a way to sign in.
 *
 * ## Fail-closed on the provider
 *
 * With no SMS or WhatsApp provider wired, `ContactVerificationService` mints a code and sends
 * NOTHING. Outside production the code comes back in the response so the flow is walkable; in
 * production it does not, so the confirmation simply cannot complete. That is the honest outcome of
 * an unconfigured channel — `READY_FOR_CREDENTIALS`, not a number quietly marked proved.
 *
 * ## Not a way to take somebody else's number
 *
 * Confirming binds the number to the SIGNED-IN user and to nobody else. The code goes to the number
 * being claimed, so completing the flow means holding that phone at that moment, which is the whole
 * proof. A number already proved by an older account stays with that account for sign-in purposes —
 * see the `oldest()` in `PhoneSignInController`.
 */
final class PhoneConfirmationController extends Controller
{
    /** Kept distinct so a sign-in or portal code can never be replayed to prove a number. */
    private const PURPOSE = 'phone_confirm';

    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private readonly ContactVerificationService $verification,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /me/phone — what this account's number is, and whether it can do anything yet.
     *
     * The two channel states are reported separately and honestly, because they are what decides
     * whether the product may OFFER a WhatsApp sign-in at all. A page that showed the option while
     * the channel was unconfigured would be a button that cannot work.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'phone' => $user?->phone,
            'confirmed' => $user?->phone_verified_at !== null,
            'confirmed_at' => $user?->phone_verified_at,
            'channels' => [
                'sms' => $this->providers->isConfigured('sms'),
                'whatsapp' => $this->providers->isConfigured('whatsapp'),
            ],
        ], 'The number on this account.');
    }

    /**
     * POST /me/phone/start — send a code to the number being claimed.
     *
     * The number is normalised before anything is sent, so `05…`, `9665…` and `+9665…` are one
     * destination rather than three — and so the code reaches the number the customer will recognise.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40', new PhoneNumberRule],
            'channel' => ['sometimes', 'in:sms,whatsapp'],
        ]);

        $e164 = PhoneNumber::normalise((string) $data['phone']);

        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => [__('validation.phone_number')]]);
        }

        $channel = (string) ($data['channel'] ?? 'sms');

        $result = $this->verification->start(
            $channel,
            $e164,
            self::PURPOSE,
            null,
            cooldownSeconds: self::RESEND_COOLDOWN,
            invalidatePrevious: true,
        );

        $this->audit->log('user.phone_confirmation_requested', 'user', (string) $request->user()?->uuid, null, [
            'channel' => $channel,
            'delivery_status' => $result['delivery_status'],
        ]);

        return ApiResponse::success([
            'verification_id' => $result['id'],
            // Honest: `awaiting_provider_credentials` means nothing was sent to anybody.
            'delivery_status' => $result['delivery_status'],
            'resend_after' => self::RESEND_COOLDOWN,
            // Non-production only; hard-gated inside the verification service.
            'dev_code' => $result['dev_code'],
        ], __('auth.code_sent'));
    }

    /**
     * POST /me/phone/confirm — check the code and bind the number to this account.
     *
     * The challenge is consumed here, before it can prove a second number: `verify()` marks a row
     * verified and leaves it usable, which is right for flows that verify once and consume the proof
     * later, and wrong for anything that grants a credential.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $verification = $this->verification->verify($data['verification_id'], (string) $data['code']);

        // A code minted to sign in, or for the client portal, is not proof of a number.
        abort_unless($verification->purpose === self::PURPOSE, 422);

        if ($verification->consumed_at !== null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $verification->forceFill(['consumed_at' => now()])->save();

        $e164 = PhoneNumber::normalise((string) $verification->destination);

        if ($e164 === null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $user->forceFill(['phone' => $e164, 'phone_verified_at' => now()])->save();

        $this->audit->log('user.phone_confirmed', 'user', (string) $user->uuid, null, [
            'channel' => $verification->channel,
        ]);

        return ApiResponse::success([
            'phone' => $user->phone,
            'confirmed' => true,
        ], 'The number is confirmed.');
    }

    /**
     * DELETE /me/phone — stop using this number as a credential.
     *
     * The number itself is kept: it is a contact detail as well as a credential, and somebody
     * removing it as a sign-in method rarely means «forget how to reach me». Only the proof goes.
     */
    public function revoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['phone_verified_at' => null])->save();

        $this->audit->log('user.phone_unverified', 'user', (string) $user->uuid, null, [
            'reason' => 'The account holder withdrew the number as a sign-in method.',
        ]);

        return ApiResponse::success(['confirmed' => false], 'The number is no longer a sign-in method.');
    }
}
