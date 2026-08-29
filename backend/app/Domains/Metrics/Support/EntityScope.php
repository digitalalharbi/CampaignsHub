<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

/**
 * ANALYTICS-FILTER-TRUTH-001 — the filter row's three axes, carried to the entity grain.
 *
 * The drill-down endpoint read the window, the parent and the attribution basis, and nothing else.
 * Its own docblock said otherwise — that the provider and objective "come from the same request
 * helpers every other metric endpoint uses" — which was a description of an intention rather than
 * of the code: the ad-set and ad tables answered for the whole project under chips naming one
 * campaign, directly beneath a campaign table that had narrowed correctly.
 *
 * A small object rather than three more positional parameters on a method that already takes six.
 * The next axis added is then a field here, not a seventh argument that a caller can pass in the
 * wrong order and still typecheck.
 */
final readonly class EntityScope
{
    /**
     * @param  list<string>  $providers  canonical platform keys
     * @param  list<string>  $objectives  RAW objectives, as the metrics API filters on
     * @param  list<string>  $campaigns  UNIFIED campaign ids, as the filter row emits them
     */
    public function __construct(
        public array $providers = [],
        public array $objectives = [],
        public array $campaigns = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->providers === [] && $this->objectives === [] && $this->campaigns === [];
    }
}
