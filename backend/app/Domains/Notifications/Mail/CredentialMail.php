<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A code to type or a link to open — MAIL-009.
 *
 * Password reset, email verification, and a one-time sign-in code. One class, because the three are
 * the same message with a different reason: here is the thing that proves it is you, here is how long
 * it lasts, here is what to do if you did not ask for it.
 *
 * ## `$purpose` decides the words and nothing else
 *
 * Every sentence a reader sees comes from the map below, keyed by purpose, rather than from the
 * caller. That is deliberate: these are the messages most likely to be composed in a hurry by
 * whichever flow needs one, and a hand-written «your code is 482910» in the wrong place is how a
 * validity period goes unstated or a code ends up in a subject line. A caller supplies the SECRET and
 * the purpose; the wording is settled here.
 *
 * ## The secret never reaches the subject or the URL
 *
 * The subject names the purpose only. A code lives in the body as text; a link's token is a query
 * parameter on a URL the reader opens themselves, which is the only place it can be and still be
 * clickable. Neither is ever both.
 *
 * ## `$lang`, not `$locale`
 *
 * `Illuminate\Mail\Mailable` already declares a non-readonly `$locale`; promoting a readonly property
 * of that name is a fatal error at class-load time. The same trap as `DailyDigestMail`.
 */
final class CredentialMail extends Mailable
{
    use Queueable, SerializesModels;

    public const PASSWORD_RESET = 'password_reset';

    public const EMAIL_VERIFICATION = 'email_verification';

    public const SIGN_IN_CODE = 'sign_in_code';

    /** A member added to a workspace by somebody else, choosing their password for the first time. */
    public const MEMBER_SETUP = 'member_setup';

    public function __construct(
        /** One of the four constants above. */
        public readonly string $purpose,
        public readonly string $lang = 'ar',
        /** A code the reader types. Mutually exclusive with `$url`. */
        public readonly ?string $code = null,
        /** A link the reader opens. Mutually exclusive with `$code`. */
        public readonly ?string $url = null,
        /** How long the secret lasts, in minutes — stated to the reader, never assumed. */
        public readonly int $expiresInMinutes = 60,
        /** Which workspace they were added to. Only `MEMBER_SETUP` has one. */
        public readonly string $workspace = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: MailSubject::of($this->words()['subject']));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $words = $this->words();

        $shell = MailShell::build(
            lang: $this->lang,
            subject: $words['subject'],
            preheader: $words['intro'],
            // An account message carries no unsubscribe. See `MailShell`.
            preferences: false,
            footerNote: MailShell::accountFootnote($ar),
        );

        $body = $shell + [
            'title' => $words['title'],
            'intro' => $words['intro'],
            'code' => $this->code,
            'actionUrl' => $this->url ?? '',
            'actionLabel' => $words['action'],
            'validity' => $this->validity($ar),
            'notYou' => $words['not_you'],
        ];

        // `t` carries the footer's strings; these are the body's. Merged rather than replaced, so the
        // policy links keep their own words.
        $body['t'] = $shell['t'] + ($ar
            ? [
                'never_share' => 'لا تشارك هذا الرمز مع أي شخص. لن يطلبه منك فريقنا أبدًا.',
                'or_paste' => 'إذا لم يعمل الزر، افتح هذا الرابط:',
                'not_you_title' => 'إذا لم تطلب هذه الرسالة',
            ]
            : [
                'never_share' => 'Do not share this code with anyone. Our team will never ask for it.',
                'or_paste' => 'If the button fails, open this link:',
                'not_you_title' => 'If you did not ask for this',
            ]);

        return new Content(view: 'mail.layout', with: $body + [
            'slot' => view('mail.credential', $body)->render(),
        ]);
    }

    /**
     * How long the secret lasts, written the way a person says it.
     *
     * «60 دقيقة» is arithmetic; «ساعة واحدة» is the answer. The distinction matters here more than
     * anywhere else in the product, because this sentence is read by somebody deciding whether to
     * finish now or come back later.
     */
    private function validity(bool $ar): string
    {
        $minutes = $this->expiresInMinutes;

        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);

            return $ar
                ? ($days === 1 ? 'هذا الرابط صالح لمدة يوم واحد.' : "هذا الرابط صالح لمدة {$days} أيام.")
                : ($days === 1 ? 'This link is valid for one day.' : "This link is valid for {$days} days.");
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $thing = $this->code !== null ? ($ar ? 'الرمز' : 'code') : ($ar ? 'الرابط' : 'link');

            return $ar
                ? ($hours === 1 ? "هذا {$thing} صالح لمدة ساعة واحدة." : "هذا {$thing} صالح لمدة {$hours} ساعات.")
                : ($hours === 1 ? "This {$thing} is valid for one hour." : "This {$thing} is valid for {$hours} hours.");
        }

        return $ar
            ? ($this->code !== null ? "هذا الرمز صالح لمدة {$minutes} دقيقة." : "هذا الرابط صالح لمدة {$minutes} دقيقة.")
            : ($this->code !== null ? "This code is valid for {$minutes} minutes." : "This link is valid for {$minutes} minutes.");
    }

    /**
     * Every sentence, by purpose.
     *
     * The `not_you` line is the one that earns its place. For a password reset it is «somebody may
     * have your address» and the safe advice is to do nothing; for a sign-in code it is stronger,
     * because a code arriving unrequested means somebody already has the password.
     *
     * @return array<string,string>
     */
    private function words(): array
    {
        $ar = $this->lang === 'ar';

        return match ($this->purpose) {
            self::PASSWORD_RESET => $ar ? [
                'subject' => 'إعادة تعيين كلمة المرور',
                'title' => 'إعادة تعيين كلمة المرور',
                'intro' => 'وصلنا طلب لإعادة تعيين كلمة مرورك.',
                'action' => 'اختيار كلمة مرور جديدة',
                'not_you' => 'تجاهل الرسالة. كلمة مرورك الحالية ما زالت تعمل. إذا تكررت، غيّرها من إعدادات الأمان.',
            ] : [
                'subject' => 'Reset your password',
                'title' => 'Reset your password',
                'intro' => 'We received a request to reset your password.',
                'action' => 'Choose a new password',
                'not_you' => 'Ignore this message. Your current password still works. If this repeats, change it in your security settings.',
            ],

            self::MEMBER_SETUP => $ar ? [
                'subject' => 'حسابك جاهز — اختر كلمة المرور',
                'title' => 'حسابك جاهز',
                'intro' => $this->workspace !== ''
                    ? "أُضيف حسابك إلى مساحة عمل {$this->workspace}. اختر كلمة المرور للدخول."
                    : 'أُضيف حسابك إلى كامبينز هب. اختر كلمة المرور للدخول.',
                'action' => 'اختيار كلمة المرور',
                'not_you' => 'إذا لم تتوقعها، فتجاهلها. لن يعمل الحساب قبل اختيار كلمة المرور.',
            ] : [
                'subject' => 'Your account is ready — choose a password',
                'title' => 'Your account is ready',
                'intro' => $this->workspace !== ''
                    ? "Your account was added to the {$this->workspace} workspace. Choose a password to sign in."
                    : 'Your account was added to CampaignsHub. Choose a password to sign in.',
                'action' => 'Choose a password',
                'not_you' => 'Not expecting this? Ignore it. The account cannot be used until a password is chosen.',
            ],

            self::EMAIL_VERIFICATION => $ar ? [
                'subject' => 'تأكيد بريدك الإلكتروني',
                'title' => 'تأكيد بريدك الإلكتروني',
                'intro' => 'أكّد أن هذا البريد يخصك لإكمال حسابك.',
                'action' => 'تأكيد البريد الإلكتروني',
                'not_you' => 'تجاهل الرسالة. لن يُفتح حساب دون هذا الرابط.',
            ] : [
                'subject' => 'Confirm your email address',
                'title' => 'Confirm your email address',
                'intro' => 'Confirm this address is yours to finish your account.',
                'action' => 'Confirm my email',
                'not_you' => 'Ignore this message. No account opens without this link.',
            ],

            default => $ar ? [
                'subject' => 'رمز تسجيل الدخول',
                'title' => 'رمز تسجيل الدخول',
                'intro' => 'أدخل هذا الرمز لإكمال تسجيل الدخول.',
                'action' => 'تسجيل الدخول',
                'not_you' => 'قد يعرف شخص آخر كلمة مرورك. غيّرها الآن من إعدادات الأمان، ولا تستخدم هذا الرمز.',
            ] : [
                'subject' => 'Your sign-in code',
                'title' => 'Your sign-in code',
                'intro' => 'Enter this code to finish signing in.',
                'action' => 'Sign in',
                'not_you' => 'Somebody else may know your password. Change it now in your security settings, and do not use this code.',
            ],
        };
    }
}
