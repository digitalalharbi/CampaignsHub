import { useEffect, useRef, useState, type ReactNode } from 'react'
import { CloseButton } from '@/components/ui/CloseButton'

/**
 * A panel that slides in from the reading-end edge over the board, so a project opens WITHOUT moving the page.
 * Escape and the scrim close it; focus goes in on open and returns to the row on close. Opacity only, no slide:
 * a panel whose buttons move while it opens is a click that misses.
 */
export function SlideOver({ open, onClose, title, closeLabel, children, testid, center: _center = true }: {
  open: boolean
  onClose: () => void
  title: ReactNode
  closeLabel: string
  children: ReactNode
  testid?: string
  /** BUDGET-POPUP-001 — a window in the middle of the page from tablet width up (a bottom sheet on a phone), closable by ✕, Escape and the scrim. */
  center?: boolean
}) {
  const ref = useRef<HTMLDivElement>(null)
  // The page's own header (marked `data-slideover-avoid`) stays clickable: the panel and its scrim start BELOW it,
  // so «أضف ميزانية» is never under the panel.
  const [top, setTop] = useState(0)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    const opener = document.activeElement as HTMLElement | null
    const avoid = document.querySelector('[data-slideover-avoid]')
    setTop(avoid ? Math.max(0, Math.ceil(avoid.getBoundingClientRect().bottom) + 8) : 0)
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCloseRef.current() }
    document.addEventListener('keydown', onKey)
    ref.current?.focus()

    return () => {
      document.removeEventListener('keydown', onKey)
      opener?.focus?.()
    }
  }, [open])

  // POPUP-CENTER-001 — Owner 2026-11-20: every window opens in the middle of the page.
  const center = true
  void _center
  if (!open) return null

  return (
    <div className={center ? 'fixed inset-0 z-[60] flex items-end justify-center md:items-center md:p-6' : 'fixed inset-x-0 bottom-0 z-40 flex justify-end'} style={center ? undefined : { top }} data-testid={testid}>
      <div className={`absolute inset-0 ${center ? 'bg-black/50' : 'bg-black/30'}`} onMouseDown={onClose} aria-hidden />
      <aside
        ref={ref}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        className={center
          ? 'relative flex max-h-[92dvh] w-full min-w-0 flex-col gap-3 overflow-y-auto rounded-t-[20px] border border-border bg-surface p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[var(--shadow-large,0_8px_32px_rgba(0,0,0,.18))] focus:outline-none md:max-h-[88dvh] md:max-w-[820px] md:rounded-[20px]'
          : 'relative flex h-full w-full max-w-[520px] min-w-0 flex-col gap-3 overflow-y-auto border-s border-border bg-surface p-3 shadow-[var(--shadow-large,0_8px_32px_rgba(0,0,0,.18))] focus:outline-none'}
      >
        <div className="flex min-w-0 items-center justify-between gap-2">
          <div className="min-w-0 flex-1">{title}</div>
          <CloseButton onClose={onClose} label={closeLabel} testid="slideover-close" />
        </div>
        {children}
      </aside>
    </div>
  )
}
