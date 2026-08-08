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
            'alert-budget', 'alert-performance', 'alert-creative', 'alert-sync',
            'report-ready', 'billing', 'approval', 'message',
        ] as $kind) {
            $this->assertArrayHasKey("{$kind}.ar.html", $files, "{$kind} has no Arabic preview");
            $this->assertArrayHasKey("{$kind}.en.html", $files, "{$kind} has no English preview");
        }

        $this->assertCount(20, $files);
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
     * Every message carries the way out.
     *
     * An unsubscribe a person cannot find is how a useful digest becomes a spam report — which costs
     * the sending domain, not just the message.
     */
    public function test_every_preview_offers_the_way_out_and_the_policies(): void
    {
        foreach ($this->render() as $name => $html) {
            $this->assertStringContainsString('/app/account/notifications', $html, "{$name} has no unsubscribe");
            $this->assertStringContainsString('/privacy', $html, "{$name} has no privacy link");
        }
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
}
