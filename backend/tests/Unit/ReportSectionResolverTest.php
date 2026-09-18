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
 * REPORT-SECTION-MODEL-001 — one registry, one resolver, three reasons, and hidden means absent.
 */
final class ReportSectionResolverTest extends TestCase
{
    private ReportSectionRegistry $registry;

    private ReportSectionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ReportSectionRegistry;
        $this->resolver = new ReportSectionResolver($this->registry);
    }

    /** A payload in which every section has something to show. */
    private function fullPayload(): array
    {
        return [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'currency' => 'SAR',
            'totals' => ['spend' => 1200.0, 'conversions' => 30.0],
            'deltas' => ['spend' => 0.1],
            'timeseries' => [['date' => '2026-08-01', 'spend' => 600.0], ['date' => '2026-08-02', 'spend' => 600.0]],
            'platforms' => [['provider' => 'meta', 'spend' => 700.0], ['provider' => 'snapchat', 'spend' => 500.0]],
            'budget' => [['provider' => 'meta', 'budget' => 2000.0, 'spent' => 700.0]],
            'funnel' => [['stage' => 'impressions', 'reported' => true, 'count' => 90000]],
            'store_funnel' => null,
            'ads' => [['name' => 'Ad', 'spend' => 300.0]],
            'ads_roster' => [['name' => 'Ad']],
            'ads_absent_reason' => null,
            'recommendations' => [['title' => 'Shift budget']],
            'objective_leaders' => ['paths' => [['path' => 'direct_sales']]],
            'objective_performance' => ['paths' => [['path' => 'direct_sales']], 'direct' => [], 'blended' => []],
        ];
    }

    private function settings(array $sections = []): SectionSettings
    {
        return SectionSettings::fromArray(['sections' => $sections], $this->registry);
    }

    private function resolve(array $sections, ?array $payload, string $audience = 'client', string $form = 'detailed'): ResolvedSections
    {
        return $this->resolver->resolve($this->settings($sections), new SectionContext(audience: $audience, form: $form, payload: $payload));
    }

    public function test_the_registry_covers_the_sections_in_reading_order(): void
    {
        $this->assertSame([
            'kpis', 'trends', 'platform_comparison', 'budget_pacing', 'funnel', 'content_performance',
            'recommendations', 'detailed_tables', 'objective_breakdown', 'advanced_segmentation',
        ], $this->registry->keys());
    }

    public function test_a_client_report_starts_without_detailed_tables_or_advanced_segmentation(): void
    {
        $resolved = $this->resolve([], $this->fullPayload());

        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $resolved->reasonFor('detailed_tables'));
        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $resolved->reasonFor('advanced_segmentation'));
        $this->assertTrue($resolved->isVisible('kpis'));
        $this->assertTrue($resolved->isVisible('platform_comparison'));
    }

    public function test_an_internal_report_starts_with_everything_on(): void
    {
        $resolved = $this->resolve([], $this->fullPayload(), audience: 'internal');

        $this->assertSame($this->registry->keys(), $resolved->visible());
    }

    public function test_disabled_by_operator_removes_the_sections_keys_from_the_payload(): void
    {
        $payload = $this->fullPayload();
        $resolved = $this->resolve(['budget_pacing' => false], $payload);

        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $resolved->reasonFor('budget_pacing'));

        $out = $resolved->apply($payload);
        $this->assertArrayNotHasKey('budget', $out, 'A hidden section is absent, not sent empty.');
        $this->assertNotContains('budget_pacing', $out[ResolvedSections::PAYLOAD_KEY]);
    }

    public function test_unsupported_by_objective_is_its_own_reason(): void
    {
        $payload = ['ads' => [], 'ads_roster' => [], 'ads_absent_reason' => 'no_rankable_metric_for_this_objective'] + $this->fullPayload();
        $resolved = $this->resolve([], $payload);

        $this->assertSame(ReportSectionResolver::UNSUPPORTED, $resolved->reasonFor('content_performance'));
        $out = $resolved->apply($payload);
        $this->assertArrayNotHasKey('ads', $out);
        $this->assertArrayNotHasKey('ads_absent_reason', $out);
    }

    public function test_data_unavailable_is_its_own_reason(): void
    {
        $payload = ['budget' => [['provider' => 'meta', 'budget' => 0.0]]] + $this->fullPayload();
        $resolved = $this->resolve([], $payload);

        $this->assertSame(ReportSectionResolver::DATA_UNAVAILABLE, $resolved->reasonFor('budget_pacing'));
        $this->assertArrayNotHasKey('budget', $resolved->apply($payload));
    }

    public function test_the_operator_reason_wins_over_evidence(): void
    {
        $payload = ['budget' => []] + $this->fullPayload();

        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $this->resolve(['budget_pacing' => false], $payload)->reasonFor('budget_pacing'));
    }

    public function test_a_key_two_sections_share_stays_while_either_is_visible(): void
    {
        $payload = $this->fullPayload();

        // detailed_tables is off by default for a client; the comparison still owns `platforms`.
        $out = $this->resolve([], $payload)->apply($payload);
        $this->assertArrayHasKey('platforms', $out);

        $out = $this->resolve(['platform_comparison' => false], $payload)->apply($payload);
        $this->assertArrayNotHasKey('platforms', $out);

        $out = $this->resolve(['platform_comparison' => false, 'detailed_tables' => true], $payload)->apply($payload);
        $this->assertArrayHasKey('platforms', $out);
    }

    public function test_keys_no_section_owns_are_never_touched(): void
    {
        $payload = $this->fullPayload();
        $off = array_fill_keys($this->registry->keys(), false);

        $out = $this->resolve($off, $payload)->apply($payload);

        $this->assertSame($payload['period'], $out['period']);
        $this->assertSame('SAR', $out['currency']);
        $this->assertSame([], $out[ResolvedSections::PAYLOAD_KEY]);
    }

    public function test_an_executive_summary_does_not_contain_the_funnel(): void
    {
        $resolved = $this->resolve(['funnel' => true], $this->fullPayload(), form: 'executive_summary');

        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $resolved->reasonFor('funnel'));
    }

    public function test_without_figures_availability_is_not_judged(): void
    {
        $resolved = $this->resolve([], null);

        $this->assertFalse($resolved->availabilityJudged);
        $this->assertTrue($resolved->isVisible('budget_pacing'));
        $this->assertSame(ReportSectionResolver::DISABLED_BY_OPERATOR, $resolved->reasonFor('detailed_tables'));
    }

    public function test_a_registered_predicate_can_only_take_a_section_away(): void
    {
        $this->registry->supportWhen('funnel', 'needs_a_store', static fn (SectionContext $c): bool => false);
        $resolved = $this->resolve([], $this->fullPayload());

        $this->assertSame(ReportSectionResolver::UNSUPPORTED, $resolved->reasonFor('funnel'));
        $entry = array_values(array_filter($resolved->toArray(), static fn (array $e): bool => $e['key'] === 'funnel'))[0];
        $this->assertSame('needs_a_store', $entry['because']);
    }

    public function test_stored_choices_for_unknown_sections_are_dropped(): void
    {
        $settings = SectionSettings::fromArray(['sections' => ['kpis' => false, 'gone' => true, 'trends' => 'yes']], $this->registry);

        $this->assertSame(['sections' => ['kpis' => false]], $settings->toArray());
    }
}
