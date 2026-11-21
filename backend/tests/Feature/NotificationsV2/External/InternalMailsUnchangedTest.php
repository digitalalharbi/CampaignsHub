<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Owner, 2026-11-15: «حافظ على كل قوالب الإشعارات والتنبيهات المعتادة». The external mode is added BESIDE the approved templates;
 * every internal template renders byte-for-byte as it did before the notifications-v2 external work. The golden file holds the
 * md5 of each gallery message that is deterministic (the daily-digest family embeds dates and is excluded), per language, with the
 * year of the © line neutralised. A deliberate redesign of a template must regenerate it knowingly.
 */
final class InternalMailsUnchangedTest extends TestCase
{
    public function test_every_internal_template_renders_exactly_as_approved(): void
    {
        // PERIOD-LABEL-001 — a digest names its period after the preset its days ARE («أمس», «الشهر الماضي»), which depends on
        // today; the golden hashes are pinned to this day so they do not drift with the calendar.
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'Asia/Riyadh'));
        $golden = json_decode((string) file_get_contents(__DIR__.'/fixtures/mail-gallery-golden.json'), true);
        $this->assertGreaterThan(60, count($golden));
        $changed = [];
        foreach ($golden as $name => $hash) {
            [$key, $locale] = explode('.', (string) $name);
            $html = (string) preg_replace('/© \d{4}/', '© YEAR', (string) MailGallery::render($key, $locale));
            if (md5($html) !== $hash) {
                $changed[] = $name.'='.md5($html);
            }
        }
        Carbon::setTestNow();
        $this->assertSame([], $changed, 'these approved templates changed: '.implode(', ', $changed));
    }
}
