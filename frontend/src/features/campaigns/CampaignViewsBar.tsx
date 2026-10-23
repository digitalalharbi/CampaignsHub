import { useState } from 'react'
import { Star, Trash2 } from 'lucide-react'
import { useDeleteView, useSaveView, useSavedViews, useSetDefaultView, type SavedView, type SavedViewFilters } from '@/features/dashboard/savedViews'

/**
 * CAMPAIGNS-TABLE-001 — saved views for the campaigns table: a name over the filters, the columns and the order.
 *
 * The same server store the dashboard's views live in, under the `campaigns` module. A view is applied as one
 * change by the page (one setter), never as a sequence of setters that could land in any order.
 */
export const CAMPAIGN_VIEWS_MODULE = 'campaigns'

/**
 * ADGROUPS-PAGE-001 / ADS-PAGE-001 — the same bar serves the ad-groups and ads pages, each under its own `module`
 * (`entities-ad_set`, `entities-ad`) so a view saved on one page is never offered on another. `testPrefix` keeps the
 * campaigns page's test ids exactly as they were.
 */
export function CampaignViewsBar({ current, days, onApply, ar, module = CAMPAIGN_VIEWS_MODULE, testPrefix = 'campaign' }: {
  current: SavedViewFilters
  days: number
  onApply: (view: SavedView) => void
  ar: boolean
  module?: string
  testPrefix?: string
}) {
  const views = useSavedViews(true, module)
  const save = useSaveView(module)
  const remove = useDeleteView(module)
  const setDefault = useSetDefaultView(module)
  const [naming, setNaming] = useState(false)
  const [name, setName] = useState('')

  const submit = () => {
    const trimmed = name.trim()
    if (trimmed === '') return
    save.mutate({ name: trimmed, filters: current, date_range: { days } }, { onSuccess: () => { setNaming(false); setName('') } })
  }

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid={`${testPrefix}-views`}>
      <span className="text-xs font-bold text-text-secondary">{ar ? 'العروض المحفوظة:' : 'Saved views:'}</span>
      {(views.data ?? []).map((v) => (
        <span key={v.id} className="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-2 py-1 text-xs">
          <button type="button" onClick={() => onApply(v)} className="font-semibold text-text-primary hover:text-brand-600" data-testid={`${testPrefix}-view-${v.id}`}>{v.name}</button>
          <button type="button" onClick={() => setDefault.mutate(v.id)} aria-label={ar ? 'اجعله الافتراضي' : 'Make default'} aria-pressed={v.is_default} className={v.is_default ? 'text-warning' : 'text-text-muted hover:text-warning'}><Star size={12} aria-hidden /></button>
          <button type="button" onClick={() => remove.mutate(v.id)} aria-label={ar ? 'حذف العرض' : 'Delete view'} className="text-text-muted hover:text-danger"><Trash2 size={12} aria-hidden /></button>
        </span>
      ))}
      {naming ? (
        <span className="inline-flex items-center gap-1">
          <input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') submit(); if (e.key === 'Escape') setNaming(false) }}
            placeholder={ar ? 'اسم العرض' : 'View name'}
            className="h-7 rounded-lg border border-border bg-surface px-2 text-xs"
            data-testid={`${testPrefix}-view-name`}
          />
          <button type="button" onClick={submit} disabled={save.isPending} className="rounded-lg bg-brand-600 px-2 py-1 text-xs font-bold text-white" data-testid={`${testPrefix}-view-save`}>{ar ? 'حفظ' : 'Save'}</button>
        </span>
      ) : (
        <button type="button" onClick={() => setNaming(true)} className="rounded-lg border border-dashed border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:border-brand-400" data-testid={`${testPrefix}-view-new`}>
          {ar ? '+ حفظ العرض الحالي' : '+ Save current view'}
        </button>
      )}
    </div>
  )
}
