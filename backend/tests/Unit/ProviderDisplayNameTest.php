<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDisplayName;
use App\Domains\Integrations\Http\Controllers\PlatformOverviewController;
use Tests\TestCase;

/**
 * REPORT-PROVIDER-NAME-001 — «زيادة ميزانية meta تدريجيًا», in a document that leaves the product.
 */
final class ProviderDisplayNameTest extends TestCase
{
    public function test_every_provider_the_catalogue_defines_has_a_name(): void
    {
        foreach (ProviderCatalogue::keys() as $key) {
            $this->assertArrayHasKey(
                $key,
                ProviderDisplayName::NAMES,
                "{$key} would print as its own key inside a sentence a client reads.",
            );
        }
    }

    public function test_the_short_name_drops_the_parenthetical_that_reads_badly_in_prose(): void
    {
        // Right on an integrations card, clumsy inside «زيادة ميزانية … تدريجيًا».
        $this->assertSame('ميتا (فيسبوك وإنستقرام)', ProviderDisplayName::of('meta'));
        $this->assertSame('ميتا', ProviderDisplayName::short('meta'));
    }

    public function test_it_answers_in_both_languages(): void
    {
        $this->assertSame('سناب شات', ProviderDisplayName::short('snapchat', 'ar'));
        $this->assertSame('Snapchat Ads', ProviderDisplayName::short('snapchat', 'en'));
    }

    public function test_an_unknown_provider_returns_its_own_key_rather_than_a_placeholder(): void
    {
        // A connector the product does not recognise is a fact worth seeing; «غير معروف» hides which.
        $this->assertSame('pinterest', ProviderDisplayName::short('pinterest'));
    }

    public function test_the_platform_overview_reads_the_same_names_rather_than_a_second_copy(): void
    {
        foreach (PlatformOverviewController::PLATFORMS as $key => $entry) {
            $this->assertSame(ProviderDisplayName::NAMES[$key], $entry);
        }
    }

    public function test_the_lookup_is_forgiving_about_case_and_spacing(): void
    {
        $this->assertSame('تيك توك', ProviderDisplayName::short(' TikTok '));
    }
}
