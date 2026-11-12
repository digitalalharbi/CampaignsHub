<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Services\MailUnsubscribes;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Notifications\Support\MailShell;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One click out of a kind of message, with no sign-in — EMAIL-GAPS (§17.4.5).
 *
 * ## GET does not unsubscribe
 *
 * Mail security gateways fetch every link in a message before anybody reads it, so a link that acted on
 * GET would quietly unsubscribe real readers, which is why RFC 8058 forbids it. The page GET returns
 * submits its own POST as it loads: the reader clicks once and is out, and a fetcher that runs no script
 * changes nothing. With scripts off the same form is one button.
 *
 * A mail client's own «Unsubscribe» button posts directly through the `List-Unsubscribe-Post` header.
 *
 * An invalid link gets the same page shape with a 404, naming nothing about whose it might have been.
 */
final class MailUnsubscribeController extends Controller
{
    public function __construct(private readonly MailUnsubscribes $unsubscribes) {}

    public function show(Request $request, string $token): Response
    {
        $read = $this->unsubscribes->read($token);

        return $this->page($request, $read === null ? 'invalid' : 'confirm', $token, $read);
    }

    public function leave(Request $request, string $token): Response
    {
        $read = $this->unsubscribes->read($token);

        if ($read === null) {
            return $this->page($request, 'invalid', $token);
        }

        $this->unsubscribes->leave($read);

        return $this->page($request, 'done', $token, $read);
    }

    /** @param  array{t: string, h: string, s: string, u: int|null}|null  $read */
    private function page(Request $request, string $state, string $token, ?array $read = null): Response
    {
        $ar = $request->query('lang') !== 'en' && ! str_starts_with((string) $request->header('Accept-Language'), 'en');
        // SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — a recipient OUTSIDE the platform has no account: no link into it, no account wording.
        $external = ($read['s'] ?? null) === MailUnsubscribes::EXTERNAL;

        return response()->view('mail.pages.unsubscribe', [
            'state' => $state,
            'action' => route('mail.unsubscribe.confirm', ['token' => $token]),
            'preferences' => $external ? null : MailLinks::to('/app/account/notifications'),
            'dir' => $ar ? 'rtl' : 'ltr',
            'locale' => $ar ? 'ar' : 'en',
            'wordmark' => MailShell::wordmarkPlain($ar),
            't' => $ar ? [
                'title' => 'إلغاء الاشتراك',
                'confirm' => 'لإيقاف هذا النوع من الرسائل، اضغط الزر إن لم يكتمل ذلك تلقائيًا.',
                'button' => 'إلغاء الاشتراك',
                'done' => 'تم إلغاء اشتراكك في هذا النوع من الرسائل.',
                'kept' => $external ? 'لن تصلك رسائل متابعة الميزانية بعد الآن.' : 'ستبقى رسائل أمان حسابك تصلك عند الحاجة.',
                'change' => 'تغيير إعدادات الإشعارات',
                'invalid' => 'هذا الرابط غير صالح. يمكنك إدارة إشعاراتك من إعدادات حسابك.',
            ] : [
                'title' => 'Unsubscribe',
                'confirm' => 'To stop this type of message, press the button if this does not finish by itself.',
                'button' => 'Unsubscribe',
                'done' => 'You are unsubscribed from this type of message.',
                'kept' => $external ? 'Budget follow-up messages will no longer reach you.' : 'Messages about the security of your account will still reach you when needed.',
                'change' => 'Change your notification settings',
                'invalid' => 'This link is not valid. You can manage your notifications from your account settings.',
            ],
        ], $state === 'invalid' ? 404 : 200)->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
