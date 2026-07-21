import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

type Size = 'sm' | 'md' | 'lg'

const sizes: Record<Size, string> = {
  sm: 'max-w-[420px]',
  md: 'max-w-[560px]',
  lg: 'max-w-[760px]',
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

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'Tab') trapFocus(e, panelRef.current)
    }
    document.addEventListener('keydown', onKey)
    // Focus the first focusable element when opening.
    panelRef.current?.querySelector<HTMLElement>('[data-autofocus],button,input,textarea,select')?.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`max-h-[88vh] w-full overflow-y-auto rounded-[16px] border border-border bg-surface p-5 shadow-[var(--shadow-large)] ${sizes[size]}`}
      >
        {title && (
          <div className="mb-3 flex items-center justify-between">
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
        <div className="text-[13px] text-text-secondary">{children}</div>
        {footer && <div className="mt-4 flex justify-end gap-2">{footer}</div>}
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
