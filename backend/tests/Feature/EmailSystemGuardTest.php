<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use App\Domains\Notifications\Support\MailShell;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * EMAIL-SYSTEM-P0 §17 — a message cannot leave the one layout, the text part or the named subject.
 *
 * It walks the code rather than a list, so a Mailable added next year fails here until it joins the
 * gallery, and the gallery is what `mail:preview`, the operator console and this contract all read.
 * `violations()` is exercised against a deliberately bad mailable too: a guard nobody has seen fail
 * is a comment.
 */
final class EmailSystemGuardTest extends TestCase
{
    /** @return list<class-string> every Mailable under app/, found by reading the source */
    private function mailableClasses(): array
    {
        $classes = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php')->contains('/extends\s+Mailable\b/') as $file) {
            $source = $file->getContents();
            preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
            preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class);
            $classes[] = $ns[1].'\\'.$class[1];
        }
        sort($classes);

        return $classes;
    }

    /**
     * What a message must be, whichever template it is.
     *
     * @return list<string> what is wrong with it; empty means nothing
     */
    private function violations(Mailable $mail, string $locale): array
    {
        $wrong = [];
        $subject = (string) $mail->envelope()->subject;
        // Owner, 2026-11-05: the name is written in the reader's language, and that supersedes §17.3.1's Latin name on an Arabic subject.
        $opens = MailShell::wordmark($locale === 'ar').' — ';
        if (! str_starts_with($subject, $opens)) {
            $wrong[] = "subject does not start «{$opens}»: {$subject}";
        }
        if ($locale === 'ar' && str_contains($subject, 'CampaignsHub')) {
            $wrong[] = "an Arabic subject names the product in Latin: {$subject}";
        }

        Mail::mailer('array')->to('guard@example.test')->send($mail);
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $message = $sent[count($sent) - 1]->getOriginalMessage();

        $html = (string) $message->getHtmlBody();
        if (substr_count($html, 'class="ch-wrap"') !== 1) {
            $wrong[] = 'not on the shared layout (no single .ch-wrap container)';
        }
        if (! str_contains($html, 'CampaignsHub') && ! str_contains($html, 'كامبينز هَب')) {
            $wrong[] = 'the HTML part does not name CampaignsHub';
        }
        if (! str_contains($html, 'dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"')) {
            $wrong[] = 'wrong direction for its language';
        }
        // The container is fluid, or a client that drops <style> shows a phone a 600px page.
        if (! preg_match('/class="ch-wrap"[^>]*width:100%;\s*max-width:600px/', $html)) {
            $wrong[] = 'the container is not fluid without a media query';
        }
        if (preg_match_all('/<img\b(?![^>]*\balt=")[^>]*>/i', $html) > 0) {
            $wrong[] = 'an image has no alt text';
        }
        foreach (self::logoViolations($html, $locale) as $logo) {
            $wrong[] = $logo;
        }
        // Any fixed width above the container is a horizontal scroll on a phone.
        if (preg_match_all('/(?<![-\w])width:\s*(\d+)px/i', $html, $m) && max(array_map('intval', $m[1])) > 600) {
            $wrong[] = 'a fixed width is wider than the container';
        }

        $text = (string) $message->getTextBody();
        if (trim($text) === '') {
            $wrong[] = 'no text/plain part';
        }
        if (! str_contains($text, 'CampaignsHub') && ! str_contains($text, 'كامبينز هَب')) {
            $wrong[] = 'the text part does not name CampaignsHub';
        }

        return $wrong;
    }

    /**
     * EMAIL-LOGO-GUARD-001 — Owner, 2026-11-06: «واي مراسلات من ايميلات النظام: اضيف لها شعار النظام».
     *
     * Exactly one system logo, in the header band: the banner in the reader's language, from an absolute
     * https address on the app origin, with its box and its name written on the tag so a client that
     * blocks images still shows the band and the name.
     *
     * @return list<string>
     */
    private static function logoViolations(string $html, string $locale): array
    {
        preg_match_all('#<img\b[^>]*/brand/email/[^>]*>#i', $html, $found);
        if (count($found[0]) !== 1) {
            return ['the system logo is shown '.count($found[0]).' times, not once'];
        }

        $tag = $found[0][0];
        $wrong = [];
        if (! str_contains($tag, 'src="'.MailShell::logoUrl($locale === 'ar').'"')) {
            $wrong[] = 'the logo is not the banner in the reader\'s language';
        }
        if (preg_match('#src="https://[^"/]+/brand/email/banner-(ar|en)\.png"#', $tag) !== 1) {
            $wrong[] = 'the logo is not an absolute https address';
        }
        if (! str_contains($tag, 'width="'.MailShell::LOGO_WIDTH.'"') || ! str_contains($tag, 'height="'.MailShell::LOGO_HEIGHT.'"')) {
            $wrong[] = 'the logo has no width and height attributes';
        }
        if (! str_contains($tag, 'alt="'.MailShell::wordmark($locale === 'ar').'"')) {
            $wrong[] = 'the logo\'s alt text is not the product\'s name';
        }
        $band = strpos($html, 'class="ch-hdr"');
        if ($band === false || strpos($html, $tag) < $band) {
            $wrong[] = 'the logo is not in the header band';
        }

        return $wrong;
    }

    /** The file the address points at is in the repository, at the proportions the tag states. */
    public function test_the_logo_files_exist_at_the_stated_proportions(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $path = base_path('../frontend/public/brand/email/banner-'.$locale.'.png');
            $this->assertFileExists($path);
            [$width, $height] = (array) getimagesize($path);
            $this->assertSame(MailShell::LOGO_WIDTH * $height, MailShell::LOGO_HEIGHT * $width, "banner-{$locale}.png is not {$width}×{$height} in the tag's proportions");
            $this->assertGreaterThanOrEqual(2 * MailShell::LOGO_WIDTH, $width, 'the banner is not sharp on a 2x screen');
        }
    }

    /** What an admin sends as a test is the same message, so it carries the same logo. */
    public function test_a_test_send_carries_the_logo_too(): void
    {
        config()->set('mail.default', 'array');
        config()->set('brand.frontend_url', 'https://campaignshub.io');

        $found = [];
        foreach (['ar', 'en'] as $locale) {
            foreach ([null, ...MailGallery::keys()] as $key) {
                $mail = MailGallery::testMessage($key, $locale);
                $this->assertNotNull($mail);
                foreach (self::logoViolations((string) $mail->render(), $locale) as $wrong) {
                    $found[] = ($key ?? 'plain').".{$locale}: {$wrong}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_contract_catches_a_message_without_the_logo(): void
    {
        $band = '<table><tr><td class="ch-hdr">';
        $logo = '<img src="https://campaignshub.io/brand/email/banner-en.png" width="600" height="150" alt="CampaignsHub" />';
        config()->set('brand.frontend_url', 'https://campaignshub.io');

        $this->assertSame([], self::logoViolations($band.$logo, 'en'));
        $this->assertNotSame([], self::logoViolations($band.'<p>CampaignsHub</p>', 'en'), 'no logo at all');
        $this->assertNotSame([], self::logoViolations($band.$logo.$logo, 'en'), 'two logos');
        $this->assertNotSame([], self::logoViolations($band.$logo, 'ar'), 'the other language\'s banner');
        $this->assertNotSame([], self::logoViolations($band.str_replace(' height="150"', '', $logo), 'en'), 'no height');
        $this->assertNotSame([], self::logoViolations($band.str_replace('alt="CampaignsHub"', 'alt=""', $logo), 'en'), 'no name');
        $this->assertNotSame([], self::logoViolations($band.str_replace('https://campaignshub.io', '', $logo), 'en'), 'a relative address');
        $this->assertNotSame([], self::logoViolations($logo.$band, 'en'), 'outside the header band');
    }

    public function test_every_mailable_in_the_code_is_in_the_gallery(): void
    {
        $inGallery = array_values(array_unique(array_map('get_class', array_values(MailGallery::messages('ar')))));

        foreach ($this->mailableClasses() as $class) {
            $this->assertContains($class, $inGallery, "{$class} is not in MailGallery, so nothing renders or checks it");
        }
    }

    public function test_every_message_meets_the_contract_in_both_languages(): void
    {
        config()->set('mail.default', 'array');
        config()->set('brand.frontend_url', 'https://campaignshub.io');

        $found = [];
        foreach (['ar', 'en'] as $locale) {
            foreach (MailGallery::messages($locale) as $key => $mail) {
                foreach ($this->violations($mail, $locale) as $wrong) {
                    $found[] = "{$key}.{$locale}: {$wrong}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_contract_catches_a_message_that_bypasses_the_layout(): void
    {
        config()->set('mail.default', 'array');

        $wrong = $this->violations(new class extends Mailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Your report');
            }

            public function content(): Content
            {
                return new Content(htmlString: '<table width="800" style="width:800px"><tr><td><img src="x.png"></td></tr></table>');
            }
        }, 'en');

        $this->assertGreaterThanOrEqual(5, count($wrong), implode(' | ', $wrong));
    }

    /** Nothing composes a body by hand: `Mail::raw` is how the billing message once lost the shell. */
    public function test_no_code_sends_mail_outside_a_mailable(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            // A framework `MailMessage` (a Notification's `toMail`) renders Laravel's own template: no shell, no logo.
            if (preg_match('/Mail::(raw|html|plain)\(|\bMailMessage\b|function\s+toMail\s*\(/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    /** EMAIL-GAPS — an account message never offers a way out; the new optional ones show it in the footer. */
    public function test_the_unsubscribe_link_is_on_optional_messages_and_never_on_account_messages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (MailGallery::messages($locale) as $key => $mail) {
                $html = $mail->render();

                if (str_starts_with($key, 'account-')) {
                    $this->assertStringNotContainsString('/mail/unsubscribe/', $html, "{$key}.{$locale}");
                }
            }

            foreach (['report-scheduled', 'request-to-team', 'request-to-client'] as $key) {
                $this->assertStringContainsString('/mail/unsubscribe/', MailGallery::messages($locale)[$key]->render(), "{$key}.{$locale}");
            }
        }
    }

    /** The named set: what the spec lists has to exist in the gallery, in the words it asked for. */
    public function test_the_subjects_read_as_the_owner_asked(): void
    {
        $ar = MailGallery::messages('ar');
        $en = MailGallery::messages('en');

        $this->assertSame('كامبينز هَب — دعوة للانضمام إلى وكالة المدى', (string) $ar['account-invitation']->envelope()->subject);
        $this->assertStringStartsWith('CampaignsHub — You have been invited to join', (string) $en['account-invitation']->envelope()->subject);
        $this->assertStringStartsWith('كامبينز هَب — 3 تنبيهات', (string) $ar['alerts-bundle']->envelope()->subject);
        $this->assertStringStartsWith('كامبينز هَب — تقريرك الأسبوعي', (string) $ar['digest-weekly']->envelope()->subject);
        $this->assertStringStartsWith('CampaignsHub — Your weekly report', (string) $en['digest-weekly']->envelope()->subject);
        $this->assertStringStartsWith('كامبينز هَب — تقريرك الشهري', (string) $ar['digest-monthly']->envelope()->subject);
    }
}
