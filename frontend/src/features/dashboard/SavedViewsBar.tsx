import { Bookmark, Star, Trash2 } from 'lucide-react'
import { useDeleteView, useRenameView, useSaveView, useSavedViews, useSetDefaultView, type SavedView } from './savedViews'
import { useUi } from '@/stores/ui'

/**
 * DASH-010-E-FE — save / apply / rename / set-default / delete server-persisted dashboard views. The current
 * objective + platform filters + date range are captured on save; applying a view restores them exactly.
 */
export function SavedViewsBar({
  current,
  onApply,
}: {
  current: { objective: string; providers: string[]; days: number }
  onApply: (view: SavedView) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const { data: views = [], isLoading, isError, refetch } = useSavedViews()
  const save = useSaveView()
  const del = useDeleteView()
  const setDefault = useSetDefaultView()
  const rename = useRenameView()

  const onSave = () => {
    const name = window.prompt(ar ? 'اسم العرض المحفوظ' : 'Name this saved view')?.trim()
    if (!name) return
    save.mutate({ name, filters: { provider: current.providers, objective: current.objective }, date_range: { days: current.days } })
  }

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="saved-views-bar">
      <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-muted"><Bookmark size={14} /> {ar ? 'العروض المحفوظة:' : 'Saved views:'}</span>

      {isLoading && <span className="text-sm text-text-muted">{ar ? 'جارٍ التحميل…' : 'Loading…'}</span>}
      {isError && (
        <button type="button" onClick={() => refetch()} className="text-sm font-semibold text-danger underline">
          {ar ? 'تعذّر التحميل — إعادة المحاولة' : 'Could not load — retry'}
        </button>
      )}
      {!isLoading && !isError && views.length === 0 && <span className="text-sm text-text-muted">{ar ? 'لا عروض محفوظة بعد.' : 'No saved views yet.'}</span>}

      {views.map((v) => (
        <span key={v.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-1 text-sm">
          <button type="button" onClick={() => onApply(v)} className="font-semibold text-text-secondary hover:text-text-primary" title={ar ? 'تطبيق العرض' : 'Apply this view'}>
            {v.name}
          </button>
          <button
            type="button"
            onClick={() => setDefault.mutate(v.id)}
            title={v.is_default ? (ar ? 'العرض الافتراضي' : 'Default view') : (ar ? 'تعيين كافتراضي' : 'Make default')}
            className={v.is_default ? 'text-warning' : 'text-text-muted hover:text-warning'}
          >
            <Star size={13} fill={v.is_default ? 'currentColor' : 'none'} />
          </button>
          <button
            type="button"
            onClick={() => {
              const name = window.prompt(ar ? 'إعادة تسمية العرض' : 'Rename this view', v.name)?.trim()
              if (name && name !== v.name) rename.mutate({ id: v.id, name })
            }}
            title={ar ? 'إعادة تسمية' : 'Rename'}
            className="text-text-muted hover:text-text-primary"
          >
            ✎
          </button>
          <button type="button" onClick={() => del.mutate(v.id)} title={ar ? 'حذف' : 'Delete'} className="text-text-muted hover:text-danger">
            <Trash2 size={13} />
          </button>
        </span>
      ))}

      <button
        type="button"
        onClick={onSave}
        disabled={save.isPending}
        className="inline-flex items-center gap-1.5 rounded-full border border-primary bg-primary/10 px-2.5 py-1 text-sm font-semibold text-primary hover:bg-primary/15 disabled:opacity-60"
      >
        {save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ العرض الحالي' : 'Save this view')}
      </button>
    </div>
  )
}
