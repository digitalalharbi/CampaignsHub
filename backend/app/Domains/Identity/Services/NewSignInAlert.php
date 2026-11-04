<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «A new sign-in to your account» — EMAIL-GAPS (§17.3.2).
 *
 * ## `Login` also fires on a session refresh
 *
 * Laravel raises `Login` when a remember-me cookie silently reopens an expired session, as well as for a
 * real sign-in. So this listener never decides from the event: it asks whether this device is one the
 * person has used before, which is the same answer on both paths.
 *
 * ## A person's first device is not news
 *
 * The first device ever recorded for somebody is written down in silence. Otherwise the day this is
 * switched on, every existing user would be told that the browser they have used for a year is new.
 * Devices are recorded while the email is off for the same reason.
 *
 * ## What «device» means, and what it cannot catch
 *
 * The browser and the operating system, not the IP, because a phone changes address all day. So somebody
 * signing in with stolen credentials from the same browser on the same operating system is not reported.
 * A signed device cookie would close that; it is a separate decision.
 *
 * ## It can never cost a sign-in
 *
 * Nothing here may throw into the login, and nothing here talks to a mail server on the request: the
 * message is queued, and any failure is reported and swallowed. It runs after commit, because the caller
 * may be inside a transaction that a failed statement here would poison.
 */
final class NewSignInAlert
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function handle(Login $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                $request = request();

                if ($event->user instanceof User && $request instanceof Request) {
                    $this->observe($event->user, $request);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function observe(User $user, Request $request): void
    {
        $agent = (string) $request->userAgent();
        $device = self::device($agent);
        $fingerprint = hash('sha256', $user->getKey().'|'.$device);
        $now = Carbon::now();

        $hadOne = DB::table('known_sign_in_devices')->where('user_id', $user->getKey())->exists();

        $isNew = DB::table('known_sign_in_devices')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'fingerprint' => $fingerprint,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]) > 0;

        if (! $isNew) {
            DB::table('known_sign_in_devices')->where('user_id', $user->getKey())->where('fingerprint', $fingerprint)->update(['last_seen_at' => $now]);

            return;
        }

        if (! $hadOne || config('security.new_sign_in_alert') !== true || (string) $user->email === '') {
            return;
        }

        $locale = in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
        $tenantId = $user->tenant_id === null ? null : (string) $user->tenant_id;

        $this->mailer->queue(
            recipient: (string) $user->email,
            mail: new SecurityAlertMail(
                event: SecurityAlertMail::NEW_SIGN_IN,
                lang: $locale,
                recipientName: (string) $user->name,
                at: $now->copy()->setTimezone($this->timezoneOf($user))->format('Y-m-d H:i'),
                device: $device,
                ip: $request->ip(),
            ),
            kind: 'security_alert',
            template: 'mail.security',
            locale: $locale,
            tenantId: $tenantId,
            userId: (int) $user->getKey(),
            dedupKey: 'new-sign-in:'.$user->getKey().':'.substr($fingerprint, 0, 32),
        );
    }

    private function timezoneOf(User $user): string
    {
        $chosen = DB::table('notification_preferences')->where('user_id', $user->getKey())->whereNull('client_workspace_id')->value('timezone');

        return is_string($chosen) && in_array($chosen, timezone_identifiers_list(), true) ? $chosen : 'Asia/Riyadh';
    }

    /** «Chrome · macOS». A version is left out on purpose: an update is not a new device. */
    public static function device(string $agent): string
    {
        $browser = match (true) {
            $agent === '' => 'Unknown browser',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Other browser',
        };

        $system = match (true) {
            $agent === '' => 'Unknown system',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X'), str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Other system',
        };

        return $browser.' · '.$system;
    }
}
