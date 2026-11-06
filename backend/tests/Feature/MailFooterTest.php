<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\MailLinks;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * EMAIL-FOOTER-001 — Owner, 2026-11-06: «التنسيق الجديد غير لائق أبدًا».
 *
 * The footer is the four lines he approved, each drawn in its own cell with the reader's direction
 * written on it. His screenshot showed the Arabic footer from the left with its full stop at the wrong
 * end: the direction was on a wrapper only, and a mail client does not have to inherit it.
 *
 * A reader is given a way to manage mail rather than block the sender: the settings link on every
 * message, and the one-click way out (footer link and RFC 8058 headers) where the message is optional.
 */
final class MailFooterTest extends TestCase
{
    private const UNSUBSCRIBE = 'https://campaignshub.io/mail/unsubscribe/example-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail.default', 'array');
        config()->set('brand.frontend_url', 'https://campaignshub.io');
    }

    private function sent(string $key, string $locale, bool $optional): Email
    {
        $mail = MailGallery::messages($locale)[$key];
        if ($optional) {
            $mail->offerUnsubscribe(self::UNSUBSCRIBE);
        }
        Mail::mailer('array')->to('reader@example.test')->send($mail);
        $all = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $message = $all[count($all) - 1]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }

    /** @return array<string,string> each footer line's own `<td …>…</td>`, by name */
    private function lines(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'data-mail-footer="1"'));
        preg_match_all('#<td data-footer-line="([a-z]+)"[^>]*>.*?</td>#s', $html, $m);

        return array_combine($m[1], $m[0]);
    }

    private static function text(string $cell): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell))));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function messages(): array
    {
        return [
            'security, Arabic' => ['account-security-sign-in', 'ar', false],
            'security, English' => ['account-security-sign-in', 'en', false],
            'digest, Arabic' => ['digest-daily', 'ar', true],
            'digest, English' => ['digest-daily', 'en', true],
        ];
    }

    /** @dataProvider messages */
    public function test_every_line_carries_its_own_direction_and_alignment(string $key, string $locale, bool $optional): void
    {
        $ar = $locale === 'ar';
        $html = (string) $this->sent($key, $locale, $optional)->getHtmlBody();
        [$dir, $align] = $ar ? ['rtl', 'right'] : ['ltr', 'left'];

        $this->assertMatchesRegularExpression('#<table role="presentation" data-mail-footer="1" dir="'.$dir.'" align="'.$align.'" width="100%"#', $html);
        $this->assertMatchesRegularExpression('#<td class="ch-pad ch-ftr" dir="'.$dir.'" align="'.$align.'" bgcolor="\#faf8f3" style="padding:22px 24px 24px;[^"]*text-align:'.$align.'; direction:'.$dir.';"#', $html);

        $lines = $this->lines($html);
        $this->assertSame($optional ? ['policies', 'manage', 'copyright', 'sender'] : ['policies', 'manage', 'note', 'copyright', 'sender'], array_keys($lines));
        foreach ($lines as $name => $cell) {
            $this->assertMatchesRegularExpression('#^<td data-footer-line="'.$name.'" dir="'.$dir.'" align="'.$align.'" style="text-align:'.$align.'; direction:'.$dir.';#', $cell, $name);
            // Readable on the cream ground: 12px or more, a line-height that lets Arabic breathe.
            $this->assertMatchesRegularExpression('#font-size:1[23]px; line-height:1\.8;#', $cell, $name);
            $this->assertStringContainsString('color:#5b6b68;', $cell, $name);
        }
        $this->assertStringContainsString('font-size:13px', $lines['policies']);
        $this->assertStringContainsString('font-size:13px', $lines['manage']);
    }

    /** @dataProvider messages */
    public function test_the_four_lines_say_what_the_owner_approved(string $key, string $locale, bool $optional): void
    {
        $ar = $locale === 'ar';
        $lines = $this->lines((string) $this->sent($key, $locale, $optional)->getHtmlBody());

        $this->assertSame($ar ? 'الخصوصية · الشروط · الأمان · الدعم' : 'Privacy · Terms · Security · Support', self::text($lines['policies']));
        $urls = MailLinks::footer();
        foreach (['privacy', 'terms', 'security', 'support'] as $policy) {
            $this->assertStringContainsString('href="'.e($urls[$policy]).'"', $lines['policies'], $policy);
        }

        if (! $optional) {
            $this->assertSame($ar
                ? 'هذه رسالة تخص أمان حسابك، وتُرسل عند الحاجة فقط، ولا يمكن إيقافها من إعدادات الإشعارات.'
                : 'This message is about the security of your account. It is sent only when needed, and cannot be turned off in notification settings.', self::text($lines['note']));
        }

        // The year moves; the name and the host are Latin in both languages, held in one left-to-right run
        // so the Arabic line cannot reorder them or move the dot.
        $this->assertSame('© '.date('Y').' CampaignsHub · campaignshub.io', self::text($lines['copyright']));
        $this->assertMatchesRegularExpression('#<span dir="ltr" style="direction:ltr; unicode-bidi:embed; display:inline-block;">© \d{4} CampaignsHub · <a href="https://campaignshub\.io/?"[^>]*>campaignshub\.io</a></span>#', $lines['copyright']);

        $this->assertSame($ar
            ? 'أُرسلت هذه الرسالة من كامبينز هب — منصة إدارة الحملات الإعلانية المدفوعة.'
            : 'Sent by CampaignsHub — the paid-campaign management platform.', self::text($lines['sender']));
    }

    /** Manage, do not block: the settings link is on every message; the way out only where one exists. */
    public function test_an_optional_message_offers_settings_and_a_one_click_way_out(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $message = $this->sent('digest-daily', $locale, true);
            $manage = $this->lines((string) $message->getHtmlBody())['manage'];

            $this->assertSame($locale === 'ar' ? 'إعدادات الإشعارات · إلغاء الاشتراك' : 'Notification settings · Unsubscribe', self::text($manage));
            $this->assertStringContainsString('href="https://campaignshub.io/app/account/notifications"', $manage);
            $this->assertStringContainsString('href="'.self::UNSUBSCRIBE.'"', $manage);
            $this->assertSame('<'.self::UNSUBSCRIBE.'>', $message->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
            $this->assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        }
    }

    public function test_a_security_message_links_to_settings_and_offers_no_way_out(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $message = $this->sent('account-security-sign-in', $locale, false);
            $html = (string) $message->getHtmlBody();
            $manage = $this->lines($html)['manage'];

            $this->assertSame($locale === 'ar' ? 'إعدادات الإشعارات' : 'Notification settings', self::text($manage));
            $this->assertStringContainsString('href="https://campaignshub.io/app/account/notifications"', $manage);
            $this->assertStringNotContainsString('/mail/unsubscribe/', $html);
            $this->assertFalse($message->getHeaders()->has('List-Unsubscribe'));
            $this->assertFalse($message->getHeaders()->has('List-Unsubscribe-Post'));
        }
    }

    /** The settings link opens a page the product really has. */
    public function test_the_settings_link_is_a_route_the_app_declares(): void
    {
        $this->assertSame('https://campaignshub.io/app/account/notifications', MailLinks::footer()['preferences']);
        $router = (string) file_get_contents(base_path('../frontend/src/app/router.tsx'));
        $this->assertStringContainsString('/app/account/notifications', $router);
    }

    /** An optional message with no address offered (a preview, a refusal) shows no dead link. */
    public function test_no_way_out_is_drawn_without_an_address(): void
    {
        $manage = $this->lines((string) $this->sent('digest-daily', 'ar', false)->getHtmlBody())['manage'];
        $this->assertSame('إعدادات الإشعارات', self::text($manage));
    }
}
