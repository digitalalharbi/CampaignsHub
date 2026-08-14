/**
 * MOBILE-003 — the thumb target a small control needs, without making the control big.
 *
 * ## The problem these solve, and why a bigger button is the wrong answer
 *
 * The measured audit at 375px found the remaining undersized controls were nearly all the same
 * shape: a 17×17 «×» inside a filter chip, the clear control inside a select, the info «i» beside a
 * metric label. Each is deliberately small — a chip is a compact summary of one applied filter, and
 * a chip carrying a 44px button is not a chip any more. Growing them would wrap the filter row onto
 * three lines and re-introduce the horizontal overflow this pass exists to remove.
 *
 * What a thumb needs is a 44px **hit area**, which is not the same thing as a 44px **control**.
 * `TOUCH_TARGET` expands the hit area with a transparent pseudo-element and leaves every pixel that
 * is drawn exactly where it was — so the identity, the colours and the density are untouched, and
 * the thing is reachable with a thumb. Above `sm` the expansion is dropped, because a mouse does not
 * need it and overlapping hit areas on a dense desktop toolbar would be worse than the small target.
 *
 * `TOUCH_CONTROL` is for the other kind: a control that is genuinely operated and genuinely too
 * small (a 36px filter select, a 32px segment). Those take the same mobile-first treatment the
 * `Button` primitive took — 44px on a phone, the previous density restored from `sm` up, so desktop
 * is pixel-identical.
 */

/**
 * A 44px hit area around a small icon control, drawn nowhere.
 *
 * Needs no layout change at the call site beyond what is here: `relative` scopes the pseudo-element
 * and `z-10` keeps it above a sibling that would otherwise swallow the tap. The expansion is
 * `-inset-3` (12px each side), which turns a 17px icon into a 41px target and a 20px one into 44 —
 * the practical floor, given the icon is inside a chip whose own padding adds the rest.
 */
export const TOUCH_TARGET =
  'relative z-10 after:absolute after:-inset-3 after:content-[""] sm:after:hidden'

/** A real control: the touch height on a phone, the previous desktop height from `sm` up. */
export const TOUCH_CONTROL = 'h-11 sm:h-9'
