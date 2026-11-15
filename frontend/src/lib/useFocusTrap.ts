import { useEffect, type RefObject } from 'react'

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Keeps Tab inside `ref` while `active`, moves focus in on open and gives it back on close.
 *
 * For an overlay that sits above the page — the phone's navigation layer — where Tab walking out into the
 * page behind a backdrop is the keyboard version of tapping through it. Escape is left to the caller, which
 * knows what «close» means for it.
 */
export function useFocusTrap(ref: RefObject<HTMLElement | null>, active: boolean): void {
  useEffect(() => {
    if (!active) return
    const container = ref.current
    if (!container) return

    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const items = () => [...container.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => { const css = getComputedStyle(el); return css.display !== 'none' && css.visibility !== 'hidden' })
    ;(container.querySelector<HTMLElement>('[data-autofocus]') ?? items()[0] ?? container).focus()

    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return
      const list = items()
      if (list.length === 0) {
        e.preventDefault()

        return
      }
      const first = list[0]
      const last = list[list.length - 1]
      const current = document.activeElement
      if (e.shiftKey && (current === first || !container.contains(current))) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && (current === last || !container.contains(current))) {
        e.preventDefault()
        first.focus()
      } else {
        /*
         * Safari does not Tab to links and buttons unless the person turned that on, so the browser's own «next» can
         * be a field in the page behind, or the address bar, whatever this list says is last. Whatever it chose,
         * if it left the layer, bring focus back to the end it should have wrapped to.
         */
        const backwards = e.shiftKey
        window.setTimeout(() => {
          if (!container.contains(document.activeElement)) (backwards ? last : first).focus()
        }, 0)
      }
    }
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('keydown', onKey)
      previous?.focus?.()
    }
  }, [ref, active])
}
