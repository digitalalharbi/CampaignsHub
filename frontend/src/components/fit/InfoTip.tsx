import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Info } from 'lucide-react'

/**
 * NOV-FIT-001 — a sentence of explanation, folded into an icon.
 *
 * A fitted page cannot afford a paragraph under every title. The sentence is not removed: it is one press (or
 * hover, or keyboard focus) away, and it is the button's accessible name, so a screen reader reads it where a
 * sighted reader sees the «i». It closes on Escape and on a press outside, and never takes space from the page.
 */
export function InfoTip({ text, label, testid }: { text: ReactNode; label?: string; testid?: string }) {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLSpanElement>(null)
  const id = useId()

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    const onPress = (e: PointerEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onPress)

    return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('pointerdown', onPress) }
  }, [open])

  return (
    <span ref={box} className="relative inline-flex" onPointerEnter={(e) => { if (e.pointerType === 'mouse') setOpen(true) }} onPointerLeave={(e) => { if (e.pointerType === 'mouse') setOpen(false) }}>
      <button
        type="button"
        data-testid={testid}
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        aria-label={label ?? (typeof text === 'string' ? text : undefined)}
        onClick={() => setOpen((v) => !v)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        className="flex h-6 w-6 items-center justify-center rounded-full text-text-muted hover:bg-surface-hover hover:text-text-primary"
      >
        <Info size={15} aria-hidden />
      </button>
      {open && (
        <span
          id={id}
          role="tooltip"
          className="absolute start-0 top-full z-30 mt-1 w-64 max-w-[80vw] rounded-lg border border-border bg-surface-elevated p-2.5 text-start text-xs font-normal leading-snug text-text-secondary shadow-[var(--shadow-medium)]"
        >
          {text}
        </span>
      )}
    </span>
  )
}
