<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Services\CardPopupParity;
use PHPUnit\Framework\TestCase;

/**
 * OWNER CONTENT P0 — the server-side mirror is held to the browser's own rules.
 *
 * #505 fixed the two surfaces and guarded them in a browser. Production cannot be read in a browser
 * by this project's diagnostics — the page needs an authenticated session — so the same question is
 * asked of the payload on the server, and this is what stops that answer being a different question.
 *
 * Each case below states the frontend rule it mirrors. A mirror nobody checks drifts, and a drifted
 * diagnostic reports defects that are its own.
 */
final class CardPopupParityTest extends TestCase
{
    private CardPopupParity $parity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parity = new CardPopupParity;
    }

    /** The owner's shape: a sales collection with a hero, no tiles, and a full set of figures. */
    private function row(array $metrics = [], array $preview = [], array $headline = []): array
    {
        return [
            'headline_metrics' => $headline === [] ? ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov'] : $headline,
            'metrics' => array_merge([
                'spend' => 400.0, 'revenue' => 3000.0, 'orders' => 60.0, 'conversions' => 60.0,
                'impressions' => 90000.0, 'clicks' => 1800.0, 'ctr' => 0.02, 'cpc' => 0.22, 'cpm' => 4.44,
                'cpa' => 6.67, 'roas' => 7.5, 'conversion_rate' => 0.0333, 'aov' => 50.0,
                'reported' => ['spend' => true, 'revenue' => true, 'impressions' => true, 'clicks' => true, 'orders' => true],
            ], $metrics),
            'preview' => array_merge([
                'kind' => 'collection', 'state' => 'available',
                'image_url' => 'https://cdn.test/hero.jpg', 'thumbnail_url' => null, 'video_url' => null,
                'cards' => null, 'cards_reported' => false, 'cards_withheld' => 0,
            ], $preview),
        ];
    }

    /**
     * The card carries the objective's own verdict FIRST and then the universal figures it answers —
     * `canonicalFigureKeys()`: the server's list as given, then the additions.
     */
    public function test_the_card_leads_with_the_objective_and_then_adds_every_universal_figure_the_row_answers(): void
    {
        $card = $this->parity->card($this->row());

        $this->assertSame(
            ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm'],
            array_keys($card),
        );
    }

    /** And the two surfaces state the same figures for that row — the whole point of #505. */
    public function test_the_card_and_the_popup_state_the_same_figures(): void
    {
        $row = $this->row();

        $this->assertSame([], $this->parity->differences(
            $this->parity->card($row),
            $this->parity->popup($row),
            $this->parity->preview($row),
        ));
    }

    /**
     * The pre-#505 card is exactly what the verdict must NOT call a match.
     *
     * A guard that cannot express the defect proves nothing, so the defect is fed to it: the capped,
     * sliced list — Spend and three figures — against the panel the owner actually saw.
     */
    public function test_the_verdict_names_every_figure_the_popup_states_and_the_card_does_not(): void
    {
        $row = $this->row();
        $capped = array_slice($this->parity->card($row), 0, 4, preserve_keys: true);

        $differences = $this->parity->differences($capped, $this->parity->popup($row), $this->parity->preview($row));

        $this->assertSame([
            'the popup states «impressions» and the card does not',
            'the popup states «clicks» and the card does not',
            'the popup states «ctr» and the card does not',
            'the popup states «cpc» and the card does not',
            'the popup states «cpm» and the card does not',
            'the popup states «roas» and the card does not',
            'the popup states «conversion_rate» and the card does not',
            'the popup states «aov» and the card does not',
        ], array_values($differences), 'every figure the old card dropped is named, one line each');
    }

    /**
     * A measured zero is a figure; an unreported one is not; and a withheld amount IS.
     *
     * FX-001 refuses to convert without a rate and preserves the original beside it — «79.61 USD» is
     * what the card and the panel both print, so treating it as absent would make the parity line
     * blind to the state every Snapchat creative on the owner's account is in.
     */
    public function test_a_zero_a_withheld_amount_and_an_unreported_figure_are_three_different_states(): void
    {
        $row = $this->row([
            'spend' => null, 'spend_original' => 79.61, 'spend_withheld_rows' => 11,
            'money_original_currency' => 'USD', 'money_original_currencies' => 1,
            'conversion_rate' => 0.0,
            // No impressions were reported, so there is no denominator for a click-through rate either.
            'impressions' => null, 'ctr' => null, 'cpm' => null,
            'reported' => ['impressions' => false],
        ]);

        $card = $this->parity->card($row);
        $popup = $this->parity->popup($row);

        $this->assertSame('withheld', $card['spend']);
        $this->assertSame('zero', $card['conversion_rate']);

        /*
         * An unreported figure is on neither surface's stated set — the card leaves it out and the
         * panel, whose floor always holds it, prints it as the dash it is. Both are read from here,
         * because «the card dropped a figure» and «neither surface has one» are the two readings this
         * instrument exists to keep apart.
         */
        $this->assertArrayNotHasKey('impressions', $card);
        $this->assertSame('unavailable (the platform does not report it)', $popup['impressions']);
        // A ratio with no denominator is «nothing to divide», which is a different sentence.
        $this->assertSame('unavailable (no denominator)', $popup['ctr']);
        $this->assertSame([], $this->parity->differences($card, $popup, $this->parity->preview($row)));
    }

    /**
     * A collection's hero is drawn and its missing tiles are said separately — `readPreview()`.
     *
     * The owner's second reading was a card with a picture and a creative saying it had no cover, so
     * this is the pairing the diagnostic has to be able to state: hero yes, tiles not fetched.
     */
    public function test_a_collection_with_a_hero_and_no_tiles_draws_the_hero_and_says_the_tiles_are_missing(): void
    {
        $preview = $this->parity->preview($this->row());

        $this->assertSame('collection', $preview['kind']);
        $this->assertSame('available', $preview['state']);
        $this->assertSame('yes', $preview['hero']);
        $this->assertSame('not fetched', $preview['tiles']);
        $this->assertSame('still', $preview['card_draws']);
        $this->assertSame('still', $preview['popup_draws']);
    }

    /**
     * An EXPIRED link keeps its thumbnail in the payload, and neither surface may draw it.
     *
     * This is the rule the library card used to skip by reading `thumbnail_url ?? image_url` off the
     * envelope: a dead asset presented as the live ad. The diagnostic has to see it the way the fixed
     * card does, or it would report a picture where the product correctly shows a sentence.
     */
    public function test_an_expired_preview_draws_its_stated_absence_rather_than_the_thumbnail_it_still_holds(): void
    {
        $preview = $this->parity->preview($this->row([], [
            'state' => 'expired', 'image_url' => null, 'thumbnail_url' => 'https://cdn.test/stale.jpg',
        ]));

        $this->assertSame('no', $preview['hero']);
        $this->assertSame('stated absence (expired)', $preview['card_draws']);
        $this->assertSame('stated absence (expired)', $preview['popup_draws']);
    }

    /** A film with no cover is a film on both surfaces — the card draws it inert, the panel plays it. */
    public function test_a_film_without_a_cover_is_a_film_on_both_surfaces(): void
    {
        $preview = $this->parity->preview($this->row([], [
            'kind' => 'video', 'image_url' => null, 'thumbnail_url' => null, 'video_url' => 'https://cdn.test/a.mp4',
        ]));

        $this->assertSame('film', $preview['card_draws']);
        $this->assertSame('film', $preview['popup_draws']);
        $this->assertSame([], $this->parity->differences([], [], $preview));
    }

    /** A catalog ad is missing nothing: the platform composes it per product at delivery. */
    public function test_a_catalog_ad_is_not_an_absence(): void
    {
        $preview = $this->parity->preview($this->row([], [
            'kind' => 'catalog', 'image_url' => null, 'thumbnail_url' => null,
        ]));

        $this->assertSame('catalog — composed per product, nothing to draw', $preview['card_draws']);
    }
}
