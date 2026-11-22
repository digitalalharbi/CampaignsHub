import type { LucideIcon } from 'lucide-react'

/**
 * CONTENT-LIBRARY-SNAP-001 — the library's tabs drawn the way the platforms' own creative libraries draw them: an icon and a
 * word, a line under the open one, nothing boxed. Three tabs, always the same three.
 */
export function LibraryTabs<K extends string>({ items, active, onChange }: { items: Array<{ key: K; label: string; icon: LucideIcon }>; active: K; onChange: (k: K) => void }) {
  return (
    <div role="tablist" data-testid="library-tabs" className="flex gap-6 overflow-x-auto border-b border-border">
      {items.map(({ key, label, icon: Icon }) => {
        const on = key === active

        return (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={on}
            data-testid={`library-tab-${key}`}
            onClick={() => onChange(key)}
            className={`-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-1 pb-3 pt-1 text-base font-semibold transition-colors ${on ? 'border-text-primary text-text-primary' : 'border-transparent text-text-secondary hover:text-text-primary'}`}
          >
            <Icon size={18} aria-hidden />
            {label}
          </button>
        )
      })}
    </div>
  )
}
