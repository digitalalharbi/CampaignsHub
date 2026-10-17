<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * BRAND-LANG-001 — an Arabic sentence names the product in Arabic.
 *
 * «كامبينز هب» in Arabic, «CampaignsHub» in English. An Arabic email, notification or observation that put
 * the Latin wordmark inside the sentence showed the English name to an Arabic reader. A source guard,
 * because the same sentence is copied into every new message.
 */
final class ArabicBrandNameTest extends TestCase
{
    public function test_no_arabic_sentence_carries_the_latin_product_name(): void
    {
        $offenders = [];
        foreach (['app', 'resources/views'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! preg_match('/\.(php)$/', (string) $file)) {
                    continue;
                }
                foreach (file((string) $file) ?: [] as $n => $line) {
                    $t = ltrim($line);
                    if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*') || str_starts_with($t, '{{--') || ! str_contains($line, 'CampaignsHub')) {
                        continue;
                    }
                    if (str_contains($line, 'config(') && str_contains($line, 'brand.name')) {
                        continue;
                    }
                    if (preg_match('/[\x{0600}-\x{06FF}][\s«"\'(]*CampaignsHub|CampaignsHub[\s»"\'),.،]*[\x{0600}-\x{06FF}]/u', $line) === 1) {
                        $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', (string) $file).':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Arabic copy names the product in Latin:\n  ".implode("\n  ", $offenders));
    }
}
