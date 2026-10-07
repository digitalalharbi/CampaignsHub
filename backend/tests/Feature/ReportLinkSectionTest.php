<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportLinks;
use App\Domains\Reports\Services\ReportStructure;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REPORT-LINK-SECTION-001 — a report can finally mention the address the money pointed at.
 *
 * A short link is the one artefact of a campaign that LEAVES this product: it is pasted into an ad,
 * read aloud, sent in a message. Reports described a month of spend without ever naming one, while
 * the clicks were counted the whole time on a page nobody opens while reading a report.
 *
 * The assertions that matter here are the REFUSALS. A section that prints a table of zeroes beside
 * links that were plainly being followed is worse than no section — a client reads it as «nobody
 * clicked» over a period that may have been their best, and nothing on the page says otherwise.
 */
final class ReportLinkSectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'P',
            'status' => 'active',
        ]);
    }

    private function link(string $slug, int $clicks = 0): ShortLink
    {
        return ShortLink::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'slug' => $slug,
            'kind' => ShortLink::KIND_LINK,
            'destination' => 'https://client.test/'.$slug,
            'source_value' => 'https://client.test/'.$slug,
            'clicks' => $clicks,
            'is_active' => true,
        ]);
    }

    private function hop(ShortLink $link, Carbon $at): void
    {
        ShortLinkHop::create([
            'tenant_id' => $this->tenant->id,
            'short_link_id' => $link->getKey(),
            'occurred_at' => $at,
        ]);
    }

    public function test_a_scope_with_no_short_link_says_exactly_that(): void
    {
        $out = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(7),
            Carbon::now(),
        );

        $this->assertSame([], $out['links']);
        $this->assertSame('no_short_link_in_this_scope', $out['absent_reason']);
    }

    /**
     * The refusal this unit exists for.
     *
     * The links are real and have real lifetime totals. Nothing was timed before the window closed,
     * so the window cannot be spoken for — and the alternative is a column of zeroes that reads as
     * a verdict on the client's month.
     */
    public function test_a_window_that_closed_before_recording_began_reports_no_figures_rather_than_zeroes(): void
    {
        $link = $this->link('rep0001', clicks: 240);

        /* Recording starts today; the report's window ended a fortnight ago. */
        $this->hop($link, Carbon::now());

        $out = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(30),
            Carbon::now()->subDays(14),
        );

        $this->assertSame([], $out['links'], 'no row, rather than one row reading zero');
        $this->assertSame('links_not_recorded_in_this_window', $out['absent_reason']);
    }

    /** Measured, and nobody came: that IS a figure, and the section stays to report it. */
    public function test_a_recorded_window_with_no_follows_still_reports_the_links(): void
    {
        $this->link('rep0002', clicks: 9);
        /* Something was recorded — for a different link, on a different day inside the window. */
        $other = $this->link('rep0003');
        $this->hop($other, Carbon::now()->subDays(2));

        $out = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(5),
            Carbon::now(),
        );

        $this->assertNull($out['absent_reason']);
        $this->assertCount(2, $out['links']);

        $quiet = collect($out['links'])->firstWhere('slug', 'rep0002');

        $this->assertSame(0, $quiet['follows'], 'the window was measured and this link was not followed');
        $this->assertSame(9, $quiet['clicks_all_time'], 'its lifetime counter is still reported, separately');
    }

    /** Follows are counted INSIDE the window, never the lifetime total. */
    public function test_only_follows_inside_the_window_are_counted(): void
    {
        $link = $this->link('rep0004', clicks: 100);

        $this->hop($link, Carbon::now()->subDays(20));
        $this->hop($link, Carbon::now()->subDays(2));
        $this->hop($link, Carbon::now()->subDay());

        $out = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(5),
            Carbon::now(),
        );

        $row = $out['links'][0];

        $this->assertSame(2, $row['follows'], 'the follow from twenty days ago is outside the window');
        $this->assertSame(100, $row['clicks_all_time'], 'and the lifetime counter is never summed into it');
    }

    /** Another project's links are not this report's business. */
    public function test_another_projects_links_are_not_in_this_report(): void
    {
        $this->link('rep0005');

        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'P2',
            'status' => 'active',
        ]);

        ShortLink::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $otherProject->id,
            'slug' => 'rep0006',
            'kind' => ShortLink::KIND_LINK,
            'destination' => 'https://client.test/elsewhere',
            'clicks' => 0,
            'is_active' => true,
        ]);

        $this->hop(ShortLink::query()->where('slug', 'rep0005')->firstOrFail(), Carbon::now());

        $out = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(5),
            Carbon::now(),
        );

        $this->assertSame(['rep0005'], array_column($out['links'], 'slug'));
    }

    /** Nothing in the row is an internal reference — see ClientEntityBoundary for the line. */
    public function test_a_row_carries_nothing_a_client_report_may_not_show(): void
    {
        $link = $this->link('rep0007', clicks: 3);
        $this->hop($link, Carbon::now());

        $row = app(ReportLinks::class)->forWindow(
            (string) $this->project->getKey(),
            Carbon::now()->subDays(5),
            Carbon::now(),
        )['links'][0];

        foreach (['id', 'project_id', 'tenant_id', 'created_by', 'campaign_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "a client report row must not carry {$forbidden}");
        }

        $this->assertSame('https://client.test/rep0007', $row['destination'], 'the client’s own address is theirs to see');
    }

    /** The structure places the section and carries the service's own reason when it is absent. */
    public function test_the_structure_places_the_section_and_repeats_its_reason(): void
    {
        $sections = (new ReportStructure)->sections([
            'links' => [],
            'links_absent_reason' => 'links_not_recorded_in_this_window',
        ]);

        $byKey = [];

        foreach ($sections as $section) {
            $byKey[$section['key']] = $section;
        }

        $this->assertArrayHasKey('links', $byKey);
        $this->assertFalse($byKey['links']['present']);
        $this->assertSame('links_not_recorded_in_this_window', $byKey['links']['absent_reason']);
        $this->assertStringContainsString('لم يُسجَّل', $byKey['links']['absent_reason_ar']);
        $this->assertStringContainsString('not the same as zero', $byKey['links']['absent_reason_en']);
    }
}
