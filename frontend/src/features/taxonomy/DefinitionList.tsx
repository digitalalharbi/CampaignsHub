import { useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { TAX_COPY, definitionLabel, fieldTypeLabel, scopeLabel } from './taxonomyCopy'
import type { TaxonomyDefinition } from './taxonomyApi'

/** Left rail: searchable list of taxonomy definitions grouped by module. */
export function DefinitionList({
  definitions,
  selectedKey,
  onSelect,
}: {
  definitions: TaxonomyDefinition[]
  selectedKey: string | null
  onSelect: (key: string) => void
}) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const [query, setQuery] = useState('')

  const groups = useMemo(() => {
    const q = query.trim().toLowerCase()
    const filtered = definitions.filter((d) => {
      if (!q) return true
      return [d.label_ar, d.label_en, d.key, d.module].some((s) => (s ?? '').toLowerCase().includes(q))
    })
    const byModule = new Map<string, TaxonomyDefinition[]>()
    for (const d of filtered) {
      const list = byModule.get(d.module) ?? []
      list.push(d)
      byModule.set(d.module, list)
    }
    return [...byModule.entries()]
      .map(([module, items]) => ({
        module,
        items: items.sort((a, b) => a.sort_order - b.sort_order),
      }))
      .sort((a, b) => a.module.localeCompare(b.module))
  }, [definitions, query])

  return (
    <div className="flex flex-col gap-3">
      <div className="relative">
        <Search size={15} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={c.searchDefinitions}
          aria-label={c.searchDefinitions}
          className="w-full rounded-xl border border-border bg-surface py-2.5 ps-9 pe-3 text-sm text-text-primary outline-none placeholder:text-text-muted focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15"
        />
      </div>

      {groups.length === 0 ? (
        <EmptyState title={c.noDefinitions} description={c.noDefinitionsHint} />
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map(({ module, items }) => (
            <div key={module} className="flex flex-col gap-1.5">
              <h3 className="px-1 text-[11px] font-bold uppercase tracking-wide text-text-tertiary">{module}</h3>
              <ul className="flex flex-col gap-1">
                {items.map((d) => {
                  const active = d.key === selectedKey
                  return (
                    <li key={d.key}>
                      <button
                        type="button"
                        onClick={() => onSelect(d.key)}
                        aria-current={active ? 'true' : undefined}
                        className={`flex w-full flex-col gap-1 rounded-xl border px-3 py-2.5 text-start transition-colors ${
                          active
                            ? 'border-brand-500 bg-brand-primary-soft'
                            : 'border-border bg-surface hover:bg-surface-hover'
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <span className={`truncate text-sm font-semibold ${active ? 'text-brand-700' : 'text-text-primary'}`}>
                            {definitionLabel(d, locale)}
                          </span>
                          {d.is_system && <Badge tone="neutral">{c.system}</Badge>}
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-text-tertiary">
                          <span className="rounded-md bg-surface-hover px-1.5 py-0.5">{scopeLabel(d.scope, c)}</span>
                          <span className="rounded-md bg-surface-hover px-1.5 py-0.5">{fieldTypeLabel(d.field_type, c)}</span>
                          {!d.is_active && <span className="text-warning">{c.inactive}</span>}
                        </div>
                      </button>
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
