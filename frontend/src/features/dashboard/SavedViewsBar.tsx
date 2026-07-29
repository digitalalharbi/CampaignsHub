import { Bookmark, Star, Trash2 } from 'lucide-react'
import { useDeleteView, useRenameView, useSaveView, useSavedViews, useSetDefaultView, type SavedView } from './savedViews'

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
  const { data: views = [], isLoading, isError, refetch } = useSavedViews()
  const save = useSaveView()
  const del = useDeleteView()
  const setDefault = useSetDefaultView()
  const rename = useRenameView()

  const onSave = () => {
    const name = window.prompt('اسم العرض المحفوظ')?.trim()
    if (!name) return
    save.mutate({ name, filters: { provider: current.providers, objective: current.objective }, date_range: { days: current.days } })
  }

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="saved-views-bar">
      <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-text-muted"><Bookmark size={14} /> العروض المحفوظة:</span>

      {isLoading && <span className="text-xs text-text-muted">جارٍ التحميل…</span>}
      {isError && (
        <button type="button" onClick={() => refetch()} className="text-xs font-semibold text-danger underline">
          تعذّر التحميل — إعادة المحاولة
        </button>
      )}
      {!isLoading && !isError && views.length === 0 && <span className="text-xs text-text-muted">لا عروض محفوظة بعد.</span>}

      {views.map((v) => (
        <span key={v.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-1 text-xs">
          <button type="button" onClick={() => onApply(v)} className="font-semibold text-text-secondary hover:text-text-primary" title="تطبيق العرض">
            {v.name}
          </button>
          <button
            type="button"
            onClick={() => setDefault.mutate(v.id)}
            title={v.is_default ? 'العرض الافتراضي' : 'تعيين كافتراضي'}
            className={v.is_default ? 'text-warning' : 'text-text-muted hover:text-warning'}
          >
            <Star size={13} fill={v.is_default ? 'currentColor' : 'none'} />
          </button>
          <button
            type="button"
            onClick={() => {
              const name = window.prompt('إعادة تسمية العرض', v.name)?.trim()
              if (name && name !== v.name) rename.mutate({ id: v.id, name })
            }}
            title="إعادة تسمية"
            className="text-text-muted hover:text-text-primary"
          >
            ✎
          </button>
          <button type="button" onClick={() => del.mutate(v.id)} title="حذف" className="text-text-muted hover:text-danger">
            <Trash2 size={13} />
          </button>
        </span>
      ))}

      <button
        type="button"
        onClick={onSave}
        disabled={save.isPending}
        className="inline-flex items-center gap-1.5 rounded-full border border-primary bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/15 disabled:opacity-60"
      >
        {save.isPending ? 'جارٍ الحفظ…' : 'حفظ العرض الحالي'}
      </button>
    </div>
  )
}
