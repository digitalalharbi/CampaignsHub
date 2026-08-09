import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

type Size = 'sm' | 'md' | 'lg' | 'xl'

const sizes: Record<Size, string> = {
  sm: 'max-w-[420px]',
  md: 'max-w-[560px]',
  lg: 'max-w-[760px]',
  xl: 'max-w-[1000px]',
}

/** Accessible modal: Escape to close, click-outside to close, focus trapped inside. */
export function Modal({
  open,
  onClose,
  title,
  size = 'md',
  children,
  footer,
}: {
  open: boolean
  onClose: () => void
  title?: string
  size?: Size
  children: ReactNode
  footer?: ReactNode
}) {
  const panelRef = useRef<HTMLDivElement>(null)

  /*
   * `onClose` is held in a ref so the effect below does not depend on its identity.
   *
   * Every caller passes an inline arrow — `onClose={() => setOpen(false)}` — which is a new function
   * on each render of the parent. With `onClose` in the dependency list the effect therefore re-ran
   * on EVERY re-render, and re-ran the focus call with it: any query settling behind an open modal
   * pulled focus out of whatever the person was typing into. Keystrokes landing in that instant went
   * nowhere, and the field they had just filled looked as though it had emptied itself.
   *
   * A ref keeps the handler current without making it a reason to re-run.
   */
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onCloseRef.current()
      if (e.key === 'Tab') trapFocus(e, panelRef.current)
    }
    document.addEventListener('keydown', onKey)

    /*
     * The field the modal's author designated, and only then the first focusable thing.
     *
     * A single comma-separated selector returns the first match in DOCUMENT order, so the close
     * button in the title row always won and `data-autofocus` never did anything — every modal
     * opened with focus on "close" no matter which field it was asking for.
     */
    const panel = panelRef.current
    const target = panel?.querySelector<HTMLElement>('[data-autofocus]')
      ?? panel?.querySelector<HTMLElement>('button,input,textarea,select')
    target?.focus()

    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      {/*
        The body scrolls; the title and the actions do not — CLICK-STABLE-001.

        The whole panel used to be one scrolling box, so its height followed its contents and the
        actions sat wherever the content had reached. In a modal whose body fills in from a query
        that means the buttons MOVE after the modal has been shown: the report builder's scope
        picker stands in at 160px, settles far taller, and then grows again when its templates
        arrive, and «إنشاء وتوليد» travelled down the screen twice.

        A press and its release must reach the same element for a click to exist, so a button that
        moves in between is a click that never happens — no error, no request, and a three-minute
        wait for a response nobody asked for. That is exactly how `report-pdf-download.spec.ts` died
        on firefox: `waitForResponse` on `POST /reports` timed out because no POST was ever sent.

        Pinning the actions also fixes the ordinary version of the same problem, which is that in a
        tall modal you had to scroll to find the button that finishes the job.
      */}
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`flex max-h-[88vh] w-full flex-col rounded-[16px] border border-border bg-surface shadow-[var(--shadow-large)] ${sizes[size]}`}
      >
        {title && (
          <div className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3">
            <h3 className="font-[var(--font-heading)] text-base font-bold text-text-primary">{title}</h3>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              className="rounded-[9px] p-1.5 text-text-muted hover:bg-surface-secondary"
            >
              <X size={17} />
            </button>
          </div>
        )}
        <div className="min-h-0 flex-1 overflow-y-auto p-5 text-sm text-text-secondary">{children}</div>
        {footer && (
          <div className="flex shrink-0 justify-end gap-2 border-t border-border px-5 py-3">{footer}</div>
        )}
      </div>
    </div>
  )
}

function trapFocus(e: KeyboardEvent, container: HTMLElement | null) {
  if (!container) return
  const focusables = container.querySelectorAll<HTMLElement>(
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
  )
  if (focusables.length === 0) return
  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}
