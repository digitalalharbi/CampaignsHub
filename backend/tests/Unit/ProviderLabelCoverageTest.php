<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use Tests\TestCase;

/**
 * Every provider the catalogue declares has a reader's name in both languages.
 *
 * `__('providers.'.$key)` returns the KEY PATH when the entry is missing, and that is how
 * «providers.openai_ads» reached an account line on Production: the provider was added to the
 * catalogue and not to the two label files. A provider without a name fails here, before it is read.
 */
final class ProviderLabelCoverageTest extends TestCase
{
    public function test_every_catalogue_provider_is_named_in_arabic_and_english(): void
    {
        foreach (ProviderCatalogue::keys() as $key) {
            foreach (['ar', 'en'] as $locale) {
                $label = __('providers.'.$key, [], $locale);

                $this->assertNotSame('providers.'.$key, $label, "«{$key}» has no {$locale} label");
                $this->assertNotSame($key, $label, "«{$key}» is labelled with its own key in {$locale}");
                $this->assertNotSame('', trim((string) $label));
            }
        }
    }
}
