import { useEffect, useRef, type ReactNode } from 'react'
import { CloseButton } from '@/components/ui/CloseButton'
import { useFocusTrap } from '@/lib/useFocusTrap'

/**
 * NOV-FIT-001 — the rest of a row, in a layer, so the row itself can stay one line.
 *
 * A side drawer from the END edge on a desktop and a bottom sheet on a phone — the same pair of shapes the
 * product's dialogs use. It is a modal layer: focus moves in and is trapped, Escape and the backdrop close it,
 * the page behind does not scroll, and it clears the notch and the home indicator. Its own body scrolls when the
 * details are long, which is a layer scrolling, not the page.
 */
export function DetailPanel({
  open, onClose, title, children, footer, closeLabel, testid, variant = 'side',
}: {
  open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; closeLabel: string; testid?: string
  /** CAMPAIGN-MODAL-001 — `center`: from tablet width up, a window in the middle of the page (up to 80% of it), not a side drawer. */
  variant?: 'side' | 'center'
}) {
  // POPUP-CENTER-001 — Owner 2026-11-20: every window opens in the middle of the page. `side` stays as a narrower centred window.
  const wide = variant === 'center'
  const panel = useRef<HTMLDivElement>(null)
  useFocusTrap(panel, open)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = previous }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[60] md:flex md:items-center md:justify-center md:p-6" data-testid={testid}>
      <div className="absolute inset-0 bg-black/40 motion-safe:animate-[fadeIn_var(--motion-panel)_var(--motion-ease)_both]" onClick={onClose} data-testid={testid ? `${testid}-backdrop` : undefined} />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        className={`absolute inset-x-0 bottom-0 flex max-h-[88dvh] flex-col rounded-t-[20px] border border-border bg-surface shadow-[var(--shadow-large)] md:relative md:inset-auto md:bottom-auto md:rounded-2xl ${wide ? 'md:max-h-[80dvh] md:w-[80vw] md:max-w-6xl' : 'md:max-h-[86dvh] md:w-[min(640px,92vw)]'}`}
      >
        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-4 py-2.5">
          <h2 className="min-w-0 truncate font-heading text-base font-extrabold text-text-primary">{title}</h2>
          <CloseButton onClose={onClose} label={closeLabel} autoFocus />
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-4 text-sm text-text-secondary">{children}</div>
        {footer && <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-border px-4 py-3">{footer}</div>}
      </div>
    </div>
  )
}
