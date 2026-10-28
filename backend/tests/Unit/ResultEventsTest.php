<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Reports\Support\ResultEvents;
use PHPUnit\Framework\TestCase;

/**
 * RESULT-EVENTS-001 — Results are configured, per platform and acquisition path, and never inferred.
 */
final class ResultEventsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $campaign, string $provider, array $figures, array $reported): array
    {
        return ['campaign_id' => $campaign, 'provider' => $provider, 'spend' => 10.0, 'spend_withheld_rows' => 0, 'reported' => $reported] + $figures;
    }

    public function test_nothing_configured_means_no_results_even_when_the_platform_reported_conversions(): void
    {
        $out = ResultEvents::fromArray(null)->over([$this->row('c1', 'tiktok', ['conversions' => 700], ['conversions' => true])], []);

        $this->assertFalse($out['configured']);
        $this->assertNull($out['total'], 'a generic provider conversion is never promoted to Results');
        $this->assertNull($out['cost']);
    }

    public function test_the_two_acquisition_paths_stay_separate_and_unmapped_ones_contribute_nothing(): void
    {
        $events = ResultEvents::fromArray(['tiktok' => ['lead_form' => 'leads', 'landing_page' => 'registrations']]);
        $rows = [
            $this->row('form', 'tiktok', ['leads' => 3, 'conversions' => 900], ['leads' => true, 'conversions' => true]),
            $this->row('page', 'tiktok', ['registrations' => 5, 'conversions' => 700], ['registrations' => true, 'conversions' => true]),
            $this->row('unstated', 'tiktok', ['conversions' => 50], ['conversions' => true]),
        ];

        $out = $events->over($rows, ['form' => LeadDestination::LeadForm, 'page' => LeadDestination::Website]);

        $this->assertSame(8.0, $out['total']);
        $this->assertSame(['lead_form' => 3.0, 'landing_page' => 5.0], $out['by_path']);
        $this->assertSame(2.5, $out['cost'], 'cost per result is the contributing spend over the results');
    }

    public function test_a_mapped_metric_the_platform_never_reported_is_absence_not_zero(): void
    {
        $out = ResultEvents::fromArray(['snapchat' => ['all' => 'leads']])->over(
            [$this->row('c', 'snapchat', ['leads' => 0], ['leads' => false])],
            [],
        );

        $this->assertTrue($out['configured']);
        $this->assertNull($out['total']);
        $this->assertNull($out['cost']);
    }

    public function test_a_cost_per_result_over_withheld_money_is_absent(): void
    {
        $row = $this->row('c', 'meta', ['leads' => 4], ['leads' => true]);
        $row['spend_withheld_rows'] = 1;

        $this->assertNull(ResultEvents::fromArray(['meta' => ['all' => 'leads']])->over([$row], [])['cost']);
    }

    public function test_a_report_override_replaces_the_project_mapping_for_the_platforms_it_names_only(): void
    {
        $events = ResultEvents::merged(
            ['tiktok' => ['all' => 'conversions'], 'meta' => ['all' => 'leads']],
            ['tiktok' => ['all' => 'registrations']],
        );

        $this->assertSame('registrations', $events->metricFor('tiktok', null));
        $this->assertSame('leads', $events->metricFor('meta', null));
    }

    public function test_unknown_platforms_paths_and_metrics_are_dropped(): void
    {
        $events = ResultEvents::fromArray(['tiktok' => ['everywhere' => 'leads', 'all' => 'made_up'], 'nope' => ['all' => 'leads']]);

        $this->assertFalse($events->configured());
    }
}
