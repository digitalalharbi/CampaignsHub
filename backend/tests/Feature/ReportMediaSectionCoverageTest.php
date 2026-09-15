<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportCreativeMedia;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-CREATIVE-MEDIA-001 — the media refresh reaches every section that holds an ad.
 *
 * ## The defect
 *
 * The refresh replaces a stored preview whose URL has expired with one the library can still
 * resolve. It walked the document from a list of section names, and the list is the part that rots:
 * `ads_platform_groups` arrived with the detailed report's platform rung and was never added, so an
 * ad in that section kept its dead URL while the same ad one section over was refreshed. The
 * expired-media defect, surviving in the newest place it can occur.
 *
 * ## Why the assertion is about depth rather than about that section
 *
 * The same omission has now happened three times in this codebase, always to a list of section
 * names and always to the section added last. So the fixture nests an ads list under names this
 * test invents: the rule being asserted is «an ads list, wherever it is», not «the sections somebody
 * remembered». A test naming `ads_platform_groups` would pass on the day a fourth section is added
 * and say nothing — which is the failure, not the instance of it.
 *
 * ## And the walk stays keyed on `ads`
 *
 * The original docblock gives the reason to keep it that way: a sweep for «anything with an id»
 * would also find campaigns, ad sets, platforms and funnel stages and hand each of them a
 * creative's preview envelope. That reasoning is preserved — what changed is where the walk looks,
 * not what it recognises.
 */
final class ReportMediaSectionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private string $creativeId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'M', 'slug' => 'md-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'Media', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $project->getKey());

        $this->creativeId = (string) ExternalCreative::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'provider' => 'meta',
            'external_creative_id' => 'ec-media-1',
            'name' => 'Film',
            'format' => 'image',
            'asset_url' => 'https://cdn.test/fresh.jpg',
        ])->getKey();
    }

    public function test_an_ad_in_any_section_gets_the_media_the_library_can_still_resolve(): void
    {
        $stale = ['id' => $this->creativeId, 'preview' => ['image_url' => 'https://cdn.expired/dead.jpg']];

        $refreshed = app(ReportCreativeMedia::class)->refresh([
            'ads' => [$stale],
            'ads_groups' => [['key' => 'video', 'ads' => [$stale]]],
            'ads_platform_groups' => [[
                'provider' => 'meta',
                'groups' => [['key' => 'sales', 'ads' => [$stale]]],
            ]],
            // Invented, and nested deeper than anything the product has today.
            'some_future_section' => [['tiers' => [['ads' => [$stale]]]]],
        ]);

        $rows = [
            'the top-level list' => $refreshed['ads'][0],
            'a content group' => $refreshed['ads_groups'][0]['ads'][0],
            'a platform group' => $refreshed['ads_platform_groups'][0]['groups'][0]['ads'][0],
            'a section nobody enumerated' => $refreshed['some_future_section'][0]['tiers'][0]['ads'][0],
        ];

        foreach ($rows as $where => $row) {
            self::assertNotSame(
                'https://cdn.expired/dead.jpg',
                $row['preview']['image_url'] ?? null,
                "the ad in {$where} kept its expired media",
            );
        }
    }

    /**
     * And a row the walk must NOT touch is still untouched.
     *
     * The sections are named for a reason the original docblock states: a sweep for «anything with
     * an id» would hand a campaign a creative's preview envelope. A campaign row carrying the same
     * id as a real creative is the case that would expose a walk that stopped caring what it was
     * looking at.
     */
    public function test_a_row_that_is_not_an_ad_is_left_alone(): void
    {
        $refreshed = app(ReportCreativeMedia::class)->refresh([
            'campaigns' => [['id' => $this->creativeId, 'name' => 'Sale']],
            'platforms' => [['id' => $this->creativeId, 'provider' => 'meta']],
        ]);

        self::assertArrayNotHasKey('preview', $refreshed['campaigns'][0], 'a campaign was given a creative’s preview');
        self::assertArrayNotHasKey('preview', $refreshed['platforms'][0], 'a platform was given a creative’s preview');
    }
}
