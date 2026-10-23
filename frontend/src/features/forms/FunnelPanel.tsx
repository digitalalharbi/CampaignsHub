import { useState } from 'react'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { useFormFunnel, useLinkFunnel } from './api'
import type { Funnel } from './types'

/**
 * FORMS-DASHBOARDS-001 — what a form, or a link, produced.
 *
 * Two kinds of figure, said apart: the VISIT (sessions — page loads, not people — from the event log, present only from the
 * day tracking was switched on) and the LEADS (from the Leads Centre, right whether or not tracking was ever on). A rate whose
 * input is missing is drawn as «—», never «0%»; a cost per lead is never drawn at all while no spend is linked, and the
 * panel says why.
 */
const PERIODS = [7, 30, 90] as const
const pct = (r: number | null | undefined) => (r === null || r === undefined ? '—' : `${(r * 100).toFixed(r * 100 < 10 ? 1 : 0)}%`)

export function FormFunnelPanel({ projectId, formId }: { projectId: string; formId: string }) {
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30)
  const q = useFormFunnel(projectId, formId, days)

  return <FunnelView q={q} days={days} setDays={setDays} testid="form-funnel" />
}

export function LinkFunnelPanel({ projectId, linkId }: { projectId: string; linkId: string }) {
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30)
  const q = useLinkFunnel(projectId, linkId, days)

  return <FunnelView q={q} days={days} setDays={setDays} testid="link-funnel" />
}

function FunnelView({ q, days, setDays, testid }: { q: { isLoading: boolean; isError: boolean; data?: Funnel }; days: number; setDays: (d: (typeof PERIODS)[number]) => void; testid: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const f = q.data

  return (
    <Card className="space-y-4 p-4" data-testid={testid}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-bold text-text-primary">{ar ? 'النتائج' : 'Results'}</p>
        <div className="flex gap-1" role="group" aria-label={ar ? 'الفترة' : 'Period'}>
          {PERIODS.map((p) => (
            <button key={p} type="button" aria-pressed={days === p} onClick={() => setDays(p)} className={`rounded-lg border px-2 py-1 text-xs font-semibold ${days === p ? 'border-brand-500 text-brand-600' : 'border-border text-text-secondary'}`} data-testid={`${testid}-period-${p}`}>
              {ar ? `${p} يومًا` : `${p} days`}
            </button>
          ))}
        </div>
      </div>

      {q.isLoading && <Skeleton className="h-24" />}
      {q.isError && <p role="alert" className="text-sm text-danger">{ar ? 'تعذّر تحميل النتائج.' : 'The results could not be loaded.'}</p>}
      {f && <Body f={f} ar={ar} testid={testid} />}
    </Card>
  )
}

function Stat({ label, value, sub, testid }: { label: string; value: string | number | null; sub?: string; testid?: string }) {
  return (
    <div className="rounded-xl border border-border p-3" data-testid={testid}>
      <p className="text-xs text-text-secondary">{label}</p>
      <p className="text-xl font-bold tabular-nums text-text-primary">{value === null ? '—' : value}</p>
      {sub && <p className="text-xs text-text-muted">{sub}</p>}
    </div>
  )
}

function Body({ f, ar, testid }: { f: Funnel; ar: boolean; testid: string }) {
  const L = f.leads
  const v = f.visit

  return (
    <div className="space-y-4">
      {f.scope.type === 'form' && v && (
        v.state === 'measured'
          ? (
            <div className="space-y-2" data-testid={`${testid}-visit`}>
              <p className="text-xs text-text-secondary">{ar ? `الزيارات تُحسب جلسات (تحميل صفحة) لا أشخاصًا، منذ ${v.since}.` : `Visits are counted as sessions (page loads), not people, since ${v.since}.`}</p>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Stat label={ar ? 'شاهدوا النموذج' : 'Viewed'} value={v.views ?? 0} testid={`${testid}-views`} />
                <Stat label={ar ? 'بدأوا' : 'Started'} value={v.starts ?? 0} sub={pct(v.rates?.view_to_start)} />
                <Stat label={ar ? 'حاولوا الإرسال' : 'Tried to send'} value={v.attempts ?? 0} sub={pct(v.rates?.start_to_attempt)} />
                <Stat label={ar ? 'أُرسلت (محفوظة)' : 'Sent (stored)'} value={L.submissions} />
              </div>
              {(v.steps ?? []).length > 0 && (
                <ul className="space-y-1" data-testid={`${testid}-steps`}>
                  {v.steps!.map((s) => (
                    <li key={s.step} className="flex items-center gap-2 text-xs">
                      <span className="w-20 text-text-secondary">{ar ? `الخطوة ${s.step}` : `Step ${s.step}`}</span>
                      <span className="h-2 flex-1 overflow-hidden rounded-full bg-border"><span className="block h-full bg-brand-600" style={{ width: `${s.reached > 0 ? (s.completed / s.reached) * 100 : 0}%` }} /></span>
                      <span className="tabular-nums text-text-primary">{s.completed}/{s.reached}</span>
                      <span className="text-text-muted">{ar ? `غادر ${s.left}` : `${s.left} left`}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )
          : <p className="rounded-lg border border-dashed border-border p-3 text-xs text-text-secondary" data-testid={`${testid}-visit-off`}>{v.state === 'tracking_off' ? (ar ? 'التتبع المتقدم متوقف لهذا المشروع، فلا أرقام للزيارات. العملاء المحتملون أدناه صحيحون رغم ذلك.' : 'Advanced tracking is off for this project, so there are no visit figures. The leads below are right regardless.') : (ar ? 'التتبع يعمل لكن لم تُسجَّل زيارات بعد.' : 'Tracking is on but no visits have been recorded yet.')}</p>
      )}

      {f.scope.type === 'link' && (
        <div className="space-y-2" data-testid={`${testid}-clicks`}>
          {f.clicks === null || f.clicks === undefined
            ? <p className="rounded-lg border border-dashed border-border p-3 text-xs text-text-secondary">{ar ? 'لم تُسجَّل نقرات بعد لهذا المشروع.' : 'No clicks have been recorded for this project yet.'}</p>
            : (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <Stat label={ar ? 'نقرات' : 'Clicks'} value={f.clicks.clicks} sub={ar ? `${f.clicks.machine} آلية مستبعدة` : `${f.clicks.machine} machine excluded`} />
                <Stat label={ar ? 'أجهزة/يوم' : 'Device-days'} value={f.clicks.device_days} sub={ar ? 'تقدير يومي فقط' : 'a per-day estimate'} />
                <Stat label={ar ? 'نقرة ← عميل' : 'Click → lead'} value={pct(f.click_to_lead_rate)} />
              </div>
            )}
          <p className="text-xs text-text-secondary" data-testid={`${testid}-rule`}>{f.attribution_rule}</p>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" data-testid={`${testid}-leads`}>
        <Stat label={ar ? 'عملاء محتملون' : 'Leads'} value={L.received} sub={ar ? `${L.people} شخص` : `${L.people} people`} testid={`${testid}-received`} />
        <Stat label={ar ? 'تم التواصل' : 'Contacted'} value={L.contacted} sub={pct(f.rates.lead_to_contacted)} />
        <Stat label={ar ? 'مؤهَّلون' : 'Qualified'} value={L.qualified} sub={pct(f.rates.lead_to_qualified)} />
        <Stat label={ar ? 'مواعيد' : 'Appointments'} value={L.appointment} sub={pct(f.rates.lead_to_appointment)} />
        <Stat label={ar ? 'صفقات' : 'Won'} value={L.won} sub={pct(f.rates.lead_to_won)} testid={`${testid}-won`} />
        <Stat
          label={ar ? 'قيمة تقديرية للصفقات' : 'Won (estimated value)'}
          value={Object.keys(f.won_estimated_value).length === 0 ? '—' : Object.entries(f.won_estimated_value).map(([c, n]) => `${n.toLocaleString('en-US')} ${c}`).join(' · ')}
          sub={ar ? 'تقدير يدوي، لا إيراد مسجّل' : 'a typed estimate, not booked revenue'}
        />
      </div>

      <p className="text-xs text-text-secondary" data-testid={`${testid}-cost`}>{ar ? 'تكلفة العميل المحتمل غير معروضة: لا إنفاق إعلاني مربوط بنموذج أو رابط، وأي قسمة ستكون ربطًا مُختلقًا.' : f.cost.reason}</p>
      <p className="text-xs text-text-muted">{f.stage_note}</p>
    </div>
  )
}
