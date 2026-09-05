<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VISUAL-FIRST-001 — «email: a compact dashboard, not an essay … short bars where supported».
 *
 * The digest's path breakdown was three columns of text while the funnel directly beneath it already
 * drew email-safe bars — which is what proves the technique renders in the clients this product
 * sends to. A path breakdown answers «where did the money go», and that is read from bar lengths in
 * one pass and from a column of figures in several.
 *
 * The rules under test are the ones that keep the bar honest: scaled to the LARGEST path so the
 * smallest is still visible, floored so a path that spent something never draws as nothing, and
 * absent entirely for a path that spent nothing at all.
 */
final class DigestPathBarTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string,mixed>> */
    private function paths(array $buckets): array
    {
        $mail = new \ReflectionClass(DailyDigestMail::class);
        $method = $mail->getMethod('paths');
        $method->setAccessible(true);

        $instance = $mail->newInstanceWithoutConstructor();
        $presenter = new DigestPresenter('en', 1);

        return $method->invoke($instance, $presenter, false, $buckets);
    }

    public function test_each_path_carries_a_share_of_the_largest(): void
    {
        $rows = $this->paths([
            'awareness' => ['spend' => 2000.0, 'campaigns' => 2, 'cost_per_result' => null],
            'conversion' => ['spend' => 8000.0, 'campaigns' => 5, 'cost_per_result' => 86.84],
        ]);

        $byLabel = [];
        foreach ($rows as $r) {
            $byLabel[$r['label']] = $r['width'];
        }

        // The biggest fills the track; the other is a quarter of it, and both are visible.
        $this->assertSame(100, max($byLabel));
        $this->assertSame(25, min($byLabel));
    }

    /**
     * A path that spent something never draws as though it spent none.
     *
     * At a hundredth of the leader the honest width rounds to zero, and a zero-width bar is a bar
     * that says «nothing here» about money that was actually spent.
     */
    public function test_a_tiny_path_still_draws(): void
    {
        $rows = $this->paths([
            'awareness' => ['spend' => 10.0, 'campaigns' => 1, 'cost_per_result' => null],
            'conversion' => ['spend' => 100000.0, 'campaigns' => 5, 'cost_per_result' => 20.0],
        ]);

        foreach ($rows as $r) {
            $this->assertGreaterThanOrEqual(2, $r['width'], "«{$r['label']}» drew an invisible bar over real spend");
        }
    }

    /** A path that spent NOTHING is not a short bar — it is not a row at all. */
    public function test_a_path_with_no_spend_is_not_listed(): void
    {
        $rows = $this->paths([
            'awareness' => ['spend' => 0.0, 'campaigns' => 1, 'cost_per_result' => null],
            'conversion' => ['spend' => 500.0, 'campaigns' => 2, 'cost_per_result' => 20.0],
        ]);

        $this->assertCount(1, $rows);
    }

    /** And the gallery fixture renders the bar, so the template is exercised rather than asserted. */
    public function test_the_rendered_digest_carries_the_bar_markup(): void
    {
        $html = MailGallery::render('digest-daily', 'en');

        $this->assertNotNull($html, 'the gallery has no digest-daily message to render');
        // The email-safe technique: a percentage-width cell inside a nested presentation table.
        $this->assertMatchesRegularExpression('/width="\d+%" style="background-color:#0f766e/', $html);
    }
}
