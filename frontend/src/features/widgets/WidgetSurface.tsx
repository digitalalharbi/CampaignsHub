import { useEffect, useState } from 'react'
import { ArrowDown, ArrowUp, SlidersHorizontal } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useSaveWidgetLayout, useWidgetLayout, type ResolvedWidget, type WidgetLayoutItem, type WidgetSize, type WidgetSurface as Surface } from './api'
import { WIDGET_COMPONENTS } from './registry'

const SPAN: Record<WidgetSize, string> = { third: 'lg:col-span-2', half: 'lg:col-span-3', full: 'lg:col-span-6' }

const REASON: Record<string, { en: string; ar: string }> = {
  module_off: { en: 'Module is off — switch it on in Settings → Modules', ar: 'الوحدة متوقفة — فعّلها من الإعدادات ← الوحدات' },
  hidden_by_project: { en: 'Hidden for this project', ar: 'مخفي لهذا المشروع' },
  hidden_by_link: { en: 'Hidden on this link', ar: 'مخفي في هذا الرابط' },
}

/**
 * WIDGET-REGISTRY-001 — the configurable widget area of the Dashboard and Analytics.
 *
 * Draws nothing at all for a project where no widget's module is on, so every existing workspace sees exactly the page
 * it had. Where there are widgets, the server's resolution decides which are shown and in what order; the
 * «Customize» panel edits that resolution (show or hide, order, width) and the server validates and stores it.
 */
export function WidgetSurface({ projectId, surface, range }: { projectId: string | null | undefined; surface: Surface; range: { from: string; to: string } }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const layout = useWidgetLayout(projectId, surface)
  const [editing, setEditing] = useState(false)

  const widgets = layout.data?.widgets ?? []
  /*
   * Only a widget that is actually SHOWN brings the area onto the page. A core widget is opt-in (hidden until someone
   * chooses it in Settings → Widgets) and an advanced one appears when its module is switched on, so a project that has
   * chosen nothing and enabled nothing gets exactly the page it had.
   */
  if (!projectId || layout.isError || !widgets.some((w) => w.visible)) return null

  const shown = widgets.filter((w) => w.visible && WIDGET_COMPONENTS[w.key] !== undefined)

  return (
    <section data-testid={`widget-surface-${surface}`} className="mt-6 space-y-3">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg font-bold text-text-primary">{ar ? 'ودجات قابلة للتخصيص' : 'Configurable widgets'}</h2>
        <Button variant="secondary" size="sm" onClick={() => setEditing((e) => !e)} data-testid="widgets-customize">
          <SlidersHorizontal className="size-4" aria-hidden /> {ar ? 'تخصيص' : 'Customize'}
        </Button>
      </div>

      {editing && <LayoutEditor projectId={projectId} surface={surface} widgets={widgets} sizes={layout.data?.sizes ?? ['third', 'half', 'full']} onDone={() => setEditing(false)} />}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-6">
        {shown.map((w) => {
          const Component = WIDGET_COMPONENTS[w.key]
          return (
            <div key={w.key} data-testid={`widget-${w.key}`} className={`min-w-0 ${SPAN[w.size] ?? 'lg:col-span-6'}`}>
              <Component projectId={projectId} range={range} />
            </div>
          )
        })}
      </div>
      {shown.length === 0 && (
        <p data-testid="widgets-all-hidden" className="text-sm text-text-muted">{ar ? 'كل الودجات مخفية.' : 'Every widget is hidden.'}</p>
      )}
    </section>
  )
}

export function LayoutEditor({ projectId, surface, widgets, sizes, onDone }: { projectId: string; surface: Surface; widgets: ResolvedWidget[]; sizes: WidgetSize[]; onDone: () => void }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const save = useSaveWidgetLayout(projectId, surface)
  const [rows, setRows] = useState<ResolvedWidget[]>(widgets)
  useEffect(() => setRows(widgets), [widgets])

  const move = (i: number, d: -1 | 1) => setRows((r) => {
    const j = i + d
    if (j < 0 || j >= r.length) return r
    const next = [...r]
    ;[next[i], next[j]] = [next[j], next[i]]
    return next
  })
  const patch = (key: string, p: Partial<ResolvedWidget>) => setRows((r) => r.map((w) => (w.key === key ? { ...w, ...p } : w)))

  const submit = () => {
    const items: WidgetLayoutItem[] = rows.map((w, i) => ({ key: w.key, visible: w.visible, order: (i + 1) * 10, size: w.size }))
    save.mutate(items, { onSuccess: onDone })
  }

  return (
    <div data-testid="widgets-editor" className="space-y-2 rounded-2xl border border-border bg-surface p-4">
      <ul className="space-y-2">
        {rows.map((w, i) => {
          const off = w.reason === 'module_off'
          return (
            <li key={w.key} data-testid={`widget-row-${w.key}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border p-3">
              <div className="min-w-0">
                <p className="font-bold text-text-primary">{ar ? w.title.ar : w.title.en}</p>
                {w.reason && <p className="text-xs text-text-muted">{REASON[w.reason]?.[ar ? 'ar' : 'en']}</p>}
              </div>
              <div className="flex items-center gap-2">
                <button type="button" aria-label={ar ? 'أعلى' : 'Move up'} disabled={i === 0} onClick={() => move(i, -1)} className="rounded-md p-1 disabled:opacity-40"><ArrowUp className="size-4" /></button>
                <button type="button" aria-label={ar ? 'أسفل' : 'Move down'} disabled={i === rows.length - 1} onClick={() => move(i, 1)} className="rounded-md p-1 disabled:opacity-40"><ArrowDown className="size-4" /></button>
                <select
                  aria-label={ar ? 'العرض' : 'Width'}
                  value={w.size}
                  disabled={off}
                  onChange={(e) => patch(w.key, { size: e.target.value as WidgetSize })}
                  className="rounded-lg border border-border bg-surface px-2 py-1 text-sm"
                >
                  {sizes.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <Switch checked={w.visible && !off} disabled={off} onCheckedChange={(v) => patch(w.key, { visible: v, reason: v ? null : 'hidden_by_project' })} label={surface === 'report' ? (ar ? 'السماح في روابط العملاء' : 'Allow on client links') : (ar ? 'إظهار' : 'Show')} testId={`widget-switch-${w.key}`} />
              </div>
            </li>
          )
        })}
      </ul>
      {save.isError && <Alert severity="danger" title={toApiError(save.error).message} />}
      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onDone}>{ar ? 'إلغاء' : 'Cancel'}</Button>
        <Button onClick={submit} disabled={save.isPending} data-testid="widgets-save">{ar ? 'حفظ' : 'Save'}</Button>
      </div>
    </div>
  )
}
