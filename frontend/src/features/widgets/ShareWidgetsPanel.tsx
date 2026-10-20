import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, putData, toApiError } from '@/lib/api/client'
import { Switch } from '@/components/ui/Switch'
import { useUi } from '@/stores/ui'
import type { WidgetLayoutResponse, ResolvedWidget } from './api'

const REASON: Record<string, { en: string; ar: string }> = {
  hidden_by_project: { en: 'The project does not allow this on client links', ar: 'المشروع لا يسمح بها في روابط العملاء' },
}

/**
 * WIDGET-REGISTRY-001 — which widgets ONE shared link shows its client. Off until switched on, per link.
 *
 * Draws nothing when no widget's module is on, so the link dialog of a workspace without them is the dialog it was.
 * A widget the project forbids is shown locked, with the reason, and a module that is off is not listed to the
 * client at all (and not offered here either). The switch writes the choice at once; what the client receives is
 * whatever the server answers after it, never what this panel believes.
 */
export function ShareWidgetsPanel({ projectId, reportId, shareId }: { projectId: string; reportId: string; shareId: string }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const url = `/projects/${projectId}/reports/${reportId}/shares/${shareId}/widgets`
  const q = useQuery({ queryKey: ['share-widgets', shareId], queryFn: () => getData<WidgetLayoutResponse>(url), retry: false })
  const save = useMutation({
    mutationFn: (items: Array<{ key: string; visible: boolean; order: number; size: string }>) => putData<WidgetLayoutResponse>(url, { items }),
    onSuccess: (data) => qc.setQueryData(['share-widgets', shareId], data),
  })

  const widgets = (q.data?.widgets ?? []).filter((w) => w.reason !== 'module_off')
  if (q.isError || widgets.length === 0) return null

  const set = (key: string, visible: boolean) => {
    const all: ResolvedWidget[] = q.data?.widgets ?? []
    save.mutate(all.filter((w) => w.reason !== 'module_off').map((w, i) => ({ key: w.key, visible: w.key === key ? visible : w.visible, order: (i + 1) * 10, size: w.size })))
  }

  return (
    <fieldset data-testid="share-widgets" className="space-y-2 rounded-xl border border-border p-3">
      <legend className="px-1 text-xs font-bold text-text-secondary">{ar ? 'ودجات التتبع والإسناد في هذا الرابط' : 'Tracking and attribution widgets on this link'}</legend>
      <p className="text-xs text-text-muted">
        {ar ? 'متوقفة افتراضيًا. لا تُعرض للعميل إلا ما فعّلته هنا ويسمح به المشروع.' : 'Off by default. A client sees only what you switch on here and the project allows.'}
      </p>
      {widgets.map((w) => {
        const locked = w.reason === 'hidden_by_project'
        return (
          <div key={w.key} data-testid={`share-widget-row-${w.key}`} className="flex items-center justify-between gap-3 text-sm">
            <span className="min-w-0">
              {ar ? w.title.ar : w.title.en}
              {locked && w.reason && <span className="block text-xs text-text-muted">{REASON[w.reason]?.[ar ? 'ar' : 'en']}</span>}
            </span>
            <Switch checked={w.visible} disabled={locked || save.isPending} onCheckedChange={(v) => set(w.key, v)} label={ar ? 'إظهار للعميل' : 'Show to client'} testId={`share-widget-switch-${w.key}`} />
          </div>
        )
      })}
      {save.isError && <p role="alert" className="text-xs text-danger">{toApiError(save.error).message}</p>}
    </fieldset>
  )
}
