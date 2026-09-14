<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * Which optional SECTIONS a client link may open — ATTRIB-VIS-001.
 *
 * ## Why attribution needed a switch of its own
 *
 * The attribution panel answers «the platforms claim 1,169 orders and your shop recorded 640».
 * That is the most useful page in the product for an advertiser who wants to know what their money
 * did — and it is also a sentence about the agency's own reporting, so publishing it is a decision
 * an operator has to make deliberately, per link, per client.
 *
 * It has therefore lived on the operator's analytics tab and nowhere else. Adding it to the client
 * link without a switch would have published figures on every existing link the day it shipped,
 * with nobody asked.
 *
 * ## Fail-closed, and closed by default
 *
 * Off unless a link says otherwise — including every link created before this existed, which is the
 * correct direction to be wrong in. `CreativeVisibility` reached the same conclusion for the same
 * reason; this is deliberately its sibling rather than a second pattern.
 *
 * ## Off means ABSENT, not hidden
 *
 * The endpoint refuses, the payload does not carry the block, and the export cannot contain what the
 * payload never held. A section removed from the UI while its data still travels in the JSON is not
 * a permission, it is a CSS rule — and the network tab is one keystroke away.
 */
final class ShareSections
{
    /** The flags an operator sets, in the order the link builder shows them. */
    public const FLAGS = [
        'attribution',
        'platform_comparison',
        'objective_breakdown',
        'creatives',
        'budget',
        'funnel_store',
        'previous_comparison',
    ];

    /**
     * DISCLOSURE flags fail closed; DISPLAY flags do not — and the difference is deliberate.
     *
     * `attribution` publishes a sentence about the agency's own reporting, so a link that never asked
     * for it must not acquire it: off unless said otherwise, including every link built before it
     * existed. That reasoning is in the note above and is unchanged.
     *
     * The six below are sections a live link has ALWAYS rendered. Making them fail closed would empty
     * every link in existence the day this shipped — the opposite of the direction to be wrong in for
     * a display toggle, where «off» is a choice somebody made rather than a permission nobody granted.
     * So an absent key means ON for these, and OFF is only ever explicit.
     */
    private const DISCLOSURE = ['attribution'];

    private function __construct(
        public readonly bool $attribution,
        public readonly bool $platform_comparison,
        public readonly bool $objective_breakdown,
        public readonly bool $creatives,
        public readonly bool $budget,
        public readonly bool $funnel_store,
        public readonly bool $previous_comparison,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $flag = static fn (string $key): bool => in_array($key, self::DISCLOSURE, true)
            ? (bool) ($raw[$key] ?? false)
            : (bool) ($raw[$key] ?? true);

        return new self(
            attribution: $flag('attribution'),
            platform_comparison: $flag('platform_comparison'),
            objective_breakdown: $flag('objective_breakdown'),
            creatives: $flag('creatives'),
            budget: $flag('budget'),
            funnel_store: $flag('funnel_store'),
            previous_comparison: $flag('previous_comparison'),
        );
    }

    /** Every DISCLOSURE off and every display section on — what a link with no `sections` key means. */
    public static function closed(): self
    {
        return self::fromArray([]);
    }

    /** @return array<string,bool> */
    public function toArray(): array
    {
        return [
            'attribution' => $this->attribution,
            'platform_comparison' => $this->platform_comparison,
            'objective_breakdown' => $this->objective_breakdown,
            'creatives' => $this->creatives,
            'budget' => $this->budget,
            'funnel_store' => $this->funnel_store,
            'previous_comparison' => $this->previous_comparison,
        ];
    }
}
