import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchResultEvents, saveResultEvents, type ResultEventsMap } from './api'
import { providerLabel } from '@/features/campaigns/labels'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * RESULT-EVENTS-001 — what counts as a «Result», chosen and never inferred.
 *
 * A platform's generic conversion is whatever event the campaign was optimised for — a page load, a form open, a purchase — so it is
 * never called a result on its own. Here the operator says, per platform and per acquisition path, which REPORTED metric is the result.
 * Left on «none», Results and Cost per Result do not appear on the client's report at all. The choice below belongs to this link; saving it
 * as the project's default is a separate, explicit act. Nothing here touches a campaign, a pixel or a conversion setting at the provider.
 */
const PATHS: Array<['all' | 'lead_form' | 'landing_page', string, string]> = [
  ['all', 'كل المسارات', 'All paths'],
  ['lead_form', 'نموذج المنصة الأصلي', 'Native lead form'],
  ['landing_page', 'صفحة الهبوط', 'Landing page'],
]

const METRIC_LABEL: Record<string, [string, string]> = {
  // NASEL 2026-10-29 — named for what it is: the platform's Lead event (Meta's pixel Lead among them), never the verified native form.
  leads: ['حدث Lead من المنصة/البكسل (غير موثّق)', 'Platform/pixel Lead event (unverified)'],
  form_leads: ['نماذج المنصة الأصلية المُرسلة', 'Native form submissions'],
  qualified_leads: ['عملاء مؤهلون', 'Qualified leads'],
  conversions: ['تحويلات المنصة (الحدث المُحسَّن له)', 'Platform conversions (optimised-for event)'],
  registrations: ['تسجيلات', 'Registrations'],
  purchases: ['مشتريات', 'Purchases'],
  add_to_cart: ['إضافات للسلة', 'Add to cart'],
  checkout: ['بدء الدفع', 'Checkout'],
  installs: ['تثبيتات', 'Installs'],
  landing_page_views: ['زيارات الصفحة', 'Landing page views'],
  clicks: ['النقرات', 'Clicks'],
}

/** RESULT-EVENTS-001 — the map a link is created with: only the platforms it covers, each with exactly the choice made for it. */
export function pickSelected(events: ResultEventsMap, platforms: string[]): ResultEventsMap {
  return Object.fromEntries(Object.entries(events).filter(([p]) => platforms.includes(p)))
}

export function ResultEventsPanel({
  projectId,
  providers,
  value,
  onChange,
}: {
  projectId: string
  providers: string[]
  value: ResultEventsMap
  onChange: (next: ResultEventsMap) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const config = useQuery({ queryKey: ['result-events', projectId], queryFn: () => fetchResultEvents(projectId) })
  const [seeded, setSeeded] = useState<string[]>([])

  /*
   * The link starts from the project's own mapping, platform by platform; changing a row overrides it for this link only.
   *
   * Seeded per PLATFORM, not once: a platform that joins after an account switch takes the project default, and a platform the operator
   * already set — or one that left and came back — keeps exactly what they chose. Nothing here ever drops a platform's choice; the builder
   * sends only the platforms the link covers (`pickSelected`).
   */
  useEffect(() => {
    if (!config.data) return
    const fresh = providers.filter((p) => !seeded.includes(p))
    if (fresh.length === 0) return
    setSeeded((prev) => [...prev, ...fresh])
    const defaults = config.data.result_events ?? {}
    const additions = Object.fromEntries(fresh.filter((p) => value[p] === undefined && defaults[p] !== undefined).map((p) => [p, defaults[p]]))
    if (Object.keys(additions).length > 0) onChange({ ...value, ...additions })
  }, [config.data, providers, seeded, value, onChange])

  const save = useMutation({
    mutationFn: () => saveResultEvents(projectId, value),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['result-events', projectId] }),
  })

  const metrics = config.data?.metrics ?? Object.keys(METRIC_LABEL)
  const set = (platform: string, path: string, metric: string) => {
    const paths = { ...(value[platform] ?? {}) }
    if (metric === '') delete paths[path]
    else paths[path] = metric
    const next = { ...value, [platform]: paths }
    if (Object.keys(paths).length === 0) delete next[platform]
    onChange(next)
  }

  return (
    <div className="grid gap-2 rounded-xl border border-border p-3" data-testid="result-events-panel">
      <span className="text-xs font-bold text-text-muted">{ar ? 'النتائج (اختيارية)' : 'Results (optional)'}</span>
      <p className="text-[11px] text-text-muted">
        {ar
          ? 'لا تُحتسب النتائج تلقائيًا. اختر لكل منصة ومسار أي مقياس مُبلَّغ عنه يُعدّ نتيجة؛ وإن تركته «بدون» فلن تظهر «النتائج» ولا «تكلفة النتيجة» للعميل.'
          : 'Results are never inferred. Choose, per platform and path, which reported metric counts as a result; left on “none”, Results and Cost per result do not appear for the client.'}
      </p>
      {providers.length === 0 ? (
        <p className="text-xs text-text-muted">{ar ? 'اختر حسابًا أولًا.' : 'Choose an account first.'}</p>
      ) : (
        providers.map((platform) => (
          <div key={platform} className="grid gap-1 rounded-lg bg-surface-secondary p-2" data-testid={`result-events-${platform}`}>
            <span className="text-xs font-semibold">{providerLabel(platform, ar ? 'ar' : 'en')}</span>
            <div className="grid gap-1 sm:grid-cols-3">
              {PATHS.map(([path, labelAr, labelEn]) => (
                <label key={path} className="grid gap-0.5 text-[11px] text-text-secondary">
                  {ar ? labelAr : labelEn}
                  <select
                    data-testid={`result-event-${platform}-${path}`}
                    value={value[platform]?.[path] ?? ''}
                    onChange={(e) => set(platform, path, e.target.value)}
                    className="rounded-lg border border-border bg-surface px-2 py-1 text-xs text-text-primary"
                  >
                    <option value="">{ar ? 'بدون (مخفي)' : 'None (hidden)'}</option>
                    {metrics.map((m) => (
                      <option key={m} value={m}>{(METRIC_LABEL[m] ?? [m, m])[ar ? 0 : 1]}</option>
                    ))}
                  </select>
                </label>
              ))}
            </div>
          </div>
        ))
      )}
      <div className="flex items-center gap-2">
        <Button type="button" variant="secondary" data-testid="result-events-save" loading={save.isPending} onClick={() => save.mutate()}>
          {ar ? 'حفظ كافتراضي للمشروع' : 'Save as the project default'}
        </Button>
        {save.isSuccess && <span className="text-[11px] text-success">{ar ? 'تم الحفظ' : 'Saved'}</span>}
      </div>
    </div>
  )
}
