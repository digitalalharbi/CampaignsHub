<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

/**
 * OWNER CONTENT P0 — what the CARD and the POPUP each resolve to, from one payload, on the server.
 *
 * ## Why this exists
 *
 * #505 closed the owner's reading — the card showing Spend and three figures while the popup one
 * click away showed five more, and a card drawing a picture whose creative said it had no cover.
 * Its guards are a browser test and a three-browser gate, and neither can be pointed at Production:
 * seeing the deployed page requires an authenticated session, and the figures live on a connected ad
 * account. So the same question is asked here, of the same payload, where a read-only diagnostic can
 * reach it.
 *
 * ## What it is, precisely
 *
 * A MIRROR of two frontend modules, and nothing else:
 *
 *   - `canonicalFigures.ts` — the card's list: the server's objective-aware `headline_metrics` as
 *     given, then the universal figures this row answers, then revenue and return where reported;
 *   - `creativeDialogFigures.ts` — the panel's list: its own floor of six, stated even where the
 *     platform sent nothing, then the same canonical tail;
 *   - `adPreview.ts` — one preview decision, which both surfaces read.
 *
 * A mirror can drift from the thing it mirrors, and a diagnostic that drifts invents defects that are
 * its own. `CardPopupParityTest` walks the shapes and holds each rule to the frontend's, which is the
 * same bargain `ProbeInsightsCommand::cardStill()` makes with `adPreview.ts`.
 *
 * ## What it prints
 *
 * Metric KEYS and value STATES, the preview DECISION, and one verdict. Never a value, a name, an
 * account, a url or a link: this output is read in a workflow log, which is not the ad account's
 * audience.
 */
final class CardPopupParity
{
    /** The figures true of every campaign — `UNIVERSAL_FIGURES` in `canonicalFigures.ts`. */
    private const UNIVERSAL = ['impressions', 'clicks', 'ctr', 'cpc', 'cpm'];

    /**
     * The panel's floor — the six tiles `creativeDialogFigures()` always builds.
     *
     * It states them even where the platform sent nothing, which is right for a surface opened to
     * study one creative; a dash there is «we cannot say», not a figure, so parity is judged on the
     * figures a surface actually STATES.
     */
    private const POPUP_FLOOR = ['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm'];

    /** Read through the money contract rather than off the column — FX-001 withholds by design. */
    private const MONEY = ['spend', 'revenue'];

    /** A ratio nobody sent: absent means «there was nothing to divide», not «not reported». */
    private const DERIVED = [
        'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'aov', 'cost_per_view',
        'view_rate', 'completion_rate', 'hook_rate', 'cost_per_lpv', 'engagement_rate', 'cpe', 'cpl', 'cpi',
    ];

    /** The states that count as «this surface states this figure». */
    private const STATED = ['reported', 'zero', 'withheld'];

    /**
     * The card's figures, in the order it draws them — `canonicalFigureKeys()`.
     *
     * @param  array<string, mixed>  $row  one row of the library payload
     * @return array<string, string> key => state
     */
    public function card(array $row): array
    {
        $figures = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        /** @var list<string> $headline */
        $headline = array_values(array_unique(array_map(
            static fn (mixed $k): string => (string) $k,
            is_array($row['headline_metrics'] ?? null) ? $row['headline_metrics'] : [],
        )));

        $keys = $headline;

        foreach ([...self::UNIVERSAL, 'revenue', 'roas'] as $key) {
            if (! in_array($key, $keys, true) && in_array($this->state($figures, $key), self::STATED, true)) {
                $keys[] = $key;
            }
        }

        return $this->states($figures, $keys);
    }

    /**
     * The popup's figures — its floor of six, then revenue and return where stated, then the tail.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string> key => state
     */
    public function popup(array $row): array
    {
        $figures = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $keys = self::POPUP_FLOOR;

        foreach (['revenue', 'roas'] as $key) {
            if (in_array($this->state($figures, $key), self::STATED, true)) {
                $keys[] = $key;
            }
        }

        foreach (array_keys($this->card($row)) as $key) {
            /*
             * The panel prints a figure it holds a NUMBER for; a money key goes through the contract.
             * A key the card carries as «not provided» is not a figure the panel drops — neither
             * surface is stating it — which is why parity is judged on the stated set.
             */
            if (! in_array($key, $keys, true) && in_array($this->state($figures, $key), self::STATED, true)) {
                $keys[] = $key;
            }
        }

        return $this->states($figures, $keys);
    }

    /**
     * The preview decision both surfaces read — `readPreview()` and `posterSource()`.
     *
     * `draws` is what the surface puts on the page: the hero or poster it draws, the film it mounts,
     * or the written absence. The card and the panel differ in ONE way by design — a video with no
     * cover is a poster-less film, and the card draws the film inert while the panel mounts a player
     * — so both are reported as `film` and the difference is not a divergence.
     *
     * @param  array<string, mixed>  $row
     * @return array{kind: string, state: string, hero: string, tiles: string, card_draws: string, popup_draws: string}
     */
    public function preview(array $row): array
    {
        $preview = is_array($row['preview'] ?? null) ? $row['preview'] : [];

        $kind = (string) ($preview['kind'] ?? 'unknown');
        $state = (string) ($preview['state'] ?? 'unknown');

        $url = static fn (string $key): bool => is_string($preview[$key] ?? null) && $preview[$key] !== '';

        $available = $state === 'available';
        $still = match (true) {
            ! $available, $kind === 'catalog' => false,
            $kind === 'collection' => $url('image_url') || $url('thumbnail_url'),
            $kind === 'video' && $url('video_url') => $url('thumbnail_url') || $url('image_url'),
            default => $url('image_url') || $url('thumbnail_url'),
        };
        $film = $available && $kind === 'video' && $url('video_url');

        $draws = match (true) {
            $still => 'still',
            $film => 'film',
            $available && $kind === 'catalog' => 'catalog — composed per product, nothing to draw',
            default => 'stated absence ('.$state.')',
        };

        $cards = $preview['cards'] ?? null;
        $withheld = (int) ($preview['cards_withheld'] ?? 0);

        return [
            'kind' => $kind,
            'state' => $state,
            'hero' => $still ? 'yes' : 'no',
            'tiles' => match (true) {
                is_array($cards) && $cards !== [] => 'fetched ('.count($cards).')',
                is_array($cards) => 'the platform sent an empty breakdown',
                $withheld > 0 => 'withheld ('.$withheld.')',
                (bool) ($preview['cards_reported'] ?? false) => 'reported, none held',
                default => 'not fetched',
            },
            'card_draws' => $draws,
            'popup_draws' => $draws,
        ];
    }

    /**
     * MATCH, or every difference named.
     *
     * Judged on the figures a surface STATES — a dash is «we cannot say» on both sides and is not a
     * figure either is claiming to hold — and on the preview decision, which is one decision by
     * construction and is compared anyway, because that is what a guard is for.
     *
     * @param  array<string, string>  $card
     * @param  array<string, string>  $popup
     * @param  array{card_draws: string, popup_draws: string, kind: string, state: string}  $preview
     * @return list<string> empty when the two agree
     */
    public function differences(array $card, array $popup, array $preview): array
    {
        $stated = static fn (array $figures): array => array_keys(array_filter(
            $figures,
            static fn (string $state): bool => in_array($state, self::STATED, true),
        ));

        $cardKeys = $stated($card);
        $popupKeys = $stated($popup);

        $out = [];

        foreach (array_diff($popupKeys, $cardKeys) as $key) {
            $out[] = 'the popup states «'.$key.'» and the card does not';
        }

        foreach (array_diff($cardKeys, $popupKeys) as $key) {
            $out[] = 'the card states «'.$key.'» and the popup does not';
        }

        if ($preview['card_draws'] !== $preview['popup_draws']) {
            $out[] = 'the card draws '.$preview['card_draws'].' and the popup draws '.$preview['popup_draws'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function states(array $figures, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $this->state($figures, $key);
        }

        return $out;
    }

    /**
     * What this row holds for one metric — the three answers a surface can draw, and why it cannot.
     *
     * A measured zero is a FACT about the campaign and is said as itself; `spend` withheld by FX-001
     * is a figure too, because the original amount and its currency are preserved beside it and the
     * money reader states them. Everything else names why there is nothing to state.
     *
     * @param  array<string, mixed>  $figures
     */
    private function state(array $figures, string $key): string
    {
        $value = $figures[$key] ?? null;

        if (is_numeric($value)) {
            return (float) $value === 0.0 ? 'zero' : 'reported';
        }

        if (in_array($key, self::MONEY, true)) {
            $rows = (int) ($figures[$key.'_withheld_rows'] ?? 0);
            $original = $figures[$key.'_original'] ?? null;
            $currency = $figures['money_original_currency'] ?? null;

            if ($rows > 0 && is_numeric($original) && (float) $original > 0.0
                && (int) ($figures['money_original_currencies'] ?? 0) === 1
                && is_string($currency) && trim($currency) !== '') {
                return 'withheld';
            }
        }

        $reported = is_array($figures['reported'] ?? null) ? $figures['reported'] : [];

        return match (true) {
            in_array($key, self::DERIVED, true) => 'unavailable (no denominator)',
            ($reported[$key] ?? null) === false => 'unavailable (the platform does not report it)',
            default => 'unavailable (absent)',
        };
    }
}
