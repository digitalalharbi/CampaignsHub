<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Http\Controllers\PlatformOverviewController as IntegrationsOverviewController;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Support\AdPlatforms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLATFORM-ORDER-001 — one order, everywhere, without exception.
 *
 * The order is a product decision: سناب شات، تيك توك، ميتا، جوجل أدز، إكس، لينكدإن. What made it
 * worth a test is not that it is hard to get right once, but that it was a literal beside each of six
 * screens — so it was right in some of them and wrong in the others, and nobody could tell by reading
 * any single file.
 */
final class PlatformOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_order_is_the_products_order(): void
    {
        $this->assertSame(
            ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'],
            AdPlatforms::ORDER,
        );
    }

    /** Every spelling this codebase uses resolves to the same rank. */
    public function test_the_same_platform_ranks_the_same_however_it_is_spelled(): void
    {
        foreach ([
            ['snapchat', 'snapchat_ads', 'snap'],
            ['tiktok', 'tiktok_ads'],
            ['meta', 'meta_ads', 'facebook', 'instagram'],
            ['google', 'google_ads'],
            ['x', 'x_ads', 'twitter'],
            ['linkedin', 'linkedin_ads'],
        ] as $spellings) {
            $ranks = array_map(AdPlatforms::rank(...), $spellings);
            $this->assertSame([$ranks[0]], array_values(array_unique($ranks)), implode(' / ', $spellings));
        }
    }

    /** An unknown platform sorts last rather than breaking the list it is in. */
    public function test_an_unknown_platform_sorts_last(): void
    {
        $this->assertSame(
            ['snapchat', 'meta', 'pinterest', 'reddit'],
            AdPlatforms::sort(['pinterest', 'meta', 'reddit', 'snapchat']),
        );
    }

    /**
     * Sorting reorders; it never rewrites.
     *
     * The keys that come out are the keys that went in — `google_ads` stays `google_ads`, because
     * these lists are used as API filters and connector ids, and a sort that helpfully canonicalised
     * them would return a list that no longer matches anything.
     */
    public function test_sorting_reorders_without_rewriting_the_keys(): void
    {
        $this->assertSame(
            ['snapchat', 'tiktok', 'meta_ads', 'google_ads', 'x', 'linkedin'],
            AdPlatforms::sort(['linkedin', 'google_ads', 'x', 'meta_ads', 'snapchat', 'tiktok']),
        );
    }

    /**
     * The integrations overview leads with Snapchat.
     *
     * The endpoint an operator opens to connect a platform, and the one that led with Meta while the
     * report engine led with Snapchat — the clearest instance of the drift this closes.
     */
    /**
     * The integrations overview leads with Snapchat.
     *
     * Asserted on the controller's own table rather than through the endpoint, which is project-scoped
     * and would need a tenant, a project and a membership built for it — none of which this is about.
     * The table IS what the endpoint iterates, in declaration order.
     */
    public function test_the_integrations_overview_is_in_the_products_order(): void
    {
        $platforms = (new \ReflectionClass(IntegrationsOverviewController::class))->getConstant('PLATFORMS');

        $this->assertSame(AdPlatforms::ORDER, array_keys((array) $platforms));
    }

    /** The report engine reads the shared order rather than keeping a second copy of it. */
    public function test_the_report_engine_reads_the_shared_order(): void
    {
        $reflected = new \ReflectionClass(ReportTemplateEngine::class);

        $this->assertSame(AdPlatforms::ORDER, $reflected->getConstant('PLATFORM_ORDER'));
    }
}
