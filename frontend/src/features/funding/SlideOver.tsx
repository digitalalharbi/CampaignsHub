import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

/**
 * A panel that slides in from the reading-end edge over the board, so a project opens WITHOUT moving the page.
 * Escape and the scrim close it; focus goes in on open and returns to the row on close. Opacity only, no slide:
 * a panel whose buttons move while it opens is a click that misses.
 */
export function SlideOver({ open, onClose, title, closeLabel, children, testid }: {
  open: boolean
  onClose: () => void
  title: ReactNode
  closeLabel: string
  children: ReactNode
  testid?: string
}) {
  const ref = useRef<HTMLDivElement>(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    const opener = document.activeElement as HTMLElement | null
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCloseRef.current() }
    document.addEventListener('keydown', onKey)
    ref.current?.focus()

    return () => {
      document.removeEventListener('keydown', onKey)
      opener?.focus?.()
    }
  }, [open])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-40 flex justify-end" data-testid={testid}>
      <div className="absolute inset-0 bg-black/30" onMouseDown={onClose} aria-hidden />
      <aside
        ref={ref}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        className="relative flex h-full w-full max-w-[520px] min-w-0 flex-col gap-3 overflow-y-auto border-s border-border bg-surface p-3 shadow-[var(--shadow-large,0_8px_32px_rgba(0,0,0,.18))] focus:outline-none"
      >
        <div className="flex min-w-0 items-center justify-between gap-2">
          <div className="min-w-0 flex-1">{title}</div>
          <button type="button" onClick={onClose} aria-label={closeLabel} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover" data-testid="slideover-close">
            <X className="h-4 w-4" aria-hidden />
          </button>
        </div>
        {children}
      </aside>
    </div>
  )
}
