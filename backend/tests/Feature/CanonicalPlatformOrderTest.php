<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Support\AdPlatforms;
use Tests\TestCase;

/**
 * PLATFORM-ORDER-001 — one list, two languages, no drift.
 *
 * ## Why this exists now
 *
 * The order has had a single source on each side of the wire since it was written, and that was
 * enough while nobody added a platform. Adding one showed the gap: `AdPlatforms::ORDER` and
 * `PLATFORM_ORDER` are two literals that agree by hand, and the failure mode of two literals is not
 * an error — it is a product where a filter chip, a chart legend and a report list a platform in
 * three different places, which is the exact defect PLATFORM-ORDER-001 was written to end.
 *
 * So the agreement is PROVED rather than remembered. The frontend file is read as text because that
 * is the only way a PHP test can see it, and the alternative — publishing the order through an
 * endpoint the interface fetches at runtime — would put a network round trip in front of a constant
 * that is known at build time on both sides.
 *
 * ## And the COUNT is not part of the contract
 *
 * «The six platforms» was written into forty comments and a handful of assertions, and became an
 * architectural assumption by repetition rather than by anybody choosing it. These hold that nothing
 * in production logic knows how many there are.
 */
final class CanonicalPlatformOrderTest extends TestCase
{
    private function frontendSource(string $relative): string
    {
        $path = base_path('../frontend/'.$relative);

        $this->assertFileExists($path, "the interface no longer has {$relative} — this guard is reading a file that moved");

        return (string) file_get_contents($path);
    }

    /** Owner regression 2 — the two orders are the same order. */
    public function test_the_interface_lists_the_platforms_in_the_servers_order(): void
    {
        $source = $this->frontendSource('src/lib/platforms.ts');

        $this->assertSame(
            1,
            preg_match("/export const PLATFORM_ORDER = \[([^\]]*)\]/", $source, $match),
            'the interface no longer declares PLATFORM_ORDER the way this guard reads it',
        );

        preg_match_all("/'([a-z0-9_]+)'/", $match[1], $keys);

        $this->assertSame(
            AdPlatforms::ORDER,
            $keys[1],
            'the interface and the server disagree about the platform order, which is how one platform '
            .'comes to sit in three different places on three screens',
        );
    }

    /** Owner regression 1 — ChatGPT Ads is in the canonical registry, not bolted on beside it. */
    public function test_chatgpt_ads_is_a_canonical_platform(): void
    {
        $this->assertContains('openai_ads', AdPlatforms::ORDER);
        $this->assertSame('إعلانات ChatGPT', AdPlatforms::name('openai_ads', 'ar'));
        $this->assertSame('ChatGPT Ads', AdPlatforms::name('openai_ads', 'en'));

        /* Both the provider's name and the product's resolve to the one key. */
        foreach (['openai', 'chatgpt', 'chatgpt_ads', 'openai_advertising', 'OpenAI_Ads'] as $spelling) {
            $this->assertSame('openai_ads', AdPlatforms::canonical($spelling), "«{$spelling}» does not resolve");
        }
    }

    /** And it is a real advertising provider in the catalogue every surface reads. */
    public function test_chatgpt_ads_is_an_advertising_provider_in_the_catalogue(): void
    {
        $this->assertTrue(ProviderCatalogue::has('openai_ads'));

        $definition = ProviderCatalogue::get('openai_ads');

        $this->assertSame(ProviderKind::Advertising, $definition->kind);

        $advertising = array_map(
            static fn ($d): string => $d->key,
            ProviderCatalogue::ofKind(ProviderKind::Advertising),
        );

        $this->assertContains('openai_ads', $advertising);
    }

    /**
     * Owner regression 3 — nothing in production logic knows how many platforms there are.
     *
     * A literal six in a comment is a stale sentence; a literal six in an assertion or a slice is a
     * seventh platform silently dropped. This reads the production tree only — a test may legitimately
     * name the platforms it is exercising.
     */
    public function test_no_production_code_assumes_a_fixed_platform_count(): void
    {
        $offenders = [];

        foreach ($this->phpSources(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            /*
             * The shape that actually breaks: comparing a platform count to a literal the SIZE OF
             * THE REGISTRY, or slicing a platform list to a fixed length.
             *
             * Small literals are deliberately allowed, because they are facts about a row rather
             * than about the registry: `count($platforms) === 1` asks whether this campaign runs on
             * one platform, which is how `MetricsAggregator` decides a budget can be divided at all.
             * Five and up is the range where the only plausible meaning is «all of them», and that
             * is the assumption this guard exists to prevent. `count(self::ORDER)` is the registry
             * answering for itself and is not matched at all.
             */
            if (preg_match('/count\([^)]*[Pp]latform[^)]*\)\s*(===?|<|>|<=|>=)\s*(?:[5-9]|[1-9][0-9]+)\b/', $source)
                || preg_match('/array_slice\([^,]*[Pp]latform[^,]*,\s*0,\s*[0-9]+\)/', $source)) {
                $offenders[] = str_replace(app_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, "these files compare a platform count to a literal:\n  ".implode("\n  ", $offenders));
    }

    /** @return list<string> */
    private function phpSources(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
