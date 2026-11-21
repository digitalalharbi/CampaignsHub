<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\PeriodResolver;
use App\Support\PeriodLabel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * PERIOD-LABEL-001 — the PHP twin reproduces the Owner's approved examples, read from the SAME file the frontend's
 * formatPeriodLabel.test.ts reads, so an export or a mail can never write a period differently from the page.
 */
final class PeriodLabelTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../frontend/src/lib/period/periodLabelExamples.json';

    /** @return array{today: string, cases: list<array<string, string>>} */
    private function fixture(): array
    {
        $json = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    public function test_every_approved_example_in_both_languages(): void
    {
        $fx = $this->fixture();
        self::assertNotEmpty($fx['cases']);

        foreach ($fx['cases'] as $c) {
            foreach (['ar', 'en'] as $locale) {
                self::assertSame($c[$locale], PeriodLabel::format($c['from'], $c['to'], $locale, $c['key']), "{$c['key']} {$c['from']}..{$c['to']} ({$locale})");
            }
        }
    }

    public function test_the_resolver_and_the_label_agree_on_the_days_of_each_example_preset(): void
    {
        $fx = $this->fixture();
        $now = Carbon::parse($fx['today'].' 10:00:00', 'Asia/Riyadh');

        foreach ($fx['cases'] as $c) {
            if ($c['key'] === 'custom') {
                continue;
            }
            $p = PeriodResolver::preset($c['key'], $now);
            self::assertSame([$c['from'], $c['to']], [$p['from'], $p['to']], $c['key']);
        }
    }

    public function test_latin_digits_en_dash_reversed_pair_and_open_ends(): void
    {
        self::assertSame('3 نوفمبر – 10 نوفمبر 2026', PeriodLabel::range('2026-11-10', '2026-11-03', 'ar'));
        self::assertMatchesRegularExpression('/^[0-9A-Za-z ,\x{2013}\x{0600}-\x{06FF}]+$/u', PeriodLabel::range('2026-11-03', '2026-11-10', 'ar'));
        self::assertSame('1 نوفمبر 2026 – …', PeriodLabel::bounds('2026-11-01', null, 'ar'));
        self::assertSame('… – Nov 9, 2026', PeriodLabel::bounds(null, '2026-11-09', 'en'));
        self::assertSame('Nov 3 – Nov 10, 2026', PeriodLabel::format(Carbon::parse('2026-11-03', 'Asia/Riyadh'), Carbon::parse('2026-11-10', 'Asia/Riyadh'), 'en'));
    }
}
