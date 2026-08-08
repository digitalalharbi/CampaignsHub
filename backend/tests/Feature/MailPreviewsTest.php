<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MAIL-008 — every email type renders, in both languages, on an empty machine.
 *
 * The command exists because SMTP credentials do not: the templates, the scheduler, the preferences
 * and the delivery ledger are complete and tested, and REAL SENDING IS `Awaiting Credentials`. Until
 * that changes, a rendered file is the only way anybody reviews this work — so the renderer itself
 * needs to be something the suite protects.
 *
 * The assertions are about the properties that survive an email client, not about the words: a
 * template that renders a blank page, loses its unsubscribe, or reaches for an external image is
 * broken in a way that only shows up in somebody's inbox.
 */
final class MailPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/mail-previews');
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function render(): array
    {
        $this->artisan('notifications:preview', ['--out' => $this->dir])->assertSuccessful();

        return collect(File::files($this->dir))
            ->mapWithKeys(fn ($f) => [$f->getFilename() => File::get($f->getPathname())])
            ->all();
    }

    /** Every message type, both languages — and the count is pinned so a silent loss is a failure. */
    public function test_every_message_type_renders_in_both_languages(): void
    {
        $files = $this->render();

        foreach ([
            'digest-daily', 'digest-weekly',
            // MAIL-013 — one bulletin per sweep, so one preview of it and one of the single-finding
            // case. Four separate `alert-*` previews would picture a product that no longer exists.
            'alerts-bundle', 'alerts-one',
            'report-ready', 'billing', 'approval', 'message',
            // MAIL-009 — the messages about somebody's account rather than their campaigns.
            'account-password-reset', 'account-email-verification', 'account-sign-in-code',
            'account-member-setup', 'account-invitation', 'account-invitation-unknown-role',
            'account-security-sign-in', 'account-security-sparse',
        ] as $kind) {
            $this->assertArrayHasKey("{$kind}.ar.html", $files, "{$kind} has no Arabic preview");
            $this->assertArrayHasKey("{$kind}.en.html", $files, "{$kind} has no English preview");
        }

        $this->assertCount(32, $files);
    }

    /**
     * A bulletin about several clients does not claim to be about one project — MAIL-013.
     *
     * Found by rendering it: three findings across two clients sat above the shell's «you follow
     * this project» line, which was written for a message about one. The sentence is the only thing
     * standing between an unexplained email and a spam report, so it has to be true.
     */
    public function test_a_bulletin_about_several_clients_says_projects_not_project(): void
    {
        $files = $this->render();

        $this->assertStringContainsString('تتابع هذه المشاريع', $files['alerts-bundle.ar.html']);
        $this->assertStringContainsString('follow these projects', $files['alerts-bundle.en.html']);

        // And one finding still reads as one.
        $this->assertStringContainsString('تتابع هذا المشروع', $files['alerts-one.ar.html']);
        $this->assertStringNotContainsString('تتابع هذه المشاريع', $files['alerts-one.ar.html']);
    }

    /** A preview that rendered a blank shell would pass every other assertion here. */
    public function test_no_preview_is_an_empty_shell(): void
    {
        foreach ($this->render() as $name => $html) {
            $this->assertGreaterThan(2000, strlen($html), "{$name} rendered almost nothing");
            $this->assertStringContainsString('CampaignsHub', $html);
        }
    }

    /**
     * Direction follows the language, on the element every client reads.
     *
     * A right-to-left email laid out left-to-right is harder to read than an untranslated one.
     */
    public function test_the_arabic_previews_are_right_to_left_and_the_english_are_not(): void
    {
        foreach ($this->render() as $name => $html) {
            $expected = str_contains($name, '.ar.') ? 'dir="rtl"' : 'dir="ltr"';
            $this->assertStringContainsString($expected, $html, "{$name} is laid out in the wrong direction");
        }
    }

    /**
     * No external images, in any of them.
     *
     * Most clients block them by default, so a logo would be a grey box and a chart would be
     * nothing at all. The identity is type and colour, both of which always render.
     */
    public function test_no_preview_reaches_for_an_image(): void
    {
        foreach ($this->render() as $name => $html) {
            $this->assertStringNotContainsString('<img', $html, "{$name} loads an image");
            $this->assertStringNotContainsString('background-image', $html, "{$name} loads a background image");
        }
    }

    /**
     * Every message carries the policies; only the ones a person subscribed to carry the way out.
     *
     * An unsubscribe a person cannot find is how a useful digest becomes a spam report — which costs
     * the sending domain, not just the message. But an unsubscribe on a PASSWORD RESET is an offer
     * the product will not honour: nothing in the preference centre can switch one off, and a reader
     * who follows that link from a security alert leaves the message that was asking them to act.
     *
     * Both directions are asserted, on purpose. Dropping the second half would let an account
     * message quietly regain the link, and dropping the first would let a digest lose it — and each
     * of those is a different kind of harm.
     */
    public function test_only_subscribed_messages_offer_the_way_out(): void
    {
        foreach ($this->render() as $name => $html) {
            $this->assertStringContainsString('/privacy', $html, "{$name} has no privacy link");

            if (str_starts_with($name, 'account-')) {
                $this->assertStringNotContainsString(
                    '/app/account/notifications', $html,
                    "{$name} offers to unsubscribe from a message that cannot be switched off",
                );

                continue;
            }

            $this->assertStringContainsString('/app/account/notifications', $html, "{$name} has no unsubscribe");
        }
    }

    /**
     * A secret never travels in the subject line — MAIL-009.
     *
     * Subjects are the part of an email that survives everywhere: a lock screen, a notification
     * bubble, an assistant reading mail aloud, a mail server's own logs. A six-digit code shown on a
     * locked phone has already been delivered to whoever is holding it.
     */
    public function test_no_credential_message_puts_its_secret_in_the_title(): void
    {
        foreach ($this->render() as $name => $html) {
            if (! str_starts_with($name, 'account-')) {
                continue;
            }

            preg_match('/<title>(.*?)<\/title>/s', $html, $m);
            $this->assertNotEmpty($m, "{$name} has no title");
            $this->assertStringNotContainsString('482913', $m[1], "{$name} put the code in its title");
            $this->assertStringNotContainsString('k7Qm3xZa', $m[1], "{$name} put the token in its title");
        }
    }

    /**
     * Arabic is never set in the tabular face.
     *
     * `SF Mono`, `Menlo` and `Consolas` carry no Arabic at all, so a place name set in one falls back
     * per glyph and loses its joining — «الرياض» is shown as «ا ل ر ي ا ض», which is not a spacing
     * problem but a word that stops reading as a word. Found by rendering the security template and
     * looking at it; this is what stops it coming back.
     */
    public function test_no_arabic_word_is_set_in_the_face_that_cannot_join_it(): void
    {
        $html = $this->render()['account-security-sign-in.ar.html'];

        // Every cell that names the monospace stack, with what it contains.
        preg_match_all('/font-family:[^"]*SF Mono[^"]*"[^>]*>([^<]*)</', $html, $matches);
        $this->assertNotEmpty($matches[1], 'the tabular face is not used at all — the fixture has drifted');

        foreach ($matches[1] as $content) {
            $this->assertDoesNotMatchRegularExpression(
                '/\p{Arabic}/u', $content,
                "Arabic set in a face that cannot join it: «{$content}»",
            );
        }
    }

    /**
     * A fact nobody knows is omitted, never printed as «unknown».
     *
     * A table with three rows of «غير معروف» reads as a broken feature and teaches the reader to skip
     * the table — which is the one part of a security message that does any work.
     */
    public function test_an_unknown_fact_leaves_no_row_behind(): void
    {
        $sparse = $this->render()['account-security-sparse.ar.html'];

        $this->assertStringContainsString('الوقت', $sparse, 'the one known fact is missing');
        foreach (['الجهاز', 'الموقع التقريبي', 'عنوان IP'] as $absent) {
            $this->assertStringNotContainsString($absent, $sparse, "«{$absent}» was printed with nothing in it");
        }
    }

    /**
     * The product declines to describe a role it does not know, rather than inventing one.
     *
     * A description guessed for an unknown role is a statement about somebody's ACCESS that nothing
     * checks — and access is the one thing in an invitation a reader takes literally.
     */
    public function test_an_unknown_role_is_named_but_not_described(): void
    {
        $files = $this->render();

        $this->assertStringContainsString('مدير حسابات', $files['account-invitation.ar.html']);
        $this->assertStringContainsString(
            'متابعة المشاريع المسندة إليه',
            $files['account-invitation.ar.html'],
            'a known role lost its description',
        );

        $unknown = $files['account-invitation-unknown-role.ar.html'];
        $this->assertStringContainsString('campaign_ops', $unknown, 'the unknown role is not named at all');
        $this->assertStringNotContainsString('متابعة المشاريع المسندة إليه', $unknown, 'a description was invented');
    }

    /**
     * The awkward pair, rendered side by side: a reported figure and an unreported one.
     *
     * This is the case that regresses, and it cannot be judged from a tidy fixture — which is why
     * the preview's own data carries it.
     */
    public function test_the_digest_preview_shows_a_reported_and_an_unreported_metric(): void
    {
        $html = $this->render()['digest-daily.ar.html'];

        $this->assertStringContainsString('23,333.18', $html, 'the reported spend is missing');
        // A funnel stage nobody sent is absent rather than drawn at zero.
        $this->assertStringNotContainsString('الإضافة للسلة', $html);
    }

    /**
     * The font stack survives Blade — MAIL-DS-001.
     *
     * The stack contains quoted family names, and `{{ }}` escapes each quote to `&#039;`. A browser
     * decodes that inside a style attribute; Outlook does not, so the whole declaration becomes
     * unparseable and every glyph falls back to the client's default — the precise failure the
     * Arabic stack exists to prevent. Caught by rendering and reading the output, not by a test.
     */
    public function test_every_preview_carries_a_usable_font_stack_for_its_language(): void
    {
        foreach ($this->render() as $name => $html) {
            $this->assertStringNotContainsString('font-family:&#039;', $html, "{$name} has an escaped font stack");

            if (str_contains($name, '.ar.')) {
                // Arabic faces, and first — order is what a client actually honours.
                $this->assertStringContainsString("font-family:'SF Arabic'", $html, "{$name} does not lead with an Arabic face");
                $this->assertStringContainsString('Noto Sans Arabic', $html);
            } else {
                $this->assertStringContainsString('font-family:-apple-system', $html, "{$name} does not use the Latin stack");
            }

            // Both stacks close with a generic, so a client with none of the named faces still
            // renders text rather than something nobody chose.
            $this->assertStringContainsString('sans-serif', $html);
        }
    }
}
