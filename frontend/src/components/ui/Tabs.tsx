import type { ReactNode } from 'react'

export interface TabItem {
  key: string
  label: string
}

/** Segmented tabs. Horizontally scrollable on small screens. */
export function Tabs({
  items,
  active,
  onChange,
}: {
  items: TabItem[]
  active: string
  onChange: (key: string) => void
}) {
  return (
    <div
      role="tablist"
      className="flex gap-1 overflow-x-auto rounded-[13px] border border-border bg-surface-secondary p-1"
    >
      {items.map((it) => {
        const selected = it.key === active
        return (
          <button
            key={it.key}
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(it.key)}
            className={`whitespace-nowrap rounded-[10px] px-3.5 py-1.5 text-sm font-semibold transition-colors ${
              selected
                ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]'
                : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            {it.label}
          </button>
        )
      })}
    </div>
  )
}

export function TabPanel({ children }: { children: ReactNode }) {
  return (
    <div role="tabpanel" className="pt-4">
      {children}
    </div>
  )
}
