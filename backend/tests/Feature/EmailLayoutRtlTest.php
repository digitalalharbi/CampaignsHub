<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EMAIL-RTL-001 — the account emails are centred and aligned by locale, not left-leaning.
 *
 * Gmail drops `dir` from <html>, so alignment has to be on the cells themselves; these assertions
 * pin the cells, the centred CTA, the bidi island around the raw URL and the Latin digits.
 */
final class EmailLayoutRtlTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATED = [
        'account-email-verification', 'account-password-reset', 'account-member-setup',
        'account-sign-in-code', 'account-security-sign-in', 'account-invitation', 'account-owner-invitation',
    ];

    /** @return array<string, array{string,string}> */
    public static function cases(): array
    {
        $out = [];
        foreach (self::MIGRATED as $key) {
            foreach (['ar', 'en'] as $locale) {
                $out["{$key} {$locale}"] = [$key, $locale];
            }
        }

        return $out;
    }

    /** @dataProvider cases */
    public function test_layout_is_aligned_by_locale(string $key, string $locale): void
    {
        $html = (string) MailGallery::render($key, $locale);
        $ar = $locale === 'ar';
        $align = $ar ? 'right' : 'left';

        $this->assertStringContainsString('lang="'.$locale.'" dir="'.($ar ? 'rtl' : 'ltr').'"', $html);
        $this->assertMatchesRegularExpression('/<body dir="'.($ar ? 'rtl' : 'ltr').'"/', $html);
        // Header lockup is centred; body and footer cells carry the locale's alignment.
        $this->assertMatchesRegularExpression('/<td class="ch-hdr" align="'.$align.'"[^>]*bgcolor="#0b1a16"[^>]*border-bottom:3px solid #0b7a62/', $html);
        $this->assertStringContainsString($ar ? 'كامبينز هَب' : 'CampaignsHub', $html);
        // One name, in the reader's language (Owner, 2026-11-05): never both side by side.
        // The one exception is the footer's copyright line, in the Owner's own words (2026-11-06): «© 2026 CampaignsHub · campaignshub.io».
        $withoutCopyright = (string) preg_replace('#<td data-footer-line="copyright".*?</td>#s', '', $html);
        $this->assertStringNotContainsString($ar ? 'CampaignsHub ·' : 'كامبينز', $ar ? $withoutCopyright : (string) preg_replace('#<td data-footer-line="sender".*?</td>#s', '', $html));
        $this->assertMatchesRegularExpression('/<img src="[^"]*\/brand\/email\/banner-'.($ar ? 'ar' : 'en').'\.png" width="600" height="150" alt="/', $html);
        $this->assertDoesNotMatchRegularExpression('/lockup|\.jpe?g/i', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark" />', $html);
        $this->assertStringContainsString('<meta name="supported-color-schemes" content="light dark" />', $html);
        $this->assertStringContainsString('[data-ogsc] .ch-hdr', $html);
        $this->assertMatchesRegularExpression('/<td class="ch-pad" dir="'.($ar ? 'rtl' : 'ltr').'" align="'.$align.'"[^>]*text-align:'.$align.'/', $html);
        $this->assertSame(2, preg_match_all('/<td class="ch-pad(?: ch-ftr)?" dir="[a-z]+" align="'.$align.'"/', $html));
        $this->assertStringNotContainsString('align="'.($ar ? 'left' : 'right').'" bgcolor', $html);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $html);
        $this->assertStringContainsString('Segoe UI', $html);
        $this->assertStringContainsString('sans-serif', $html);
        // EMAIL-LAYOUT-001 — centred and fluid with no media query; the fixed 600 is Outlook's alone.
        $this->assertMatchesRegularExpression('/<table role="presentation" class="ch-wrap" align="center" width="100%"[^>]*width:100%; max-width:600px/', $html);
        $this->assertStringContainsString('<!--[if mso]><table role="presentation" align="center" width="600"', $html);
    }

    /** @dataProvider cases */
    public function test_cta_is_centred_and_raw_url_is_an_ltr_island(string $key, string $locale): void
    {
        $html = (string) MailGallery::render($key, $locale);

        if (str_contains($key, 'sign-in-code')) {
            $this->assertStringNotContainsString('class="ch-btn"', $html, 'a code message has no button');

            return;
        }

        $this->assertMatchesRegularExpression('/<td align="center" bgcolor="#0b7a62"[^>]*>\s*<a class="ch-btn"[^>]*min-height:44px/', $html);

        if (str_contains($key, 'security')) {
            return; // the security alert's action is "this was not me"; it carries no token URL
        }

        $this->assertMatchesRegularExpression('/<span class="ch-url" dir="ltr" style="[^"]*unicode-bidi:embed[^"]*word-break:break-all/', $html);
        $align = $locale === 'ar' ? 'right' : 'left';
        $this->assertMatchesRegularExpression('/<td dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'" align="'.$align.'" style="padding-top:14px/', $html);
    }

    public function test_footer_links_follow_reading_order(): void
    {
        // Only the footer's own links: the body copy can use the same words earlier.
        $tail = fn (string $html) => substr($html, (int) strrpos($html, 'Privacy') ?: (int) strrpos($html, 'الخصوصية'));
        $ar = $tail((string) MailGallery::render('account-password-reset', 'ar'));
        $en = $tail((string) MailGallery::render('account-password-reset', 'en'));

        $this->assertLessThan(strpos($ar, 'الشروط'), strpos($ar, 'الخصوصية'));
        $this->assertLessThan(strpos($ar, 'الأمان'), strpos($ar, 'الشروط'));
        $this->assertLessThan(strpos($ar, 'الدعم'), strpos($ar, 'الأمان'));
        $this->assertLessThan(strpos($en, 'Terms'), strpos($en, 'Privacy'));
        $this->assertLessThan(strpos($en, 'Support'), strpos($en, 'Security'));
    }

    public function test_every_gallery_message_renders_in_both_locales_without_arabic_indic_digits(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (MailGallery::keys() as $key) {
                $html = (string) MailGallery::render($key, $locale);
                $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html, $key);
                $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $html, "{$key} {$locale}");
            }
        }
    }

    public function test_sending_still_uses_the_same_subject_and_recipient(): void
    {
        Mail::fake();

        foreach (['ar', 'en'] as $locale) {
            foreach (MailGallery::messages($locale) as $key => $mailable) {
                $subject = $mailable->envelope()->subject;
                Mail::to('person@example.com')->send($mailable);
                Mail::assertSent($mailable::class, fn ($m) => $m->hasTo('person@example.com') && $m->envelope()->subject === $subject);
            }
        }
    }

    public function test_mail_preview_command_writes_one_file(): void
    {
        $out = storage_path('framework/testing/mail-preview-one.html');
        @unlink($out);

        $this->artisan('mail:preview', ['mailable' => 'verification', '--locale' => 'en', '--out' => $out])->assertSuccessful();
        $this->assertStringContainsString('dir="ltr"', (string) file_get_contents($out));
        $this->artisan('mail:preview', ['mailable' => 'nope'])->assertFailed();
        @unlink($out);
    }

    public function test_owner_slug_has_a_role_and_unknown_slugs_are_humanised(): void
    {
        $owner = (string) MailGallery::render('account-owner-invitation', 'en');
        $this->assertStringContainsString('Workspace owner', $owner);
        $this->assertStringNotContainsString('tenant-owner', $owner);
        $this->assertStringContainsString('مالك مساحة العمل', (string) MailGallery::render('account-owner-invitation', 'ar'));

        $unknownEn = (string) MailGallery::render('account-invitation-unknown-role', 'en');
        $this->assertStringContainsString('Campaign ops', $unknownEn);
        $this->assertStringNotContainsString('campaign_ops', $unknownEn);
        $unknownAr = (string) MailGallery::render('account-invitation-unknown-role', 'ar');
        $this->assertStringContainsString('عضو', $unknownAr);
        $this->assertStringNotContainsString('campaign_ops', $unknownAr);
    }

    /**
     * EMAIL-TEST-SEND-001 — seen in the rendered image: an Arabic label inside `dir="ltr"` put the percent
     * sign before the word («%النتائج ▼ 12.0»). The line follows the reader; only the figure is left to right.
     */
    public function test_the_movement_line_reads_in_the_readers_direction(): void
    {
        $html = (string) MailGallery::render('digest-daily', 'ar');

        $this->assertDoesNotMatchRegularExpression('/dir="ltr">\s*النتائج/u', $html);
        $this->assertStringContainsString("النتائج \u{2066}▼ 12.0%\u{2069}", $html);
        $this->assertStringContainsString('Results ▼ 12.0%', (string) MailGallery::render('digest-daily', 'en'));
    }
}
