<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Platform\Models\PlatformAlert;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — files what the detector found into the platform inbox, and tells the owner once.
 *
 * Idempotent by construction: `platform_alerts.dedup_key` is unique, so a second sweep over the same attempt finds the
 * row and changes nothing; a grouped technical error only grows by customers it has not reported before, in any
 * day's group. The email for an alert is keyed by the alert and the administrator in the mail ledger, so a retry or
 * an overlapping run cannot send it twice.
 *
 * Recipients are `users.is_platform_admin` and nobody else — a subscriber's team is never addressed, and no row here
 * has an owner other than the platform.
 */
final class PlatformAlertInbox
{
    public const MAIL_KIND = 'platform_payment_alert';

    public function __construct(
        private readonly PaymentAlertDetector $detector,
        private readonly PlatformAlertPresenter $presenter,
        private readonly TransactionalMailer $mailer,
    ) {}

    /**
     * One sweep: detect, file, clear what has cleared, tell the owner about what is new.
     *
     * @return array{raised: int, grown: int, cleared: int, mailed: int, settlement_check: string}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $raised = $grown = $mailed = 0;
        $fresh = [];

        foreach ($this->detector->detect($now) as $candidate) {
            if ($candidate['kind'] === PlatformAlert::BLOCKING) {
                [$alert, $isNew, $added] = $this->fileGroup($candidate, $now);
                if ($alert === null) {
                    continue;
                }
                if ($isNew) {
                    $raised++;
                    $fresh[] = $alert;
                } elseif ($added > 0) {
                    $grown++;
                    // More customers stuck on the same error: told again, but not more often than the group window.
                    $every = max(1, (int) config('platform_alerts.group_reannounce_hours', 3));
                    if ($alert->emailed_at === null || $alert->emailed_at->lessThanOrEqualTo($now->copy()->subHours($every))) {
                        $fresh[] = $alert;
                    }
                }

                continue;
            }

            $alert = $this->file($candidate, $now);
            if ($alert !== null) {
                $raised++;
                $fresh[] = $alert;
            }
        }

        $cleared = $this->clear($now);

        foreach ($fresh as $alert) {
            $mailed += $this->announce($alert);
        }

        return [
            'raised' => $raised,
            'grown' => $grown,
            'cleared' => $cleared,
            'mailed' => $mailed,
            'settlement_check' => $this->detector->settlementCheckAvailable() ? 'available' : UnavailableSettlementSource::STATE,
        ];
    }

    /** @param  array<string,mixed>  $candidate  the new alert, or null when this attempt was already filed */
    private function file(array $candidate, Carbon $now): ?PlatformAlert
    {
        if (PlatformAlert::query()->where('dedup_key', $candidate['dedup_key'])->exists()) {
            return null;
        }

        try {
            return PlatformAlert::query()->create($this->row($candidate, $now));
        } catch (UniqueConstraintViolationException) {
            // An overlapping sweep filed it a moment ago: still one alert.
            return null;
        }
    }

    /**
     * A day's group of one technical error, grown by customers not reported before in ANY group of that type.
     *
     * @param  array<string,mixed>  $candidate
     * @return array{0: ?PlatformAlert, 1: bool, 2: int}
     */
    private function fileGroup(array $candidate, Carbon $now): array
    {
        $type = (string) $candidate['facts']['type'];
        $known = [];

        PlatformAlert::query()->where('kind', PlatformAlert::BLOCKING)
            ->where('dedup_key', 'like', 'blocking:'.$type.':%')
            ->where('first_seen_at', '>=', $now->copy()->subHours(max(1, (int) config('platform_alerts.lookback_hours', 72)) + 48))
            ->get(['facts'])
            ->each(function (PlatformAlert $a) use (&$known): void {
                foreach ((array) ($a->facts['items'] ?? []) as $item) {
                    $known[(string) ($item['key'] ?? '')] = true;
                }
            });

        $new = array_values(array_filter((array) $candidate['facts']['items'], fn (array $i): bool => ! isset($known[(string) $i['key']])));
        if ($new === []) {
            return [null, false, 0];
        }

        $existing = PlatformAlert::query()->where('dedup_key', $candidate['dedup_key'])->first();
        if ($existing === null) {
            $candidate['facts']['items'] = $new;
            try {
                $alert = PlatformAlert::query()->create(['occurrences' => count($new)] + $this->row($candidate, $now));

                return [$alert, true, count($new)];
            } catch (UniqueConstraintViolationException) {
                $existing = PlatformAlert::query()->where('dedup_key', $candidate['dedup_key'])->first();
                if ($existing === null) {
                    return [null, false, 0];
                }
            }
        }

        $facts = $existing->facts;
        $facts['items'] = [...array_values((array) ($facts['items'] ?? [])), ...$new];
        $existing->forceFill([
            'facts' => $facts,
            'occurrences' => count($facts['items']),
            'last_seen_at' => $now,
            // New customers stuck: the group is open again even if the owner had marked the earlier ones handled.
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ])->save();

        return [$existing, false, count($new)];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function row(array $candidate, Carbon $now): array
    {
        return [
            'kind' => $candidate['kind'],
            'severity' => $candidate['severity'],
            'dedup_key' => $candidate['dedup_key'],
            'subject_type' => $candidate['subject_type'],
            'subject_id' => $candidate['subject_id'],
            'subject_tenant_id' => $candidate['subject_tenant_id'],
            'facts' => $candidate['facts'],
            'occurrences' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ];
    }

    /** Payment alerts whose facts have changed — the customer paid, the activation landed — close themselves. */
    private function clear(Carbon $now): int
    {
        $cleared = 0;

        PlatformAlert::query()->open()
            ->whereIn('kind', [PlatformAlert::ABANDONED, PlatformAlert::UNVERIFIED, PlatformAlert::MISMATCH])
            ->orderBy('first_seen_at')
            ->each(function (PlatformAlert $alert) use (&$cleared, $now): void {
                if ($this->detector->hasCleared($alert)) {
                    $alert->forceFill(['resolved_at' => $now])->save();
                    $cleared++;
                }
            });

        return $cleared;
    }

    /** One message per administrator, once. Returns how many were handed to the mail ledger. */
    public function announce(PlatformAlert $alert): int
    {
        $told = 0;

        foreach ($this->administrators() as $admin) {
            $ar = $admin->locale !== 'en';
            $card = $this->presenter->present($alert, $ar);

            try {
                $this->mailer->queue(
                    recipient: (string) $admin->email,
                    mail: $this->mail($alert, $card, $ar, (string) $admin->name),
                    kind: self::MAIL_KIND,
                    template: 'mail.operational',
                    locale: $ar ? 'ar' : 'en',
                    tenantId: null,
                    userId: (int) $admin->getKey(),
                    // The group's size is part of the key: a grown group is a new message, the same group never twice.
                    dedupKey: 'platform-alert:'.$alert->getKey().':'.$admin->getKey().($alert->kind === PlatformAlert::BLOCKING ? ':'.$alert->occurrences : ''),
                );
                $told++;
            } catch (Throwable $e) {
                // The alert is filed and listed whatever happens to the message about it.
                report($e);
            }
        }

        if ($told > 0) {
            $alert->forceFill(['emailed_at' => Carbon::now()])->save();
        }

        return $told;
    }

    /** @param  array<string,mixed>  $card */
    public function mail(PlatformAlert $alert, array $card, bool $ar, string $recipientName = ''): OperationalMail
    {
        return new OperationalMail(
            kind: self::MAIL_KIND,
            severity: (string) $card['severity'],
            title: (string) $card['title'],
            detail: implode("\n", [(string) $card['line'], ...$card['more']]),
            context: (string) $card['context'],
            lang: $ar ? 'ar' : 'en',
            recipientName: $recipientName,
            path: '/admin/requests?alert='.$alert->getKey(),
            action: $ar ? 'افتح التنبيه' : 'Open the alert',
            why: $ar
                ? 'وصلتك لأنك من إدارة المنصة. الأوقات بتوقيت الرياض، والأرقام من سجلات الدفع والتسجيل كما هي.'
                : 'Sent because you administer the platform. Times are Riyadh time; figures are from the payment and sign-up records as they stand.',
            steps: (string) $card['steps'],
            viz: (array) $card['viz'],
        );
    }

    /** @return list<User> */
    private function administrators(): array
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->whereNotNull('email')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u): bool => (string) $u->email !== '')
            ->values()->all();
    }
}
