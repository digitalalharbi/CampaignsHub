<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\WhatsAppTemplates;
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
 * A person's WhatsApp number and consent — NOTIFICATION-CHANNELS-001. The number is proved with a code
 * (`ContactVerificationService`, the same flow that proves a sign-in number) and consent is a checkbox
 * the person ticks AT the moment of proof; the opt-in is stored only when both happened.
 *
 * ## Fail-closed on the provider
 *
 * With no WhatsApp provider the code is minted and sent to nobody, and in production it is not returned,
 * so the opt-in cannot complete. That is the honest state of an unconfigured channel — `start` answers
 * `awaiting_provider_credentials` — rather than a number marked proved by a code nobody received.
 *
 * ## Not the sign-in number
 *
 * Proving a WhatsApp number does not touch `users.phone` or `phone_verified_at`, and withdrawing it does
 * not un-verify a login. See the migration for why they are separate.
 */
final class WhatsAppOptInController extends Controller
{
    private const PURPOSE = 'whatsapp_opt_in';

    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private readonly ContactVerificationService $verification,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /settings/notifications/whatsapp/start — send a code to the number being claimed. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40', new PhoneNumberRule],
            'consent' => ['accepted'],
        ]);

        $e164 = PhoneNumber::normalise((string) $data['phone']);

        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => [__('validation.phone_number')]]);
        }

        /** @var User $user */
        $user = $request->user();
        $locale = in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';

        $result = $this->verification->start(
            'whatsapp', $e164, self::PURPOSE, null,
            cooldownSeconds: self::RESEND_COOLDOWN,
            invalidatePrevious: true,
            deliver: function (string $code) use ($e164, $locale): string {
                $provider = $this->providers->for('whatsapp');

                if (! $provider->isConfigured()) {
                    return 'awaiting_provider_credentials';
                }

                $sid = WhatsAppTemplates::contentSid('ch_whatsapp_otp', $locale);

                return $sid === null ? 'template_missing' : (string) $provider->send($e164, ['content_sid' => $sid, 'variables' => ['1' => $code]])['status'];
            },
            ownerKey: 'user:'.(string) $user->getAuthIdentifier(),
        );

        $this->audit->log('user.whatsapp_opt_in_requested', 'user', (string) $user->uuid, null, ['delivery_status' => $result['delivery_status']]);

        return ApiResponse::success([
            'verification_id' => $result['id'],
            'delivery_status' => $result['delivery_status'],
            'resend_after' => self::RESEND_COOLDOWN,
            'dev_code' => $result['dev_code'],
        ], __('auth.code_sent'));
    }

    /** POST /settings/notifications/whatsapp/confirm — check the code and record the opt-in. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'consent' => ['accepted'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $verification = $this->verification->verify($data['verification_id'], (string) $data['code'], 'user:'.(string) $user->getAuthIdentifier());

        abort_unless($verification->purpose === self::PURPOSE, 422);

        if ($verification->consumed_at !== null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $verification->forceFill(['consumed_at' => now()])->save();

        $e164 = PhoneNumber::normalise((string) $verification->destination);

        if ($e164 === null) {
            throw ValidationException::withMessages(['code' => [__('auth.failed')]]);
        }

        $user->forceFill([
            'whatsapp_number' => $e164,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_in_source' => 'settings_checkbox',
            'whatsapp_opted_out_at' => null,
        ])->save();

        $this->audit->log('user.whatsapp_opted_in', 'user', (string) $user->uuid, null, ['source' => 'settings_checkbox']);

        return ApiResponse::success(['opted_in' => true], 'WhatsApp is on for this number.');
    }

    /** DELETE /settings/notifications/whatsapp — withdraw consent. The number is kept, the opt-out is dated. */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->whatsapp_opt_in_at !== null) {
            $user->forceFill(['whatsapp_opted_out_at' => now()])->save();
            $this->audit->log('user.whatsapp_opted_out', 'user', (string) $user->uuid, null, ['via' => 'settings']);
        }

        return ApiResponse::success(['opted_in' => false], 'WhatsApp is off.');
    }

    /** What the settings screen needs to know, without ever returning the whole number. */
    public static function state(User $user, ProviderRegistry $providers): array
    {
        $optedIn = $user->whatsapp_opt_in_at !== null
            && ($user->whatsapp_opted_out_at === null || $user->whatsapp_opted_out_at < $user->whatsapp_opt_in_at);
        $number = trim((string) $user->whatsapp_number);

        return [
            'provider_configured' => $providers->isConfigured('whatsapp'),
            'opted_in' => $optedIn,
            'opted_out' => $user->whatsapp_opted_out_at !== null && ! $optedIn,
            'number_hint' => $number === '' ? null : str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4),
        ];
    }
}
