/**
 * UX-KPI-PRESENTATION-001 · TYPOGRAPHY-PRODUCT-POLISH-001 — the sizes a figure is allowed to be.
 *
 * ## Why this is a module and not a set of literals
 *
 * The type scale was consolidated onto whole steps, which stopped new half-pixel sizes appearing.
 * It did nothing about the other half of the problem: every surface that shows a figure picked its
 * own step. `StatCard` and `MetricCard` both settled on `text-2xl` for the value and `text-xs` for
 * the label — the same two decisions, made twice, and the next card to be written makes them a
 * third time. Two components that agree by coincidence disagree the first time one is touched.
 *
 * So the decisions live here, once, and the cards import them.
 *
 * ## What changed, and why these numbers
 *
 * A KPI card is read at a glance from across a desk, and 24px in a 12px label's company is not the
 * jump a reader needs to see WHICH thing is the number. The lead figure steps to 28px on a phone and
 * 32px above `sm`, where there is room for it; a card inside a dense grid steps to 24/26 so a row of
 * eight still fits without either wrapping mid-figure or flattening into one texture.
 *
 * The label goes the other way: 12px semibold was thin enough that «الطلبات» and «تكلفة النتيجة»
 * read as caption text rather than as the name of the figure under them. 13px is one step, and it
 * is the step that makes the pair read as a unit.
 *
 * `PAD` is the card's own rhythm: 16px on a phone, 20px from `sm`, because a desktop card with
 * phone padding is what makes a large screen look like a stretched small one.
 *
 * ## What is NOT here
 *
 * Colour, tone, borders, and anything a single surface legitimately decides for itself. This module
 * is the scale, not a theme: a card that needs a different border for a lead state still says so at
 * its own call site.
 */

/** The figure a surface leads with — a dashboard hero, a single headline number. */
export const METRIC_VALUE = 'tnum text-[28px] font-extrabold leading-none tracking-tight sm:text-[32px]'

/** The figure inside a grid of cards, where eight of them share a row. */
export const METRIC_VALUE_DENSE = 'tnum text-2xl font-extrabold leading-none tracking-tight sm:text-[26px]'

/** The name of the figure. One step above caption text, because it names rather than annotates. */
export const METRIC_LABEL = 'text-[13px] font-semibold leading-tight'

/** The line under a figure: what it is measured against, what is missing, why it is withheld. */
export const METRIC_HINT = 'text-xs leading-snug'

/** A card's padding, phone first. */
export const CARD_PAD = 'p-4 sm:p-5'

/** A card inside a dense grid, where the row's rhythm matters more than each card's air. */
export const CARD_PAD_DENSE = 'p-3.5 sm:p-4'

/** The gap between cards in a KPI row — the same on every surface, which is the point. */
export const CARD_GAP = 'gap-3 sm:gap-4'

/** A page's own title. */
export const PAGE_TITLE = 'text-2xl font-extrabold tracking-tight sm:text-[28px]'
