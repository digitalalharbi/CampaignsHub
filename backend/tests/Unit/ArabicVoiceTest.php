<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportObservations;
use App\Support\AdPlatforms;
use PHPUnit\Framework\TestCase;

/**
 * MAIL-007 — the Arabic reads as though somebody wrote it.
 *
 * Copy is the part of a product that rots without anybody noticing: nothing fails, a string is
 * added in a hurry, and six months later half the sentences sound like a log file. These are the
 * rules that were agreed, expressed as assertions so a later change has to argue with them.
 *
 * The rule, stated once: plain, formal, and human. No dialect, no heavy classical, and above all no
 * machine voice — «تم اكتشاف انخفاض في المؤشر» describes a system noticing something. «تراجع معدل
 * النقر مقارنة بالفترة السابقة» describes what happened to the reader's campaign, which is the only
 * thing they asked about.
 */
final class ArabicVoiceTest extends TestCase
{
    /** Phrasings that mean a machine is talking about itself rather than about the reader's money. */
    private const MACHINE_VOICE = [
        'تم اكتشاف',
        'تم رصد',
        'تم تسجيل',
        'لم يتم',
        'يرجى ملاحظة',
        'النظام',
    ];

    private function notes(array $data, string $objective = 'sales'): array
    {
        return (new ReportObservations)->build(new ReportObjectiveLens($objective), $data + [
            'currency' => 'SAR',
            'kpis' => [],
            'delta' => [],
            'reported' => [],
            'metric_set' => [],
            'platforms' => [],
            'budget' => [],
        ]);
    }

    /** @return list<array<string,mixed>> every note this engine can produce, in one place */
    private function everyNote(): array
    {
        return array_merge(
            $this->notes([
                'kpis' => ['roas' => 4.0, 'ctr' => 0.02, 'frequency' => 6.0, 'cpa' => 60.0],
                'delta' => ['roas' => -0.4, 'ctr' => -0.3, 'cpa' => 0.5],
                'reported' => ['reach' => true],
                'budget' => [['campaign_id' => 'a', 'campaign_name' => 'حملة الصيف', 'budget' => 10000.0, 'spent' => 8000.0, 'pace' => 1.6]],
                'platforms' => [
                    ['provider' => 'meta', 'spend' => 5000, 'roas' => 8.0],
                    ['provider' => 'tiktok', 'spend' => 5000, 'roas' => 2.0],
                ],
                'freshness' => ['state' => 'failed', 'failing' => [['name' => 'سناب شات', 'provider' => 'snapchat']]],
            ]),
            $this->notes([
                'metric_set' => ['impressions', 'reach', 'video_views'],
                'reported' => ['impressions' => true, 'reach' => false, 'video_views' => false],
            ], 'awareness'),
        );
    }

    public function test_no_note_speaks_in_the_voice_of_a_machine(): void
    {
        $notes = $this->everyNote();
        $this->assertNotSame([], $notes);

        foreach ($notes as $note) {
            $text = $note['title'].' '.$note['detail'];
            foreach (self::MACHINE_VOICE as $phrase) {
                $this->assertStringNotContainsString($phrase, $text, "«{$phrase}» in: {$note['title']}");
            }
        }
    }

    /**
     * Every note is a sentence, ending in one.
     *
     * A fragment reads as a field value that escaped into the interface — which is what most of
     * these were before somebody decided they were copy.
     */
    public function test_every_detail_is_a_finished_sentence(): void
    {
        foreach ($this->everyNote() as $note) {
            $this->assertStringEndsWith('.', $note['detail'], "unfinished: {$note['detail']}");
            $this->assertGreaterThan(20, mb_strlen($note['detail']), "too terse to be a sentence: {$note['detail']}");
        }
    }

    /** No trailing «.0» anywhere: it is precision the figures do not have, and it reads as output. */
    public function test_no_percentage_carries_a_decimal_it_does_not_need(): void
    {
        foreach ($this->everyNote() as $note) {
            $this->assertDoesNotMatchRegularExpression('/\b\d+\.0%/', $note['detail'], "machine rounding in: {$note['detail']}");
        }
    }

    /** A platform is named, never keyed. «نحو tiktok» is an untranslated log line. */
    public function test_platforms_are_named_in_arabic_wherever_they_appear_in_prose(): void
    {
        foreach ($this->everyNote() as $note) {
            $text = $note['title'].' '.$note['detail'];
            foreach (['tiktok', 'snapchat', 'linkedin', 'google'] as $slug) {
                $this->assertStringNotContainsString($slug, $text, "raw key «{$slug}» in: {$text}");
            }
        }
    }

    /** The registry answers in both languages, and never with an empty string for a key it lacks. */
    public function test_the_platform_registry_names_every_platform_and_falls_back_to_the_key(): void
    {
        $this->assertSame('تيك توك', AdPlatforms::name('tiktok'));
        $this->assertSame('TikTok', AdPlatforms::name('tiktok', 'en'));
        // Aliases resolve: the same platform is spelled several ways across this codebase.
        $this->assertSame('جوجل', AdPlatforms::name('google_ads'));
        $this->assertSame('ميتا', AdPlatforms::name('facebook'));
        // An unknown platform reads as its own key rather than as a gap.
        $this->assertSame('pinterest', AdPlatforms::name('pinterest'));
    }

    /**
     * A judgement is withheld in words, not by silence — and never asserted on thin data.
     *
     * «لا تتوفر بيانات كافية» is the sentence the requirement asks for, and the alternative is worse
     * than a gap: a confident recommendation built on two days of delivery.
     */
    public function test_a_finding_is_not_asserted_where_the_data_cannot_carry_it(): void
    {
        // One platform: there is nothing to compare it with, so nothing is said about comparison.
        $notes = $this->notes(['platforms' => [['provider' => 'meta', 'spend' => 5000, 'roas' => 6.0]]]);
        $this->assertSame([], array_filter($notes, static fn (array $n): bool => $n['kind'] === 'reallocation'));

        // Frequency without reach: the ratio is absent, not low.
        $notes = $this->notes(['kpis' => ['frequency' => 6.0], 'reported' => ['reach' => false]]);
        $this->assertSame([], $notes);
    }
}
