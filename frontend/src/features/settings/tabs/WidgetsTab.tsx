import { useState } from 'react'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { LayoutEditor } from '@/features/widgets/WidgetSurface'
import { useWidgetLayout, type WidgetSurface } from '@/features/widgets/api'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

const SURFACES: Array<{ id: WidgetSurface; en: string; ar: string; hintEn: string; hintAr: string }> = [
  { id: 'dashboard', en: 'Dashboard', ar: 'لوحة المعلومات', hintEn: 'Shown beneath the Dashboard overview.', hintAr: 'تظهر أسفل نظرة لوحة المعلومات.' },
  { id: 'analytics', en: 'Analytics', ar: 'التحليلات', hintEn: 'Shown on the Insights tab of Analytics.', hintAr: 'تظهر في تبويب «رؤى إضافية» بالتحليلات.' },
  { id: 'report', en: 'Client links', ar: 'روابط العملاء', hintEn: 'What this project ALLOWS on a client link. Each link still shows nothing until its owner switches a widget on.', hintAr: 'ما يسمح به المشروع في رابط العميل. يبقى كل رابط بلا ودجات حتى يفعّلها صاحبه.' },
]

/** WIDGET-REGISTRY-001 — choose, order and size the widgets of one project's Dashboard, Analytics and client links. */
export function WidgetsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const projectId = useProject((s) => s.currentProjectId)
  const [surface, setSurface] = useState<WidgetSurface>('dashboard')
  const layout = useWidgetLayout(projectId, surface)
  const meta = SURFACES.find((s) => s.id === surface) ?? SURFACES[0]

  if (!projectId) {
    return <EmptyState title={ar ? 'اختر مشروعًا' : 'Choose a project'} description={ar ? 'تُضبط الودجات لكل مشروع. اختر مشروعًا من مبدّل المشاريع.' : 'Widgets are set per project. Pick one from the project switcher.'} />
  }

  return (
    <div className="space-y-4" data-testid="widgets-tab">
      <div>
        <h2 className="text-xl font-bold text-text-primary">{ar ? 'الودجات' : 'Widgets'}</h2>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'إخفاء ودجة أو إظهارها يغيّر الرسم فقط؛ لا يمس أرقامك ولا إجمالياتها.'
            : 'Showing or hiding a widget changes the picture only — never your figures or their totals.'}
        </p>
      </div>
      <div role="tablist" aria-label={ar ? 'السطح' : 'Surface'} className="inline-flex rounded-lg border border-border p-0.5">
        {SURFACES.map((s) => (
          <button
            key={s.id}
            type="button"
            role="tab"
            aria-selected={surface === s.id}
            data-testid={`widgets-surface-${s.id}`}
            onClick={() => setSurface(s.id)}
            className={`rounded-md px-3 py-1.5 text-sm font-bold ${surface === s.id ? 'bg-brand-600 text-white' : 'text-text-secondary'}`}
          >
            {ar ? s.ar : s.en}
          </button>
        ))}
      </div>
      <p className="text-xs text-text-muted">{ar ? meta.hintAr : meta.hintEn}</p>
      {layout.isLoading && <Skeleton className="h-40" />}
      {layout.isError && <EmptyState title={ar ? 'تعذّر تحميل الودجات' : 'Widgets could not be loaded'} />}
      {layout.data && <LayoutEditor key={surface} projectId={projectId} surface={surface} widgets={layout.data.widgets} sizes={layout.data.sizes} onDone={() => undefined} />}
    </div>
  )
}
