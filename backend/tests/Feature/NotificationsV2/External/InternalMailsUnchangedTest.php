<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\Support\MailGallery;
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
        $golden = json_decode((string) file_get_contents(__DIR__.'/fixtures/mail-gallery-golden.json'), true);
        $this->assertGreaterThan(60, count($golden));
        $changed = [];
        foreach ($golden as $name => $hash) {
            [$key, $locale] = explode('.', (string) $name);
            $html = (string) preg_replace('/© \d{4}/', '© YEAR', (string) MailGallery::render($key, $locale));
            if (md5($html) !== $hash) {
                $changed[] = $name;
            }
        }
        $this->assertSame([], $changed, 'these approved templates changed: '.implode(', ', $changed));
    }
}
