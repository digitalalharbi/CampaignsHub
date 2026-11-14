<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Settings\Services\Totp;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TWO-STEP-001 — the methods a person holds for the second step, and the one way each is checked.
 *
 * Three methods: an authenticator app (TOTP, RFC 6238), a six-digit code to the account's verified address, and a code by SMS to
 * a number the person already proved (AUTH-PHONE-001). Recovery codes are a way back, not a method: they answer the challenge
 * when the phone is lost, each exactly once.
 *
 * ## Secrets
 *
 * The authenticator secret is stored with the model's `encrypted` cast and only once it has been confirmed with a first code; until
 * then it waits in the cache, encrypted, for fifteen minutes. Email and SMS codes go through {@see ContactVerificationService}, which
 * stores only a hash, expires them in ten minutes, counts attempts and enforces the resend window. Recovery codes are stored as
 * sha256 hashes. Nothing here is logged, and no secret is returned after the screen that shows it once.
 */
final class TwoFactorMethods
{
    public const METHODS = ['totp', 'email', 'sms'];

    public const SIGN_IN_PURPOSE = 'two_factor_sign_in';

    public const ENROL_PURPOSE = 'two_factor_enrol';

    public const RESEND_COOLDOWN = 60;

    public const RECOVERY_CODE_COUNT = 10;

    private const SETUP_TTL_MINUTES = 15;

    public function __construct(
        private readonly Totp $totp,
        private readonly ContactVerificationService $codes,
        private readonly TwilioVerifyService $twilio,
        private readonly TransactionalMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether signing in as this person needs a second step.
     *
     * Judged on what the person turned ON, not on what can be answered today: a method whose provider went away (SMS with no
     * Twilio credentials, say) leaves the gate shut and the challenge offering the recovery codes, never quietly open.
     */
    public function required(User $user): bool
    {
        return (bool) $user->two_factor_enabled && (
            $this->totpActive($user)
            || $user->two_factor_email_enabled_at !== null
            || $user->two_factor_sms_enabled_at !== null
        );
    }

    /** @return list<string> the methods this person can answer the challenge with right now */
    public function active(User $user): array
    {
        $active = [];

        if ($this->totpActive($user)) {
            $active[] = 'totp';
        }
        if ($user->two_factor_email_enabled_at !== null && $this->emailReachable($user)) {
            $active[] = 'email';
        }
        if ($user->two_factor_sms_enabled_at !== null && $this->smsReachable($user)) {
            $active[] = 'sms';
        }

        return $active;
    }

    public function defaultMethod(User $user): ?string
    {
        $active = $this->active($user);
        $chosen = (string) $user->two_factor_default_method;

        return in_array($chosen, $active, true) ? $chosen : ($active[0] ?? null);
    }

    /** SMS can only be offered when a provider can actually send it. */
    public function smsProviderReady(): bool
    {
        return $this->twilio->configured();
    }

    /**
     * What the sign-in challenge shows. Masked contact details only — enough to recognise your own.
     *
     * @return array<string, mixed>
     */
    public function challengeFor(User $user): array
    {
        return [
            'required' => true,
            'methods' => $this->active($user),
            'default' => $this->defaultMethod($user),
            'email_hint' => in_array('email', $this->active($user), true) ? PendingOAuthLink::mask((string) $user->email) : null,
            'phone_hint' => in_array('sms', $this->active($user), true) ? self::maskPhone((string) $user->phone) : null,
            'recovery_available' => $this->recoveryCodesLeft($user) > 0,
            'resend_after' => self::RESEND_COOLDOWN,
        ];
    }

    /**
     * The settings card's picture.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        return [
            'enabled' => $this->required($user),
            'default' => $this->defaultMethod($user),
            'recovery_codes_left' => $this->recoveryCodesLeft($user),
            'methods' => [
                'totp' => ['active' => $this->totpActive($user), 'available' => true],
                'email' => [
                    'active' => $user->two_factor_email_enabled_at !== null,
                    'available' => $this->emailReachable($user),
                    'hint' => PendingOAuthLink::mask((string) $user->email),
                ],
                'sms' => [
                    'active' => $user->two_factor_sms_enabled_at !== null,
                    // Two separate reasons it may not be offered; the card states which one.
                    'provider_ready' => $this->smsProviderReady(),
                    'phone_confirmed' => $user->phone !== null && $user->phone_verified_at !== null,
                    'available' => $this->smsReachable($user),
                    'hint' => $user->phone !== null ? self::maskPhone((string) $user->phone) : null,
                ],
            ],
        ];
    }

    // ── Authenticator app ──────────────────────────────────────────────────────────────────────

    /** @return array{secret: string, otpauth_uri: string} */
    public function beginTotp(User $user): array
    {
        if ($this->totpActive($user)) {
            throw ValidationException::withMessages(['method' => [__('auth.two_factor_already_active')]]);
        }

        $secret = $this->totp->generateSecret();
        Cache::put($this->setupKey($user), Crypt::encryptString($secret), now()->addMinutes(self::SETUP_TTL_MINUTES));

        return ['secret' => $secret, 'otpauth_uri' => $this->totp->uri($secret, (string) $user->email, 'CampaignsHub')];
    }

    /** @return list<string>|null the recovery codes, only when this activation is the person's first method */
    public function confirmTotp(User $user, string $code): ?array
    {
        $sealed = Cache::get($this->setupKey($user));
        $secret = is_string($sealed) ? rescue(fn () => Crypt::decryptString($sealed), null, false) : null;
        $step = is_string($secret) ? $this->totp->matchingStep($secret, $code) : null;

        if ($step === null) {
            throw ValidationException::withMessages(['code' => [__('auth.code_incorrect')]]);
        }

        Cache::forget($this->setupKey($user));
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_totp_confirmed_at' => now(),
            'two_factor_totp_last_step' => $step,
        ])->save();

        return $this->activated($user, 'totp');
    }

    // ── Email and SMS ──────────────────────────────────────────────────────────────────────────

    /** @return array{verification_id: string, delivery_status: string, resend_after: int, dev_code: ?string} */
    public function sendCode(User $user, string $method, string $purpose): array
    {
        $destination = $this->destination($user, $method);

        $result = $this->codes->start(
            $method === 'email' ? 'email' : 'sms',
            $destination,
            $purpose,
            cooldownSeconds: self::RESEND_COOLDOWN,
            invalidatePrevious: true,
            deliver: $method === 'email' ? $this->mail($user, $destination) : null,
            ownerKey: $this->ownerKey($user),
        );

        return [
            'verification_id' => $result['id'],
            'delivery_status' => $result['delivery_status'],
            'resend_after' => self::RESEND_COOLDOWN,
            // Non-production only; hard-gated inside the verification service.
            'dev_code' => $result['dev_code'],
        ];
    }

    /** @return list<string>|null */
    public function confirmChannel(User $user, string $method, string $verificationId, string $code): ?array
    {
        $this->spendCode($user, $method, $verificationId, $code, self::ENROL_PURPOSE);

        $user->forceFill([$method === 'email' ? 'two_factor_email_enabled_at' : 'two_factor_sms_enabled_at' => now()])->save();

        return $this->activated($user, $method);
    }

    // ── The challenge ──────────────────────────────────────────────────────────────────────────

    /**
     * Check an answer to the challenge. True when it is right; false for every way of being wrong.
     *
     * One answer for a wrong code, an expired one, a used one and a method the person does not hold, so a caller learns only
     * that it did not work.
     */
    public function verify(User $user, string $method, string $code, ?string $verificationId = null): bool
    {
        $code = trim($code);

        if ($method === 'recovery') {
            return $this->spendRecoveryCode($user, $code);
        }

        if (! in_array($method, $this->active($user), true)) {
            return false;
        }

        if ($method === 'totp') {
            return $this->spendTotp($user, $code);
        }

        if ($verificationId === null || $verificationId === '') {
            return false;
        }

        try {
            $this->spendCode($user, $method, $verificationId, $code, self::SIGN_IN_PURPOSE);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** Re-confirmation before a sensitive change: the current password, or a valid second-step answer. */
    public function reconfirm(User $user, ?string $password, ?string $code): bool
    {
        if (is_string($password) && $password !== '' && Hash::check($password, (string) $user->password)) {
            return true;
        }

        if (is_string($code) && $code !== '') {
            return ($this->totpActive($user) && $this->spendTotp($user, $code)) || $this->spendRecoveryCode($user, $code);
        }

        return false;
    }

    // ── Changing what is held ──────────────────────────────────────────────────────────────────

    public function setDefault(User $user, string $method): void
    {
        if (! in_array($method, $this->active($user), true)) {
            throw ValidationException::withMessages(['method' => [__('auth.two_factor_method_inactive')]]);
        }

        $before = $user->two_factor_default_method;
        $user->forceFill(['two_factor_default_method' => $method])->save();
        $this->audit->log(action: 'security.two_factor.default_changed', entityType: 'user', entityId: (string) $user->uuid,
            before: ['method' => $before], after: ['method' => $method], userId: (int) $user->getKey());
    }

    public function remove(User $user, string $method): void
    {
        $user->forceFill(match ($method) {
            'totp' => ['two_factor_secret' => null, 'two_factor_totp_confirmed_at' => null, 'two_factor_totp_last_step' => null],
            'email' => ['two_factor_email_enabled_at' => null],
            default => ['two_factor_sms_enabled_at' => null],
        })->save();

        $this->audit->log(action: 'security.two_factor.method_removed', entityType: 'user', entityId: (string) $user->uuid,
            after: ['method' => $method], userId: (int) $user->getKey());

        if (! $this->required($user)) {
            $this->disable($user);

            return;
        }

        if (! in_array((string) $user->two_factor_default_method, $this->active($user), true) && $this->active($user) !== []) {
            $user->forceFill(['two_factor_default_method' => $this->active($user)[0]])->save();
        }
    }

    /** Every method off and every recovery code gone. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_totp_confirmed_at' => null,
            'two_factor_totp_last_step' => null,
            'two_factor_email_enabled_at' => null,
            'two_factor_sms_enabled_at' => null,
            'two_factor_default_method' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
        Cache::forget($this->setupKey($user));

        $this->audit->log(action: 'security.two_factor.disabled', entityType: 'user', entityId: (string) $user->uuid, userId: (int) $user->getKey());
    }

    /** @return list<string> ten fresh codes; every earlier code stops working */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->issueRecoveryCodes($user);
        $this->audit->log(action: 'security.two_factor.recovery_regenerated', entityType: 'user', entityId: (string) $user->uuid, userId: (int) $user->getKey());

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count((array) ($user->two_factor_recovery_codes ?? []));
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4 ? '••••' : '•••• '.substr($digits, -4);
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────

    /** @return list<string>|null */
    private function activated(User $user, string $method): ?array
    {
        $first = ! (bool) $user->two_factor_enabled || $this->recoveryCodesLeft($user) === 0;

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_default_method' => in_array((string) $user->two_factor_default_method, $this->active($user), true)
                ? $user->two_factor_default_method : $method,
        ])->save();

        $this->audit->log(
            action: $first ? 'security.two_factor.enabled' : 'security.two_factor.method_added',
            entityType: 'user',
            entityId: (string) $user->uuid,
            after: ['method' => $method],
            userId: (int) $user->getKey(),
        );

        return $first ? $this->issueRecoveryCodes($user) : null;
    }

    /** @return list<string> */
    private function issueRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = Str::lower(Str::random(10));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $c): string => self::hashRecovery($c), $codes)])->save();

        return $codes;
    }

    private function spendRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hashRecovery($code);

        $spent = DB::transaction(function () use ($user, $hash): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $left = array_values((array) ($locked->two_factor_recovery_codes ?? []));

            $index = null;
            foreach ($left as $i => $candidate) {
                if (is_string($candidate) && hash_equals($candidate, $hash) && $index === null) {
                    $index = $i;
                }
            }

            if ($index === null) {
                return false;
            }

            array_splice($left, $index, 1);
            $locked->forceFill(['two_factor_recovery_codes' => $left])->save();

            return true;
        });

        if ($spent) {
            $user->refresh();
            $this->audit->log(action: 'security.two_factor.recovery_used', entityType: 'user', entityId: (string) $user->uuid,
                after: ['left' => $this->recoveryCodesLeft($user)], userId: (int) $user->getKey());
        }

        return $spent;
    }

    private function spendTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        $step = is_string($secret) && $secret !== '' ? $this->totp->matchingStep($secret, $code) : null;

        if ($step === null) {
            return false;
        }

        // Replay protection, atomically: only a step later than the last accepted one is accepted.
        $updated = User::query()->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_totp_last_step')->orWhere('two_factor_totp_last_step', '<', $step))
            ->update(['two_factor_totp_last_step' => $step]);

        if ($updated === 1) {
            $user->setAttribute('two_factor_totp_last_step', $step);
            $user->syncOriginalAttribute('two_factor_totp_last_step');

            return true;
        }

        return false;
    }

    private function spendCode(User $user, string $method, string $verificationId, string $code, string $purpose): void
    {
        $fail = fn () => ValidationException::withMessages(['code' => [__('auth.code_incorrect')]]);

        /** @var ContactVerification|null $row */
        $row = ContactVerification::query()->find($verificationId);
        $channel = $method === 'email' ? 'email' : 'sms';

        // A code minted for another purpose, channel or person is not this one, whatever it verifies.
        if ($row === null || $row->purpose !== $purpose || $row->channel !== $channel || $row->consumed_at !== null
            || $row->owner_key_hash === null || ! hash_equals((string) $row->owner_key_hash, hash('sha256', $this->ownerKey($user)))) {
            throw $fail();
        }

        $this->codes->verify($verificationId, $code, $this->ownerKey($user));

        // Single use: spent now, before it can buy anything a second time.
        $row->refresh();
        if ($row->consumed_at !== null) {
            throw $fail();
        }
        $row->forceFill(['consumed_at' => Carbon::now()])->save();
    }

    private function destination(User $user, string $method): string
    {
        if ($method === 'email') {
            abort_unless($this->emailReachable($user), 422, (string) __('auth.two_factor_email_unverified'));

            return Str::lower(trim((string) $user->email));
        }

        abort_unless($method === 'sms', 422);
        abort_unless($this->smsProviderReady(), 422, (string) __('auth.two_factor_sms_awaiting'));
        $e164 = $user->phone_verified_at !== null ? PhoneNumber::normalise((string) $user->phone) : null;
        abort_if($e164 === null, 422, (string) __('auth.two_factor_phone_unconfirmed'));

        return $e164;
    }

    private function mail(User $user, string $destination): \Closure
    {
        return function (string $code, string $id) use ($user, $destination): string {
            $locale = ($user->locale ?? 'ar') === 'en' ? 'en' : 'ar';

            return $this->mailer->send(
                recipient: $destination,
                mail: new CredentialMail(purpose: CredentialMail::SIGN_IN_CODE, lang: $locale, code: $code,
                    expiresInMinutes: (int) config('requests.verification.code_ttl_minutes', 10)),
                kind: CredentialMail::SIGN_IN_CODE,
                template: 'credential.sign_in_code',
                locale: $locale,
                userId: (int) $user->getKey(),
                dedupKey: $id,
            );
        };
    }

    private function totpActive(User $user): bool
    {
        // A legacy row (enabled with a secret, from before methods existed) is treated as active: deny by default.
        return is_string($user->two_factor_secret) && $user->two_factor_secret !== ''
            && ($user->two_factor_totp_confirmed_at !== null || (bool) $user->two_factor_enabled);
    }

    private function emailReachable(User $user): bool
    {
        return $user->email_verified_at !== null && trim((string) $user->email) !== '';
    }

    private function smsReachable(User $user): bool
    {
        return $this->smsProviderReady() && $user->phone !== null && $user->phone_verified_at !== null;
    }

    private function ownerKey(User $user): string
    {
        return 'two_factor:'.$user->getKey();
    }

    private function setupKey(User $user): string
    {
        return 'two_factor:totp_setup:'.$user->getKey();
    }

    private static function hashRecovery(string $code): string
    {
        return hash('sha256', Str::lower(str_replace([' ', '-'], '', trim($code))));
    }
}
