<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Notifications\Support\MailShell;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Something changed on this account — MAIL-009.
 *
 * A sign-in from a device we have not seen, a changed password, a new sign-in method. The message is
 * a claim plus the facts behind it, because a reader who cannot check the claim can only ignore it or
 * panic, and both are useless.
 *
 * ## Facts that are unknown are omitted, never filled in
 *
 * `null` is not «غير معروف». A table with three rows of «unknown» reads as a broken feature and
 * teaches the reader to skip the table, which is the one part of the message that does any work. The
 * caller passes what it actually knows; `facts()` drops the rest.
 *
 * ## The action is «this was not me»
 *
 * Never «open your account». A person who recognises the sign-in has nothing to do, and a button
 * inviting them to log in from an email is training for exactly the habit phishing relies on.
 */
final class SecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public const NEW_SIGN_IN = 'new_sign_in';

    public const PASSWORD_CHANGED = 'password_changed';

    public function __construct(
        public readonly string $event,
        public readonly string $lang = 'ar',
        public readonly string $recipientName = '',
        /** When it happened, already formatted in the reader's own timezone by the caller. */
        public readonly ?string $at = null,
        /** «Chrome على macOS» — a device description, never a fingerprint. */
        public readonly ?string $device = null,
        /** A city or a country. Nothing finer: an approximate place is enough to recognise yourself. */
        public readonly ?string $location = null,
        public readonly ?string $ip = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CampaignsHub — '.$this->words()['subject']);
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $words = $this->words();

        /*
         * A security notice is `warning`, not `critical`.
         *
         * Most of these describe the reader signing in themselves. Painting every one of them in the
         * colour reserved for «your budget is gone» is how a person learns that red means nothing —
         * and the one that genuinely matters arrives looking like the four that did not.
         */
        $tone = MailDesign::tone('warning');

        $shell = MailShell::build(
            lang: $this->lang,
            subject: $words['subject'],
            preheader: $words['detail'],
            preferences: false,
            footerNote: MailShell::accountFootnote($ar),
        );

        $body = $shell + [
            'title' => $words['title'],
            'detail' => $words['detail'],
            'toneInk' => $tone['ink'],
            'toneBg' => $tone['bg'],
            'facts' => $this->facts($ar),
            'greeting' => $this->recipientName !== ''
                ? ($ar ? "مرحبًا، {$this->recipientName}" : "Hello, {$this->recipientName}")
                : ($ar ? 'مرحبًا' : 'Hello'),
            'actionUrl' => MailLinks::to('/app/account/security'),
            'actionLabel' => $ar ? 'مراجعة أمان الحساب' : 'Review account security',
            'reassurance' => $words['reassurance'],
        ];

        return new Content(view: 'mail.layout', with: $body + [
            'slot' => view('mail.security', $body)->render(),
        ]);
    }

    /**
     * The rows the reader checks themselves against.
     *
     * ## `numeric` is not decoration, and getting it wrong is unreadable
     *
     * The tabular face — `SF Mono`, `Menlo`, `Consolas` — carries no Arabic. A location like «الرياض،
     * السعودية» set in it falls back per-glyph and loses its joining: the reader is shown
     * «ا ل ر ي ا ض», which is not a spacing problem but a word that no longer reads as a word. Found
     * by rendering this template and looking at it, exactly as the digest's duplicated path label was.
     *
     * So the face follows the CONTENT. A timestamp and an IP address are figures, always Latin, and
     * belong in the tabular face where the digits line up. A device string and a place name are text,
     * may be in either script, and belong in the body face — with `dir="auto"`, so the browser reads
     * the direction off the first strong character rather than being told a wrong one.
     *
     * @return list<array{label:string, value:string, numeric:bool}>
     */
    private function facts(bool $ar): array
    {
        $labels = $ar
            ? ['at' => 'الوقت', 'device' => 'الجهاز', 'location' => 'الموقع التقريبي', 'ip' => 'عنوان IP']
            : ['at' => 'Time', 'device' => 'Device', 'location' => 'Approximate location', 'ip' => 'IP address'];

        $numeric = ['at' => true, 'ip' => true, 'device' => false, 'location' => false];

        $out = [];
        foreach (['at', 'device', 'location', 'ip'] as $key) {
            $value = $this->{$key};
            if (is_string($value) && trim($value) !== '') {
                $out[] = ['label' => $labels[$key], 'value' => $value, 'numeric' => $numeric[$key]];
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function words(): array
    {
        $ar = $this->lang === 'ar';

        return match ($this->event) {
            self::PASSWORD_CHANGED => $ar ? [
                'subject' => 'تغيّرت كلمة المرور',
                'title' => 'تغيّرت كلمة المرور لحسابك',
                'detail' => 'أصبحت كلمة المرور الجديدة سارية، والجلسات المفتوحة على الأجهزة الأخرى انتهت.',
                'reassurance' => 'إذا كنت أنت من غيّرها، فلا حاجة لأي إجراء. وإذا لم تكن أنت، أعد تعيين كلمة المرور فورًا وراجع الأجهزة المرتبطة بحسابك.',
            ] : [
                'subject' => 'Your password was changed',
                'title' => 'Your account password was changed',
                'detail' => 'The new password is now in use, and sessions on other devices have been ended.',
                'reassurance' => 'If this was you, there is nothing to do. If it was not, reset your password immediately and review the devices connected to your account.',
            ],

            default => $ar ? [
                'subject' => 'تسجيل دخول جديد إلى حسابك',
                'title' => 'تسجيل دخول جديد إلى حسابك',
                'detail' => 'سُجّل دخول إلى حسابك من جهاز لم نره من قبل.',
                'reassurance' => 'إذا كنت أنت، فلا حاجة لأي إجراء. وإذا لم تكن أنت، غيّر كلمة المرور الآن وأنهِ الجلسات المفتوحة من صفحة الأمان.',
            ] : [
                'subject' => 'A new sign-in to your account',
                'title' => 'A new sign-in to your account',
                'detail' => 'Somebody signed in to your account from a device we have not seen before.',
                'reassurance' => 'If this was you, there is nothing to do. If it was not, change your password now and end the open sessions from your security page.',
            ],
        };
    }
}
