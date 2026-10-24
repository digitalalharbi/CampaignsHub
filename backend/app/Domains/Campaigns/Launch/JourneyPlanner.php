<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use InvalidArgumentException;

/**
 * LAUNCH-JOURNEY-001 — which campaign objective an account has EARNED for a business goal, from what it was seen to measure.
 *
 * Pure: it reads a readiness result and a platform's objective vocabulary and returns a PROPOSAL per account. It writes
 * nothing, calls no platform and changes no stored optimisation goal; a person (or the chat acting for them through the one
 * LaunchPlan) chooses. Three rules are fixed here and tested as rules, not as examples:
 *
 *   1. Evidence, not days. Moving a sales account forward is allowed when the event is ELIGIBLE (see LaunchReadiness), never
 *      because some number of days passed. `advance` says what evidence is still missing.
 *   2. Never a silent change. A proposal carries `silent_change: false` and the current stage; moving to the next stage is a
 *      new proposal the person accepts.
 *   3. Never a stop. A proposal never contains a pause: successful campaigns keep running while a better stage is offered.
 *
 * Stages for `sales`: `sales` (Purchase eligible) → `bridge` (a lower-funnel event is eligible, so that event is the
 * conversion to optimise for) → `measurement_first` (no usable event: traffic, plus the plan to start measuring).
 */
final class JourneyPlanner
{
    public const GOALS = ['sales', 'leads', 'awareness', 'traffic'];

    /** Business goal → the objective token each platform's write adapter understands. Checked against the adapter at call time. */
    private const OBJECTIVES = [
        'sales' => ['meta' => 'OUTCOME_SALES', 'snapchat' => 'SALES', 'tiktok' => 'PRODUCT_SALES', 'google' => 'SEARCH'],
        'leads' => ['meta' => 'OUTCOME_LEADS', 'snapchat' => 'LEADS', 'tiktok' => 'LEAD_GENERATION', 'google' => 'SEARCH'],
        'traffic' => ['meta' => 'OUTCOME_TRAFFIC', 'snapchat' => 'TRAFFIC', 'tiktok' => 'TRAFFIC', 'google' => 'SEARCH'],
        'awareness' => ['meta' => 'OUTCOME_AWARENESS', 'snapchat' => 'AWARENESS_AND_ENGAGEMENT', 'tiktok' => 'REACH', 'google' => 'SEARCH'],
    ];

    /** The event to optimise for at each stage, in the order a bridge prefers them. */
    private const BRIDGE_EVENTS = ['add_to_cart' => 'AddToCart', 'leads' => 'Lead'];

    /**
     * @param  array<string,mixed>  $readiness  one account's LaunchReadiness entry (events, tier)
     * @param  list<string>  $supported  the platform adapter's objective tokens
     * @param  array<string,mixed>  $project  LaunchReadiness `project` signals (store_linked, ga4_linked, live_conversion_destinations)
     * @return array<string,mixed>
     */
    public function plan(string $goal, string $provider, array $readiness, array $supported, array $project = []): array
    {
        if (! in_array($goal, self::GOALS, true)) {
            throw new InvalidArgumentException("Unknown business goal '{$goal}'.");
        }
        $events = $readiness['events'] ?? [];
        $status = static fn (string $e): string => (string) ($events[$e]['status'] ?? LaunchReadiness::NO_DATA);

        if ($goal === 'awareness' || $goal === 'traffic') {
            return $this->proposal($goal, $goal, $provider, $supported, null, ['goal_needs_no_conversion_event'], [], $events);
        }

        if ($goal === 'leads') {
            if ($status('leads') === LaunchReadiness::ELIGIBLE) {
                return $this->proposal('leads', 'leads', $provider, $supported, 'Lead', ['lead_event_eligible'], [], $events);
            }

            return $this->proposal('traffic', 'measurement_first', $provider, $supported, null, [$this->noData($readiness) ? 'no_synced_data' : 'lead_event_not_eligible'], $this->measurement(['leads'], $events, $project, $readiness), $events);
        }

        // sales
        if ($status('purchases') === LaunchReadiness::ELIGIBLE) {
            return $this->proposal('sales', 'sales', $provider, $supported, 'Purchase', ['purchase_event_eligible'], [], $events);
        }
        foreach (self::BRIDGE_EVENTS as $key => $label) {
            if ($status($key) === LaunchReadiness::ELIGIBLE) {
                return $this->proposal('sales', 'bridge', $provider, $supported, $label, ['purchase_event_not_eligible', 'intermediate_event_eligible'], $this->measurement(['purchases'], $events, $project, $readiness), $events);
            }
        }

        return $this->proposal('traffic', 'measurement_first', $provider, $supported, null, [$this->noData($readiness) ? 'no_synced_data' : 'no_usable_conversion_event'], $this->measurement(['purchases', 'add_to_cart'], $events, $project, $readiness), $events);
    }

    /**
     * What the person is shown to move from one stage to the next. It is a statement of missing EVIDENCE.
     *
     * @param  array<string,mixed>  $events
     * @return array<string,mixed>
     */
    private function advance(string $stage, array $events): array
    {
        if ($stage === 'sales' || $stage === 'leads') {
            return ['next' => null, 'needs' => []];
        }
        $needed = (int) config('launch.readiness.eligible_events', 50);
        $purchases = (int) ($events['purchases']['observed'] ?? 0);

        return [
            'next' => $stage === 'bridge' ? 'sales' : 'bridge',
            'needs' => [['event' => 'Purchase', 'status_needed' => LaunchReadiness::ELIGIBLE, 'observed' => $purchases, 'needed' => $needed]],
        ];
    }

    /**
     * @param  list<string>  $supported
     * @param  list<string>  $reasons
     * @param  list<array<string,mixed>>  $measurement
     * @param  array<string,mixed>  $events
     * @return array<string,mixed>
     */
    private function proposal(string $kind, string $stage, string $provider, array $supported, ?string $event, array $reasons, array $measurement, array $events): array
    {
        $token = self::OBJECTIVES[$kind][$provider] ?? null;
        $usable = $token !== null && in_array($token, $supported, true);

        return [
            'objective_kind' => $kind,
            'stage' => $stage,
            'objective' => $usable ? $token : null,
            'objective_refusal' => $usable ? null : 'no_supported_objective',
            'optimization_event' => $event,
            'reasons' => $reasons,
            'measurement_plan' => $measurement,
            'advance' => $this->advance($stage, $events),
            'silent_change' => false,
            'pauses_campaigns' => false,
        ];
    }

    /** @param array<string,mixed> $readiness */
    private function noData(array $readiness): bool
    {
        return ($readiness['data'] ?? null) === 'none';
    }

    /**
     * @param  list<string>  $missing  event keys the stage would like measured
     * @param  array<string,mixed>  $events
     * @param  array<string,mixed>  $project
     * @param  array<string,mixed>  $readiness
     * @return list<array<string,mixed>>
     */
    private function measurement(array $missing, array $events, array $project, array $readiness = []): array
    {
        // An account with no synced results says nothing about any event: the honest first step is to get its data in, not to assert
        // that a Purchase or a Lead event is missing from it.
        if ($this->noData($readiness)) {
            return [['event' => null, 'status' => LaunchReadiness::NO_DATA, 'action' => 'sync_the_account_first']];
        }
        $out = [];
        foreach ($missing as $key) {
            if (($events[$key]['status'] ?? LaunchReadiness::NO_DATA) === LaunchReadiness::ELIGIBLE) {
                continue;
            }
            $label = LaunchReadiness::EVENTS[$key] ?? $key;
            $action = match (true) {
                $key === 'purchases' && ! empty($project['store_linked']) => 'store_linked_but_platform_reports_no_purchase',
                $key === 'purchases' => 'check_the_platform_receives_the_purchase_event',
                $key === 'leads' => 'check_the_platform_receives_the_lead_event',
                default => 'check_the_platform_receives_the_event',
            };
            $out[] = ['event' => $label, 'status' => (string) ($events[$key]['status'] ?? LaunchReadiness::NO_DATA), 'action' => $action];
        }

        return $out;
    }
}
