<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Coverage;

/**
 * AGGREGATION-TRUTH-001 — whether a total is the whole answer, and who is missing from it if not.
 *
 * ## What this exists to stop
 *
 * A sum of the contributors that happened to arrive, published under the label of the complete total.
 * That is not a smaller number — it is a wrong number wearing a right one's name, and nothing on the
 * page distinguishes it from the truth.
 *
 * The state travels BESIDE the value rather than inside it. A caller that wants arithmetic still gets
 * a number; a caller that wants to know whether it may be shown as «total spend» asks `isComplete()`.
 * Encoding incompleteness into the figure itself — as a null, or as a zero — is how this defect got
 * in: `COALESCE(SUM(value), 0)` is correct arithmetic and a lie about coverage, and the two questions
 * needed separating rather than a different answer to one of them.
 *
 * ## Why the exclusions are named
 *
 * «Partial» alone is unactionable. A reader who is told which platform is missing and why — stale
 * sync, failed sync, no exchange rate — can decide whether to wait, re-authorise, or read the number
 * anyway. A reader told only that something is missing cannot do any of those, so the reasons ride
 * with the state.
 */
final readonly class AggregateCoverage
{
    /**
     * @param  array<string, ContributionState>  $contributors  provider (or account) → its state
     * @param  array<string, string>  $reasons  contributor → human-readable evidence for its state
     */
    public function __construct(
        public array $contributors = [],
        public array $reasons = [],
    ) {}

    /** Nothing was expected and nothing is missing — the shape for an unbounded, fully-synced scope. */
    public static function complete(): self
    {
        return new self;
    }

    /** Contributors that were expected and whose figures are present. */
    public function included(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s->contributes());
    }

    /** Expected, and missing. These are what make a total partial. */
    public function degraded(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s->degradesTotal());
    }

    /** Not expected at all — outside their lifecycle. Their absence costs the total nothing. */
    public function inactive(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s === ContributionState::Inactive);
    }

    public function stale(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s === ContributionState::Stale);
    }

    public function failed(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s === ContributionState::Failed);
    }

    public function withheld(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s === ContributionState::WithheldFx);
    }

    public function unsupported(): array
    {
        return $this->keysWhere(fn (ContributionState $s) => $s === ContributionState::Unsupported);
    }

    /**
     * Whether this total may be presented as the complete answer to its question.
     *
     * The one method every caller should be asking before printing a figure as «total».
     */
    public function isComplete(): bool
    {
        return $this->degraded() === [];
    }

    /**
     * Whether a DERIVED figure may be computed from this coverage.
     *
     * Stricter than `isComplete()` by intent, though currently identical: a ratio inherits the
     * incompleteness of both its numerator and its denominator, and an incomplete ratio is worse than
     * an incomplete sum. «CPA 21 USD» computed over two thirds of the spend is not approximately the
     * CPA — it is a different quantity that looks like the CPA, and no disclosure beside it survives
     * being screenshotted.
     */
    public function allowsDerived(): bool
    {
        return $this->isComplete();
    }

    /**
     * The payload shape every surface reads. Named states, never a bare boolean.
     *
     * `expected_contributors` deliberately includes the degraded ones: the reader needs to know how
     * many were meant to be here, not merely how many arrived, or «3 of 3» and «3 of 5» look alike.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->isComplete() ? 'complete' : 'partial',
            'expected_contributors' => $this->keysWhere(fn (ContributionState $s) => $s->isExpected()),
            'included_contributors' => $this->included(),
            'inactive_contributors' => $this->inactive(),
            'stale_contributors' => $this->stale(),
            'failed_contributors' => $this->failed(),
            'withheld_contributors' => $this->withheld(),
            'unsupported_contributors' => $this->unsupported(),
            'excluded_contributors' => $this->degraded(),
            'reasons' => $this->reasons,
        ];
    }

    /** @param callable(ContributionState): bool $predicate */
    private function keysWhere(callable $predicate): array
    {
        return array_values(array_keys(array_filter($this->contributors, $predicate)));
    }
}
