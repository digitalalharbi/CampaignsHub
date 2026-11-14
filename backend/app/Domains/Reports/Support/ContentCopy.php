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
     * SHARE-COPY-LEAK-001 — a preview envelope obeys the same four switches as the row it belongs to.
     *
     * `CreativePresenter::preview()` gives a text-only creative (a ChatGPT chat card, an X post, a Google
     * search ad) its own words in `text` so the card can draw them where no picture exists. Those words ARE
     * the headline and the ad copy: a link with both switched off stripped them from the row and from every
     * carousel card and still published them in `preview.text` — on the live payload, the content endpoints
     * and the summary. The keys are removed, not blanked, and an envelope left with no words carries
     * `text: null`, the presenter's own value for «no words», so nothing renders an empty text card.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public static function preview(array $preview, CreativeVisibility $visibility): array
    {
        if (is_array($preview['text'] ?? null)) {
            $text = $preview['text'];
            if (! $visibility->adCopy) {
                unset($text['body']);
            }
            if (! $visibility->headline) {
                unset($text['headline']);
            }
            $preview['text'] = array_filter($text, static fn ($v): bool => $v !== null && $v !== '') === [] ? null : $text;
        }

        if (is_array($preview['cards'] ?? null)) {
            $preview['cards'] = array_map(
                static fn ($card) => is_array($card) ? self::card($card, $visibility) : $card,
                $preview['cards'],
            );
        }

        return $preview;
    }

    /**
     * Master task 10 (REPORT-OPENABLE-OPTIONS-001) — «ما يمكن فتحه» on the report's own content lists, as on the creative endpoints.
     *
     * `SharedCreativeView` honoured `video`, `image_zoom` and `download` on the content endpoints only; the gallery in the link's own payload
     * (ranked ads, groups, roster) still carried every film, so «تشغيل الفيديو» off left the video playing there. Applied once the operator has
     * configured the section (`creatives` on): a link created before the panel existed keeps the gallery it has always shown — changing what
     * an already-shared client link draws is the Owner's decision, recorded in the ledger. The COVER is never touched: a hidden film still
     * shows its poster, which is «المعروض يظهر بغلافه».
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function media(array $row, CreativeVisibility $visibility): array
    {
        if (! $visibility->creatives || ! is_array($row['preview'] ?? null)) {
            return $row;
        }

        if (! $visibility->video) {
            $row['preview']['video_url'] = null;
            if (is_array($row['preview']['cards'] ?? null)) {
                $row['preview']['cards'] = array_map(static function ($card) {
                    if (is_array($card)) {
                        $card['video_url'] = null;
                    }

                    return $card;
                }, $row['preview']['cards']);
            }
        }
        $row['preview']['can_zoom'] = $visibility->imageZoom;
        $row['preview']['can_download'] = $visibility->download;

        return $row;
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
            $row = self::media($row, $visibility);

            if (isset($row['preview']) && is_array($row['preview'])) {
                $row['preview'] = self::preview($row['preview'], $visibility);
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
