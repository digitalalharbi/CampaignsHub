<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\ReportSectionResolver;
use App\Domains\Reports\Sections\ResolvedSections;
use App\Domains\Reports\Sections\SectionContext;
use App\Domains\Reports\Sections\SectionSettings;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — the objective section goes through the ONE section registry.
 *
 * `objective_analytics` belongs to `objective_breakdown`: switching that section off removes the
 * figures from the payload (not only from the page), and a live payload carrying the objective
 * analytics without the older per-path leaders still counts as having something to show.
 */
final class ObjectiveAnalyticsSectionRegistrationTest extends TestCase
{
    private ReportSectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ReportSectionRegistry;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'totals' => ['spend' => 100.0],
            'objective_analytics' => ['families' => [['family' => 'awareness', 'kpis' => [['key' => 'spend', 'value' => 100.0, 'state' => 'reported']]]]],
        ];
    }

    private function resolve(array $sections): ResolvedSections
    {
        return (new ReportSectionResolver($this->registry))->resolve(
            SectionSettings::fromArray(['sections' => $sections], $this->registry),
            new SectionContext(audience: 'client', form: 'detailed', payload: $this->payload()),
        );
    }

    public function test_the_objective_breakdown_owns_the_objective_analytics(): void
    {
        $this->assertContains('objective_analytics', $this->registry->get('objective_breakdown')->payloadKeys);
    }

    public function test_it_is_available_on_the_objective_analytics_alone(): void
    {
        $this->assertTrue($this->resolve([])->isVisible('objective_breakdown'));
    }

    public function test_switching_the_section_off_removes_the_figures_from_the_payload(): void
    {
        $out = $this->resolve(['objective_breakdown' => false])->apply($this->payload());

        $this->assertArrayNotHasKey('objective_analytics', $out);
    }

    public function test_an_empty_objective_section_is_unavailable_not_an_empty_card(): void
    {
        $resolved = (new ReportSectionResolver($this->registry))->resolve(
            SectionSettings::fromArray(['sections' => []], $this->registry),
            new SectionContext(audience: 'client', form: 'detailed', payload: ['totals' => ['spend' => 1.0], 'objective_analytics' => null]),
        );

        $this->assertSame(ReportSectionResolver::DATA_UNAVAILABLE, $resolved->reasonFor('objective_breakdown'));
    }
}
