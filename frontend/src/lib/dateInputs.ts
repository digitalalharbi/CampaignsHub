/**
 * Force every native date/time input to render Gregorian + English in a clear `YYYY-MM-DD` form.
 *
 * Browsers localize `<input type="date">` (and datetime-local/month/week/time) using the element's own
 * `lang`. Under an Arabic (RTL) page these inputs otherwise show Arabic month/day placeholders and a
 * Hijri-leaning picker — the garbled glyphs users see ("طلاسم"). Setting `lang="en-CA"` + `dir="ltr"` on
 * each such input fixes the placeholder, the shown value (YYYY-MM-DD) and the calendar, WITHOUT changing
 * the rest of the UI language. A MutationObserver keeps dynamically-mounted inputs (modals, drawers,
 * filters) covered too, so this applies system-wide to all forms/filters/reports.
 */
const DATE_TYPES = new Set(['date', 'datetime-local', 'month', 'week', 'time'])

function normalize(el: HTMLInputElement): void {
  if (!DATE_TYPES.has(el.type)) return
  if (el.getAttribute('lang') !== 'en-CA') el.setAttribute('lang', 'en-CA')
  if (el.getAttribute('dir') !== 'ltr') el.setAttribute('dir', 'ltr')
}

function scan(root: ParentNode): void {
  root.querySelectorAll?.('input').forEach((el) => normalize(el as HTMLInputElement))
}

let started = false

/** Idempotent; call once after the app mounts. No-op outside the browser. */
export function installDateInputNormalizer(): void {
  if (started || typeof document === 'undefined') return
  started = true

  scan(document)

  const observer = new MutationObserver((records) => {
    for (const r of records) {
      r.addedNodes.forEach((n) => {
        if (n instanceof HTMLInputElement) normalize(n)
        else if (n instanceof Element) scan(n)
      })
    }
  })
  observer.observe(document.documentElement, { childList: true, subtree: true })
}
