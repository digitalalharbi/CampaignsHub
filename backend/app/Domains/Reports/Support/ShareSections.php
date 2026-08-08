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
    public const FLAGS = ['attribution'];

    private function __construct(
        public readonly bool $attribution,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            attribution: (bool) ($raw['attribution'] ?? false),
        );
    }

    /** Everything off — what a link with no `sections` key means, and what a bad one falls back to. */
    public static function closed(): self
    {
        return new self(attribution: false);
    }

    /** @return array<string,bool> */
    public function toArray(): array
    {
        return ['attribution' => $this->attribution];
    }
}
