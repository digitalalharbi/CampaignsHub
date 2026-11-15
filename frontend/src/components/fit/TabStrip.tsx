import { useRef, type KeyboardEvent } from 'react'
import type { LucideIcon } from 'lucide-react'

export interface TabStripItem {
  key: string
  label: string
  icon?: LucideIcon
  /** A count beside the label — a chip, so the number is read before the word. */
  count?: number | string
}

/**
 * NOV-FIT-001 — tabs are how a fitted page says «and also this» without a second screen of content.
 *
 * Roving tabindex and arrow keys (Home and End too), so the strip is one stop in the Tab order and a keyboard user
 * moves along it the way a pointer does. It scrolls inside its own box on a phone rather than wrapping, and a
 * 44px row on a touch screen comes from the global coarse-pointer floor, not from padding here.
 */
export function TabStrip({
  items, active, onChange, label, testid,
}: { items: TabStripItem[]; active: string; onChange: (key: string) => void; label: string; testid?: string }) {
  const refs = useRef<Array<HTMLButtonElement | null>>([])

  const move = (e: KeyboardEvent, index: number) => {
    const last = items.length - 1
    const dir = document.documentElement.dir === 'rtl' ? -1 : 1
    let next = index
    if (e.key === 'ArrowRight') next = index + dir
    else if (e.key === 'ArrowLeft') next = index - dir
    else if (e.key === 'Home') next = 0
    else if (e.key === 'End') next = last
    else return
    e.preventDefault()
    next = (next + items.length) % items.length
    onChange(items[next].key)
    refs.current[next]?.focus()
  }

  return (
    <div
      role="tablist"
      aria-label={label}
      data-testid={testid}
      className="flex max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-surface-secondary p-1"
    >
      {items.map((item, i) => {
        const selected = item.key === active

        return (
          <button
            key={item.key}
            ref={(el) => { refs.current[i] = el }}
            type="button"
            role="tab"
            aria-selected={selected}
            tabIndex={selected ? 0 : -1}
            data-testid={testid ? `${testid}-${item.key}` : undefined}
            onClick={() => onChange(item.key)}
            onKeyDown={(e) => move(e, i)}
            className={`flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-sm font-semibold transition-colors ${
              selected ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            {item.icon && <item.icon size={15} aria-hidden />}
            {item.label}
            {item.count !== undefined && (
              <span className={`tnum rounded-full px-1.5 text-xs font-bold ${selected ? 'bg-white/20' : 'bg-surface text-text-muted'}`}>{item.count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}
