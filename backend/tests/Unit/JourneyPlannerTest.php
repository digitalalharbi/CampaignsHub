<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Launch\JourneyPlanner;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LAUNCH-JOURNEY-001 — the objective an account has earned, from evidence; a proposal, never a change and never a stop.
 */
final class JourneyPlannerTest extends TestCase
{
    private const META = ['OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_ENGAGEMENT', 'OUTCOME_LEADS', 'OUTCOME_APP_PROMOTION', 'OUTCOME_SALES'];

    /** @param array<string,string> $status event key => unavailable|proven|eligible */
    private function readiness(array $status): array
    {
        $events = [];
        foreach (['page_views', 'add_to_cart', 'leads', 'purchases'] as $k) {
            $s = $status[$k] ?? 'unavailable';
            $events[$k] = ['event' => $k, 'status' => $s, 'observed' => $s === 'eligible' ? 80 : ($s === 'proven' ? 4 : 0)];
        }

        return ['events' => $events];
    }

    private function plan(string $goal, array $status, string $provider = 'meta', array $supported = self::META, array $project = []): array
    {
        return (new JourneyPlanner)->plan($goal, $provider, $this->readiness($status), $supported, $project);
    }

    public function test_an_eligible_purchase_goes_straight_to_a_sales_campaign(): void
    {
        $p = $this->plan('sales', ['purchases' => 'eligible', 'add_to_cart' => 'eligible']);

        $this->assertSame(['sales', 'sales', 'OUTCOME_SALES', 'Purchase'], [$p['objective_kind'], $p['stage'], $p['objective'], $p['optimization_event']]);
        $this->assertSame([], $p['measurement_plan']);
        $this->assertNull($p['advance']['next']);
    }

    public function test_partial_tracking_bridges_on_the_lower_funnel_event_that_is_eligible_and_says_what_purchase_still_needs(): void
    {
        $p = $this->plan('sales', ['purchases' => 'proven', 'add_to_cart' => 'eligible']);

        $this->assertSame(['sales', 'bridge', 'AddToCart'], [$p['objective_kind'], $p['stage'], $p['optimization_event']]);
        $this->assertSame('sales', $p['advance']['next']);
        $this->assertSame(['Purchase', 'eligible'], [$p['advance']['needs'][0]['event'], $p['advance']['needs'][0]['status_needed']]);
        $this->assertSame('Purchase', $p['measurement_plan'][0]['event']);
    }

    public function test_a_proven_purchase_alone_is_not_enough_volume_so_it_stays_on_traffic_with_a_plan(): void
    {
        $p = $this->plan('sales', ['purchases' => 'proven']);

        $this->assertSame(['traffic', 'measurement_first', 'OUTCOME_TRAFFIC', null], [$p['objective_kind'], $p['stage'], $p['objective'], $p['optimization_event']]);
        $this->assertSame('bridge', $p['advance']['next']);
    }

    public function test_no_tracking_is_traffic_first_with_a_measurement_plan_that_reads_the_store_signal(): void
    {
        $none = $this->plan('sales', []);
        $this->assertSame(['measurement_first', 'link_the_store_and_send_the_purchase_event'], [$none['stage'], $none['measurement_plan'][0]['action']]);

        $linked = $this->plan('sales', [], project: ['store_linked' => true]);
        $this->assertSame('store_linked_but_platform_reports_no_purchase', $linked['measurement_plan'][0]['action']);
    }

    public function test_a_proposal_never_changes_anything_silently_and_never_stops_a_campaign(): void
    {
        foreach (['sales', 'leads', 'awareness', 'traffic'] as $goal) {
            foreach ([[], ['purchases' => 'proven'], ['purchases' => 'eligible', 'leads' => 'eligible']] as $status) {
                $p = $this->plan($goal, $status);
                $this->assertFalse($p['silent_change']);
                $this->assertFalse($p['pauses_campaigns']);
                $this->assertArrayNotHasKey('days', $p['advance'], 'Advancing is a matter of evidence, not elapsed time.');
                $this->assertStringNotContainsString('pause', json_encode(array_diff_key($p, ['pauses_campaigns' => 1]), JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_leads_follow_the_lead_event_and_awareness_and_traffic_need_no_conversion_event(): void
    {
        $this->assertSame(['leads', 'OUTCOME_LEADS'], array_values(array_intersect_key($this->plan('leads', ['leads' => 'eligible']), ['objective_kind' => 1, 'objective' => 1])));
        $this->assertSame('measurement_first', $this->plan('leads', ['leads' => 'proven'])['stage']);
        $this->assertSame('OUTCOME_AWARENESS', $this->plan('awareness', [])['objective']);
        $this->assertSame('OUTCOME_TRAFFIC', $this->plan('traffic', [])['objective']);
    }

    public function test_an_objective_the_platform_cannot_create_is_refused_not_guessed(): void
    {
        $p = $this->plan('sales', ['purchases' => 'eligible'], 'tiktok', ['REACH', 'TRAFFIC']);

        $this->assertNull($p['objective']);
        $this->assertSame('no_supported_objective', $p['objective_refusal']);

        // Google creates search campaigns only — every goal maps to the one objective it has.
        $this->assertSame('SEARCH', $this->plan('leads', ['leads' => 'eligible'], 'google', ['SEARCH'])['objective']);
    }

    public function test_an_unknown_goal_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->plan('profit', []);
    }
}
