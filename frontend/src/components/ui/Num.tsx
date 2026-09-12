import type { ReactNode } from 'react'

/**
 * KPI-ALIGNMENT-002 — a number reads in Latin order WITHOUT dragging its block to the other side.
 *
 * ## The defect this replaces, and why the first fix was wrong
 *
 * «56.3K SAR» inside an Arabic paragraph needs Latin reading order, or the currency jumps to the
 * wrong end of the amount. The product solved that by putting `dir="ltr"` on the element holding the
 * figure — and `dir` does two things, not one. It sets reading order, and it re-bases every logical
 * property on that element: `text-align: start` inside a `dir="ltr"` box means LEFT, whatever the
 * page direction is.
 *
 * So on an Arabic page the label sat at the right edge of the card and its own figure sat at the
 * left, with the width of the card between them. KPI-ALIGNMENT-001 «fixed» this by ADDING
 * `text-start` to every such element — twenty-four of them — which is the same left-alignment
 * written more explicitly. The source guard went green and the screen did not change, which is what
 * the owner saw and what a source-shaped guard can never catch.
 *
 * ## The rule
 *
 * Reading order is an INLINE concern and alignment is a BLOCK one. So the block keeps the page's
 * direction and its `text-start` resolves to the right edge in Arabic, and only the run of digits is
 * isolated — which is precisely what `<bdi>` is for: it opens a bidi isolate, so the number cannot
 * reorder the text around it and the text around it cannot reorder the number.
 *
 * `dir="ltr"` explicitly rather than `<bdi>`'s default `auto`: auto reads the first STRONG character,
 * and «٪12» or a figure that begins with a currency symbol has none, so auto would inherit RTL and
 * put the sign on the wrong end. The whole point is that this does not depend on the value.
 *
 * ## Where it does NOT belong
 *
 * A whole table cell, a card, a form field. Those are blocks, and a block that needs its content in
 * Latin order needs `text-start` plus this around the value — not `dir` on itself.
 */
export function Num({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <bdi dir="ltr" className={className}>
      {children}
    </bdi>
  )
}
