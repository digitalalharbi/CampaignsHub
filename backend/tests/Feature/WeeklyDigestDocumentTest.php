<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use Tests\TestCase;

/**
 * EMAIL-REPORT-WEEKLY-001 — the weekly email is its own document, not the daily one with a different date.
 *
 * It names the week in its heading and its reason line, never says «yesterday», and carries the rhythm the
 * reader actually subscribed to; the daily one says the opposite on each count.
 */
final class WeeklyDigestDocumentTest extends TestCase
{
    public function test_the_weekly_document_speaks_of_the_week_and_the_daily_of_the_day_in_both_languages(): void
    {
        foreach (['ar' => ['week' => ['الأسبوع', 'الأسبوعي'], 'day' => ['أمس']], 'en' => ['week' => ['week', 'weekly digest'], 'day' => ['Yesterday', 'yesterday']]] as $locale => $words) {
            $weekly = (string) MailGallery::render('digest-weekly', $locale);
            $daily = (string) MailGallery::render('digest-daily', $locale);

            foreach ($words['week'] as $w) {
                $this->assertStringContainsString($w, $weekly, "[$locale] the weekly document never says «{$w}»");
            }
            foreach ($words['day'] as $d) {
                $this->assertStringNotContainsString($d, $weekly, "[$locale] the weekly document says «{$d}», which is the daily's word");
            }
            $this->assertNotSame($weekly, $daily, "[$locale] weekly and daily rendered the same document");
        }
    }

    public function test_the_weekly_message_carries_its_rhythm_in_the_reason_line(): void
    {
        $this->assertStringContainsString('weekly digest', (string) MailGallery::render('digest-weekly', 'en'));
        $this->assertStringNotContainsString('weekly digest', (string) MailGallery::render('digest-daily', 'en'));
        $this->assertStringContainsString('daily digest', (string) MailGallery::render('digest-daily', 'en'));
    }
}
