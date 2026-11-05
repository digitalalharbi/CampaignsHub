<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Accounts\Services\RegistrationVerificationSettings;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Http\Controllers\PhoneSignInController;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Platform\Services\OperationalReadiness;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Whether a one-time code may be issued at sign-in or recovery (AUTH-CODE-SWITCHES-001, Owner §14).
 *
 * Signup has its own switches in {@see RegistrationVerificationSettings}. These cover what is left, on the same singleton and with
 * the same contract: absent means ON, a change takes a reason and the version the caller was looking at, and it is audited.
 *
 * ## Why the check lives on the code, not on the page
 *
 * {@see assertOpen()} is called from `ContactVerificationService`, the one place a code is minted or checked. Gating the controllers
 * would have missed `/requests/verify/start`, which also accepts `purpose: portal_login`. Off means no row, no message and no provider
 * call whichever door was knocked on, and a code already held stops working.
 *
 * ## What is deliberately not a switch
 *
 * Password, Google and the emailed reset link. The link is a transactional message and the way back for most accounts, and whether
 * recovery on /login should be a link or a code is an open Owner question (LOGIN-CLIENT-CODE-UNIFIED-001). No code exists on that path
 * today, so there is no recovery email switch: a switch that controlled nothing would only look like a decision.
 *
 * A switch only ever narrows. It cannot open a door some other gate holds shut, and it never records anything as proved.
 *
 * ## What each switch governs
 *
 * - `sign_in_sms_code`: `/auth/phone/start` and `/verify`. The public endpoints are live; production `/login` draws no SMS control.
 * - `portal_email_code`, `portal_sms_code`: purpose `portal_login`, so `/requests/verify/start` today and `/client/login/*` once
 *   `PORTAL_CODE_ON_LOGIN` opens that door. The flag is read here and never set.
 * - `recovery_sms_code`: proving a number from Account security.
 *
 * There is no switch for a platform account's email code. Its route left with LOGIN-SINGLE-DOOR-001 (#948) and nothing mints that
 * purpose any more, so such a switch would control nothing. The email code left on `/login` is the contact's, which `portal_email_code`
 * already governs.
 */
final class AuthCodeSwitches
{
    public const AUDIT_ACTION = 'auth.code_switches_changed';

    public const SWITCHES = ['sign_in_sms_code', 'portal_email_code', 'portal_sms_code', 'recovery_sms_code'];

    /** purpose → channel → switch. A purpose not named here is not a sign-in or recovery code, and no switch governs it. */
    private const GATES = [
        'phone_sign_in' => ['sms' => 'sign_in_sms_code', 'whatsapp' => 'sign_in_sms_code'],
        'portal_login' => ['email' => 'portal_email_code', 'sms' => 'portal_sms_code', 'whatsapp' => 'portal_sms_code'],
        // Proving a number from Account security is what makes it a way back. Numbers already proved stay proved.
        'phone_confirm' => ['sms' => 'recovery_sms_code', 'whatsapp' => 'recovery_sms_code'],
    ];

    /** @return array<string, mixed> every switch, plus the version */
    public function current(): array
    {
        $defaults = array_fill_keys(self::SWITCHES, true) + ['version' => 0];

        return array_merge($defaults, (array) (PlatformSetting::current()->auth_code_switches ?? []));
    }

    public function allows(string $purpose, string $channel): bool
    {
        $switch = self::GATES[$purpose][$channel] ?? null;

        return $switch === null || (bool) $this->current()[$switch];
    }

    /** What `/login` may offer: the door must be open on the server and the switch on. A switch never opens that door by itself. */
    public function emailCodeOffered(): bool
    {
        return $this->portalCodeIsOpen() && $this->allows('portal_login', 'email');
    }

    /** The same answer for every address, so a closed door is not an oracle for who holds an account. */
    public function assertOpen(string $purpose, string $channel): void
    {
        if (! $this->allows($purpose, $channel)) {
            throw new AccessDeniedHttpException((string) __($purpose === 'phone_confirm' ? 'auth.code_proof_off' : 'auth.code_channel_off'));
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the switches now in force
     */
    public function update(array $changes, int $expectedVersion, string $reason, User $actor, AuditLogger $audit): array
    {
        return DB::transaction(function () use ($changes, $expectedVersion, $reason, $actor, $audit): array {
            PlatformSetting::current();
            /** @var PlatformSetting $row */
            $row = PlatformSetting::query()->where('is_singleton', true)->lockForUpdate()->firstOrFail();

            $before = $this->current();

            if ((int) $before['version'] !== $expectedVersion) {
                throw new ConflictHttpException('The switches were changed by someone else. Reload them and decide again.');
            }

            $after = $before;
            foreach (self::SWITCHES as $switch) {
                if (array_key_exists($switch, $changes)) {
                    $after[$switch] = (bool) $changes[$switch];
                }
            }

            // Saving what is already there is not a decision, so it leaves no record and no new version.
            if ($after === $before) {
                return $before;
            }

            $this->assertNobodyStranded($before, $after);

            $after['version'] = (int) $before['version'] + 1;
            $row->forceFill(['auth_code_switches' => $after, 'updated_by' => $actor->getKey()])->save();

            $audit->log(
                action: self::AUDIT_ACTION,
                entityType: 'platform_settings',
                entityId: (string) $row->getKey(),
                before: $before,
                after: $after,
                reason: $reason,
                userId: (int) $actor->getKey(),
            );

            return $after;
        });
    }

    /**
     * Refuse a change that takes away somebody's last way in or way back (§14.4).
     *
     * Only a switch going OFF is judged, so turning one back on is never refused even if somebody is stranded for another reason.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function assertNobodyStranded(array $before, array $after): void
    {
        $errors = [];
        $turnedOff = fn (string $switch): bool => $before[$switch] && ! $after[$switch];

        if ($turnedOff('sign_in_sms_code')) {
            $accounts = $this->accountsWhoseOnlyWayBackIsSms();
            if ($accounts > 0) {
                $errors['sign_in_sms_code'][] = __('auth.code_switch_sms_only', ['count' => $accounts]);
            }
            if (! app(RegistrationVerificationSettings::class)->emailEnabled()) {
                $errors['sign_in_sms_code'][] = __('auth.code_switch_signup_unproven');
            }
        }

        if ($this->portalCodeIsOpen()) {
            /*
             * Judged on the channel going off, not on whether today's count gets worse. A phone-only contact with no SMS provider
             * configured yet is out of reach today, and closing the channel would keep them out once the provider arrives.
             */
            if ($turnedOff('portal_email_code')) {
                $smsReaches = $after['portal_sms_code'] && app(TwilioVerifyService::class)->configured();
                $contacts = $this->contacts(hasPhone: false) + ($smsReaches ? 0 : $this->contacts(hasPhone: true));
                if ($contacts > 0) {
                    $errors['portal_email_code'][] = __('auth.code_switch_contacts', ['count' => $contacts]);
                }
            }

            if ($turnedOff('portal_sms_code')) {
                $contacts = $this->phoneOnlyContacts() + ($after['portal_email_code'] ? 0 : $this->contacts(hasPhone: true));
                if ($contacts > 0) {
                    $errors['portal_sms_code'][] = __('auth.code_switch_contacts', ['count' => $contacts]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether a contact can sign in by code at all.
     *
     * `PORTAL_CODE_ON_LOGIN` shuts `/client/login/*` (LOGIN-SINGLE-DOOR-001) and those contacts recover by link, so with it off these
     * switches strand nobody. Read here, never set, and off when absent, as in `config/requests.php`.
     */
    private function portalCodeIsOpen(): bool
    {
        return (bool) config('requests.verification.portal_code_on_login', false);
    }

    /**
     * An address nobody proved opens no reset link and no email code, so a proved number is the only self-service way back it has.
     *
     * An account with neither has no self-service way back whatever these switches say, and a Super Admin's set-password link is its way.
     */
    private function accountsWhoseOnlyWayBackIsSms(): int
    {
        return User::query()
            ->whereNull('disabled_at')
            ->whereNull('email_verified_at')
            ->whereNotNull('email_unproven_by_policy_at')
            ->whereNotNull('phone')
            ->whereNotNull('phone_verified_at')
            ->count();
    }

    /** @param array<string, mixed> $switches */
    private function contactsWithNoWayIn(array $switches): int
    {
        if (! $this->portalCodeIsOpen()) {
            return 0;
        }

        $email = (bool) $switches['portal_email_code'];
        $sms = (bool) $switches['portal_sms_code'] && app(TwilioVerifyService::class)->configured();

        return ($email ? 0 : $this->contacts(hasPhone: false))
            + ($email || $sms ? 0 : $this->contacts(hasPhone: true))
            + ($sms ? 0 : $this->phoneOnlyContacts());
    }

    /** Contacts named by address, holding no account that could recover by link. Cross-tenant by design: the policy is the platform's. */
    private function contacts(bool $hasPhone): int
    {
        return (int) ExternalRequest::query()->withoutGlobalScopes()
            ->whereRaw("nullif(trim(contact_email), '') is not null")
            ->whereRaw($hasPhone ? "nullif(trim(contact_phone), '') is not null" : "nullif(trim(contact_phone), '') is null")
            ->whereNotExists(function (QueryBuilder $users): void {
                $users->selectRaw('1')->from('users')
                    ->whereRaw('lower(users.email) = lower(external_requests.contact_email)')
                    ->whereRaw('(users.email_verified_at is not null or users.email_unproven_by_policy_at is null)');
            })
            ->selectRaw('count(distinct lower(trim(contact_email))) as n')
            ->value('n');
    }

    private function phoneOnlyContacts(): int
    {
        return (int) ExternalRequest::query()->withoutGlobalScopes()
            ->whereRaw("nullif(trim(contact_email), '') is null")
            ->whereRaw("nullif(trim(contact_phone), '') is not null")
            ->selectRaw('count(distinct contact_phone) as n')
            ->value('n');
    }

    /**
     * The console's picture. The switch, the provider and the money are three separate questions (§14.6).
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $current = $this->current();
        $policy = app(RegistrationVerificationSettings::class)->current();

        $mail = app(OperationalReadiness::class)->mailState() === 'failing' ? 'failing'
            : (app(ProviderRegistry::class)->isConfigured('email') ? 'configured' : 'not_configured');
        $sms = app(TwilioVerifyService::class)->configured() ? 'configured' : 'not_configured';

        $channels = [];
        foreach (self::SWITCHES as $switch) {
            $byEmail = str_contains($switch, 'email');
            $channels[$switch] = $this->channel($switch, (bool) $current[$switch], $byEmail ? $mail : $sms, $policy[$byEmail ? 'email_unit_cost' : 'sms_unit_cost'], $policy['cost_currency']);
        }

        return [
            'switches' => $current,
            'channels' => $channels,
            // The panel states this beside the contact switches: with it off, no contact signs in by code whatever they say.
            'portal_code_on_login' => $this->portalCodeIsOpen(),
            'recovery_link' => ['available' => true, 'switchable' => false, 'open_question' => 'LOGIN-CLIENT-CODE-UNIFIED-001'],
            'limits' => [
                'code_ttl_minutes' => (int) config('requests.verification.code_ttl_minutes', 10),
                'max_attempts' => (int) config('requests.verification.max_attempts', 5),
                'resend_cooldown_seconds' => PhoneSignInController::RESEND_COOLDOWN,
            ],
            // Live, because a contact can arrive after a switch went off and no later save would notice.
            'lockout' => [
                'stranded_contacts' => $this->contactsWithNoWayIn($current),
                'accounts_only_sms_back' => $this->accountsWhoseOnlyWayBackIsSms(),
            ],
            'counted_since' => ContactVerification::query()->min('created_at'),
            'changes' => AuditLog::query()
                ->where('action', self::AUDIT_ACTION)
                ->latest('created_at')->limit(10)->get()
                ->map(fn (AuditLog $log) => [
                    'at' => $log->created_at?->toIso8601String(),
                    'user_id' => $log->user_id,
                    'reason' => $log->reason,
                    'before' => $log->before,
                    'after' => $log->after,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function channel(string $switch, bool $enabled, string $provider, mixed $unitCost, mixed $currency): array
    {
        $sent30 = $this->ledger($switch, Carbon::now()->subDays(30))->where('delivery_status', 'sent')->count();

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'last_24h' => [
                'issued' => $this->ledger($switch, Carbon::now()->subDay())->count(),
                'sent' => $this->ledger($switch, Carbon::now()->subDay())->where('delivery_status', 'sent')->count(),
            ],
            'sent_30d' => $sent30,
            'unit_cost' => $unitCost,
            'currency' => $currency,
            // Sent × a rate somebody entered, so it stays an estimate; with no rate entered it is unknown.
            'estimated_cost_30d' => $unitCost === null ? null : number_format($sent30 * (float) $unitCost, 4, '.', ''),
            'cost_known' => $unitCost !== null,
        ];
    }

    /** @return Builder<ContactVerification> what the ledger holds for one switch */
    private function ledger(string $switch, Carbon $since): Builder
    {
        return ContactVerification::query()
            ->where('created_at', '>=', $since)
            ->where(function (Builder $query) use ($switch): void {
                foreach (self::GATES as $purpose => $channels) {
                    foreach ($channels as $channel => $governedBy) {
                        if ($governedBy === $switch) {
                            $query->orWhere(fn (Builder $pair) => $pair->where('purpose', $purpose)->where('channel', $channel));
                        }
                    }
                }
            });
    }
}
