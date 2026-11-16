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
export const METRIC_LABEL = 'text-xs font-semibold leading-tight'

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

/*
 * NOV-FIT-001 — the rhythm of a page that has to fit the screen.
 *
 * A page that never scrolls is a budget: every row of it is paid for in pixels. The budget only balances if the
 * pages spend the same coin, so the spacing and the type of a fitted page are decided here, once, from steps that
 * already exist (no new arbitrary size, which the type-scale ratchet forbids).
 *
 *   gap between blocks        10px   (`gap-2.5`)       inside a card           12px × 8px
 *   page title                20px → 24px from `sm`     a KPI figure            24px → 30px from `sm`
 *   table row                 44px — also the touch floor, so a row is one tap target
 */
/** The gap between the blocks of a fitted page. */
export const FIT_GAP = 'gap-2.5'

/** A fitted page's title — one line, never the 28px hero. */
export const FIT_TITLE = 'text-xl font-extrabold tracking-tight sm:text-2xl'

/** The big number of a compact KPI card. */
export const FIT_KPI_VALUE = 'tnum text-2xl font-extrabold leading-none tracking-tight sm:text-3xl'

/** A compact card's surface and padding. */
export const FIT_CARD = 'rounded-xl border border-border bg-surface'
export const FIT_CARD_PAD = 'px-3 py-2'

/** Pixel budget a table spends, read by `useFitRows` so the row count and the CSS cannot disagree. */
export const FIT_ROW_HEIGHT = 44
export const FIT_HEAD_HEIGHT = 40
export const FIT_PAGER_HEIGHT = 44

/**
 * The viewport a page is locked to its height in. Below it — a phone, a tablet, a short desktop window — the page
 * flows and scrolls vertically like any other, because a locked page on a small screen would cut content off.
 * `index.css` carries the same query; `fitMedia.test.ts` fails if the two drift apart.
 */
/*
 * SCROLL-RESTORE-001 (owner: «النظام لا ينزل للأسفل، جزء من البيانات مفقود بسبب قاعدة عدم النزول») — the lock is OFF.
 * A page pinned to one screen paged its tables to what fitted and clipped what it could not page, so on a laptop a
 * table showed a few rows and the rest read as missing. Every page now flows and scrolls vertically (never sideways);
 * tables draw their full page with the pager below. The machinery stays (a query no screen matches) so a single page
 * can be locked again deliberately, but nothing is pinned by default.
 *
 * Was: '(min-width: 1280px) and (min-height: 640px)'.
 */
export const FIT_LOCK_QUERY = '(min-width: 100000px)'
