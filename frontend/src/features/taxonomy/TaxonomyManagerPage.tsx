import { useEffect, useMemo, useState } from 'react'
import { Tags } from 'lucide-react'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { TAX_COPY } from './taxonomyCopy'
import { DefinitionList } from './DefinitionList'
import { OptionsPanel } from './OptionsPanel'
import { useTaxonomyDefinitions } from './taxonomyApi'

/**
 * Settings → Taxonomies & Options. Left rail lists classification fields (grouped by module,
 * searchable); the right pane manages the selected field's options — reorder, edit, set-default,
 * add, merge/reassign, deactivate — all permission-aware. Arabic-first, RTL, light/dark.
 */
export function TaxonomyManagerPage() {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const q = useTaxonomyDefinitions()

  const definitions = useMemo(() => q.data ?? [], [q.data])
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

  // Auto-select the first definition once loaded (nothing selected yet).
  useEffect(() => {
    if (!selectedKey && definitions.length > 0) setSelectedKey(definitions[0].key)
  }, [definitions, selectedKey])

  const selected = definitions.find((d) => d.key === selectedKey) ?? null

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-primary-soft text-brand-700">
          <Tags size={20} />
        </span>
        <div>
          <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
      </header>

      {q.isPending ? (
        <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
          <div className="flex flex-col gap-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-14 w-full" />)}</div>
          <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-16 w-full" />)}</div>
        </div>
      ) : q.isError ? (
        <ErrorState title={c.loadError} onRetry={() => q.refetch()} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
          <aside className="lg:sticky lg:top-4 lg:h-fit">
            <DefinitionList definitions={definitions} selectedKey={selectedKey} onSelect={setSelectedKey} />
          </aside>
          <section className="min-w-0 rounded-2xl border border-border bg-surface/40 p-4 md:p-5">
            {selected ? (
              <OptionsPanel key={selected.key} definition={selected} />
            ) : (
              <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border py-16 text-center">
                <Tags size={26} className="text-text-muted" />
                <h4 className="text-sm font-bold text-text-primary">{c.selectDefinition}</h4>
                <p className="max-w-xs text-xs text-text-secondary">{c.selectDefinitionHint}</p>
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
