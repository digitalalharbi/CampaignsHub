<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

/**
 * Which words of a piece of content a client link may publish — one rule for every client surface.
 *
 * `CreativeVisibility` fails closed: a link publishes a card's body, headline, call to action or
 * destination only when its operator switched that on. `SharedCreativeView` applied it; the live
 * payload carried every carousel card's copy inside its preview envelopes whatever the switches said.
 * Two readers of one rule is how that happened, so there is one.
 */
final class ContentCopy
{
    private const FIELDS = [
        'body' => 'adCopy',
        'headline' => 'headline',
        'cta' => 'cta',
        'destination_url' => 'destinationUrl',
    ];

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public static function card(array $card, CreativeVisibility $visibility): array
    {
        foreach (self::FIELDS as $field => $flag) {
            if (! $visibility->{$flag}) {
                unset($card[$field]);
            }
        }

        return $card;
    }

    /**
     * Walk content rows — ranked ads, groups of them at any depth, the roster — and remove the copy
     * each card may not publish, from the row's own fields and from its preview's cards.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int|string, mixed>
     */
    public static function rows(array $rows, CreativeVisibility $visibility): array
    {
        return array_map(static function ($row) use ($visibility) {
            if (! is_array($row)) {
                return $row;
            }

            $row = self::card($row, $visibility);

            if (isset($row['preview']['cards']) && is_array($row['preview']['cards'])) {
                $row['preview']['cards'] = array_map(
                    static fn ($card) => is_array($card) ? self::card($card, $visibility) : $card,
                    $row['preview']['cards'],
                );
            }

            foreach (['ads', 'groups'] as $nested) {
                if (isset($row[$nested]) && is_array($row[$nested])) {
                    $row[$nested] = self::rows($row[$nested], $visibility);
                }
            }

            return $row;
        }, $rows);
    }
}
