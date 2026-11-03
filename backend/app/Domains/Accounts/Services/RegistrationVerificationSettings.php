<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationSendLog;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Services\TwilioVerifyService;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The platform's answer to «does signup verify email, and does it verify the phone?» (SIGNUP-VERIFY-POLICY-001).
 *
 * Kept on the `platform_settings` singleton beside the other platform switches. Absent means both ON: the secure answer
 * is what an install has until somebody with the authority changes it, so nothing is ever disabled by a deploy.
 *
 * These switches decide only whether an application is asked to prove something. They say nothing about approval or
 * payment, and turning one off never records anything as proved.
 */
final class RegistrationVerificationSettings
{
    public const AUDIT_ACTION = 'registration.verification_policy_changed';

    private const DEFAULTS = [
        'email_enabled' => true,
        'mobile_enabled' => true,
        // No cap until somebody sets one: a limit invented here would refuse messages production sends today.
        'email_daily_cap' => null,
        'sms_daily_cap' => null,
        'per_application_daily_cap' => null,
        // Unknown until entered. Null is reported as unknown, never as zero and never as a guess.
        'email_unit_cost' => null,
        'sms_unit_cost' => null,
        'cost_currency' => null,
        'budget_alert_percent' => 80,
        // Bites only while the email gate is off, because that is when an application costs nothing to open.
        'unverified_signups_daily_cap' => 200,
        'version' => 0,
    ];

    /** @return array<string, mixed> */
    public function current(): array
    {
        return array_merge(self::DEFAULTS, (array) (PlatformSetting::current()->registration_verification_policy ?? []));
    }

    public function emailEnabled(): bool
    {
        return (bool) $this->current()['email_enabled'];
    }

    /**
     * What an application opened now is fixed to.
     *
     * Copied onto the row so turning a switch later cannot activate, or strand, somebody already in the queue.
     *
     * @return array{switches: array{email: bool, mobile: bool}, settings_version: int, taken_at: string}
     */
    public function snapshot(): array
    {
        $current = $this->current();

        return [
            'switches' => ['email' => (bool) $current['email_enabled'], 'mobile' => (bool) $current['mobile_enabled']],
            'settings_version' => (int) $current['version'],
            'taken_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Change the policy: one writer at a time, and only from the version the caller was looking at.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the policy now in force
     */
    public function update(array $changes, int $expectedVersion, string $reason, User $actor, AuditLogger $audit): array
    {
        return DB::transaction(function () use ($changes, $expectedVersion, $reason, $actor, $audit): array {
            PlatformSetting::current();
            /** @var PlatformSetting $row */
            $row = PlatformSetting::query()->where('is_singleton', true)->lockForUpdate()->firstOrFail();

            $before = array_merge(self::DEFAULTS, (array) ($row->registration_verification_policy ?? []));

            if ((int) $before['version'] !== $expectedVersion) {
                throw new ConflictHttpException('The policy was changed by someone else. Reload it and decide again.');
            }

            $after = array_merge($before, array_intersect_key($changes, self::DEFAULTS));
            $after['version'] = $before['version'];

            // Saving what is already there is not a decision, so it leaves no record and no new version.
            if ($after === $before) {
                return $before;
            }

            $after['version'] = (int) $before['version'] + 1;
            $row->forceFill(['registration_verification_policy' => $after, 'updated_by' => $actor->getKey()])->save();

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
     * The console's picture: each channel's switch, provider, volume and cost, and how many applications are held.
     *
     * The gate and the provider are different questions on purpose: a disabled gate sends nothing by decision, an
     * unconfigured provider sends nothing by accident, and only the second one is a fault.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $current = $this->current();

        return [
            'policy' => $current,
            'channels' => [
                'email' => $this->channel('email', $current, 'email_enabled', 'email_daily_cap', 'email_unit_cost',
                    app(ProviderRegistry::class)->isConfigured('email')),
                'mobile' => $this->channel('mobile', $current, 'mobile_enabled', 'sms_daily_cap', 'sms_unit_cost',
                    app(TwilioVerifyService::class)->configured()),
            ],
            'open_applications' => [
                'at_email_gate' => RegistrationRequest::query()->whereNull('tenant_id')
                    ->where('state', AccountState::EmailVerificationRequired->value)->count(),
                'at_mobile_gate' => RegistrationRequest::query()->whereNull('tenant_id')
                    ->where('state', AccountState::MobileVerificationRequired->value)->count(),
            ],
            'unverified_signups_last_24h' => $this->unverifiedSignupsToday(),
            'counted_since' => RegistrationSendLog::query()->min('created_at'),
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

    /** Applications opened in the last day with no address proved, which is what the abuse ceiling counts. */
    public function unverifiedSignupsToday(): int
    {
        return RegistrationRequest::query()
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->whereNull('email_verified_at')
            ->whereRaw("(effective_policy->'switches'->>'email') = 'false'")
            ->count();
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function channel(string $channel, array $current, string $switch, string $capKey, string $costKey, bool $providerReady): array
    {
        $delivered30 = $this->count($channel, Carbon::now()->subDays(30), RegistrationSendLog::DELIVERED);
        $delivered24 = $this->count($channel, Carbon::now()->subDay(), RegistrationSendLog::DELIVERED);
        $counted24 = RegistrationSendLog::query()
            ->where('channel', $channel)->where('created_at', '>=', Carbon::now()->subDay())
            ->whereNotIn('outcome', [RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::BLOCKED_APPLICATION])
            ->count();
        $blocked24 = $this->count($channel, Carbon::now()->subDay(), [RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::BLOCKED_APPLICATION]);

        $cap = $current[$capKey] === null ? null : (int) $current[$capKey];
        $unit = $current[$costKey];
        $alertAt = (int) $current['budget_alert_percent'];

        return [
            'gate' => $current[$switch] ? 'enabled' : 'disabled',
            'provider' => $providerReady ? 'configured' : 'not_configured',
            'last_24h' => ['attempted' => $counted24, 'delivered' => $delivered24, 'blocked' => $blocked24],
            'delivered_30d' => $delivered30,
            'cap' => $cap,
            'alert' => match (true) {
                $cap === null => 'no_cap',
                $counted24 >= $cap => 'at_cap',
                ($counted24 * 100 / $cap) >= $alertAt => 'near_cap',
                default => 'ok',
            },
            'unit_cost' => $unit,
            'currency' => $current['cost_currency'],
            // Delivered × a rate somebody entered, so it stays an estimate; with no rate entered it is unknown.
            'estimated_cost_30d' => $unit === null ? null : number_format($delivered30 * (float) $unit, 4, '.', ''),
            'cost_known' => $unit !== null,
        ];
    }

    /** @param list<string> $outcomes */
    private function count(string $channel, Carbon $since, array $outcomes): int
    {
        return RegistrationSendLog::query()
            ->where('channel', $channel)
            ->where('created_at', '>=', $since)
            ->whereIn('outcome', $outcomes)
            ->count();
    }
}
