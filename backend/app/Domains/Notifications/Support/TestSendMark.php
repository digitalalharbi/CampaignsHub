<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * What turns a template into a test message — EMAIL-TEST-SEND-001 (§17.4.5).
 *
 * The same template and the same fixture, with two differences a reader cannot miss: the subject carries
 * the mark after the product's name, and a strip above the body says it is a test (`mail.layout`). The
 * text part is built from the HTML, so a client that shows only text shows the strip too.
 *
 * The subject is set on the outgoing message rather than on the mailable, because `envelope()` is read
 * again at send time and would overwrite anything set earlier. `Mail::fake()` therefore never sees the
 * mark; `MailTestSendTest` reads what the transport received for that reason.
 */
final class TestSendMark
{
    public static function subject(Mailable $mail, string $lang): string
    {
        $line = method_exists($mail, 'envelope') ? (string) $mail->envelope()->subject : '';
        $rest = str_starts_with($line, MailSubject::PREFIX) ? substr($line, strlen(MailSubject::PREFIX)) : $line;

        return MailSubject::of(($lang === 'ar' ? '[اختبار] ' : '[TEST] ').$rest);
    }

    public static function apply(Mailable $mail, string $lang): Mailable
    {
        $subject = self::subject($mail, $lang);

        // A preview carries a sample link so a reviewer sees the footer. A test must offer no way out at all.
        if (method_exists($mail, 'offerUnsubscribe')) {
            $mail->offerUnsubscribe(null);
        }

        return $mail
            ->with('testSendNotice', $lang === 'ar'
                ? ['title' => 'رسالة اختبار، وليست إشعارًا حقيقيًا.', 'detail' => 'أرقامها وأسماؤها وروابطها تجريبية. طلبها مشرف المنصة لمراجعة القالب، ولم تصل إلى أي عميل.']
                : ['title' => 'Test message, not a real notice.', 'detail' => 'Its figures, names and links are fixtures. A platform admin asked for it to review this template, and no customer received it.'])
            ->withSymfonyMessage(static function (Email $message) use ($subject): void {
                $message->subject($subject);
            });
    }
}
