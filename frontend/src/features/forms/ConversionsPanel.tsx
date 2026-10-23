import { useState } from 'react'
import { isAxiosError } from 'axios'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { useCreateDestination, useDeleteDestination, useDeliveries, useDestinations, useDiagnose, useForms, useRetryDelivery, useUpdateDestination, type Diagnosis } from './api'
import type { ConversionDestination, ConversionKind } from './types'

/**
 * FORMS-DISPATCH-001 — where a lead's conversion is reported, and what happened to each one.
 *
 * The screen says what the server enforces: a destination starts in TEST and sends nothing; going live is its own
 * confirmed step; a secret is typed once and never shown again; and a conversion is reported only for a visitor who
 * agreed to it, on a form that asked.
 */
const KIND: Record<ConversionKind, { ar: string; en: string; config: Array<{ key: string; ar: string; en: string; placeholder: string }>; secrets: Array<{ key: string; ar: string; en: string }> }> = {
  ga4: { ar: 'GA4 (Measurement Protocol)', en: 'GA4 (Measurement Protocol)', config: [{ key: 'measurement_id', ar: 'معرّف القياس', en: 'Measurement id', placeholder: 'G-XXXXXXXXXX' }], secrets: [{ key: 'api_secret', ar: 'مفتاح API السري', en: 'API secret' }] },
  meta_capi: { ar: 'Meta (واجهة التحويلات)', en: 'Meta (Conversions API)', config: [{ key: 'pixel_id', ar: 'معرّف البيكسل / مجموعة البيانات', en: 'Pixel / dataset id', placeholder: '123456789012345' }], secrets: [{ key: 'access_token', ar: 'رمز الوصول', en: 'Access token' }] },
  gtm_server: { ar: 'GTM من جهة الخادم', en: 'Server-side GTM', config: [{ key: 'endpoint_url', ar: 'عنوان الحاوية (https)', en: 'Container address (https)', placeholder: 'https://gtm.example.com/collect' }], secrets: [{ key: 'api_key', ar: 'مفتاح API (اختياري)', en: 'API key (optional)' }] },
  gtm_web: { ar: 'GTM على الصفحة', en: 'Web GTM (on the page)', config: [{ key: 'container_id', ar: 'معرّف الحاوية', en: 'Container id', placeholder: 'GTM-XXXXXX' }], secrets: [] },
}
const PLANNED = ['TikTok Events API', 'Snapchat Conversions API', 'Google Ads', 'LinkedIn Conversions API', 'X']
const STATUS_TONE = { test: 'neutral', live: 'success', off: 'warning' } as const
const DELIVERY_TONE = { queued: 'info', sent: 'success', failed: 'danger', skipped_consent: 'warning', test: 'neutral' } as const
const input = 'w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm text-text-primary'

export function ConversionsPanel({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const destinations = useDestinations(projectId)
  const forms = useForms(projectId)
  const [adding, setAdding] = useState(false)

  return (
    <section className="space-y-4" data-testid="conversions-panel" aria-labelledby="conv-title">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 id="conv-title" className="text-lg font-bold text-text-primary">{ar ? 'وجهات التحويلات' : 'Conversion destinations'}</h2>
          <p className="text-sm text-text-secondary">
            {ar
              ? 'أين يُبلَّغ عن العميل المحتمل بعد حفظه. تبدأ كل وجهة في وضع الاختبار ولا ترسل شيئًا. لا يُرسل أي تحويل إلا لزائر وافق على ذلك صراحةً في نموذج سأل عنه.'
              : 'Where a lead is reported once it is stored. Every destination starts in test and sends nothing. A conversion is reported only for a visitor who agreed to it, on a form that asked.'}
          </p>
        </div>
        <Button variant="secondary" onClick={() => setAdding((v) => !v)} data-testid="destination-add">{ar ? 'وجهة جديدة' : 'New destination'}</Button>
      </div>

      {adding && <NewDestination projectId={projectId} forms={(forms.data ?? []).map((f) => ({ id: f.id, name: f.name }))} onDone={() => setAdding(false)} />}

      {destinations.isLoading && <Skeleton className="h-20" />}
      {destinations.isSuccess && destinations.data.data.length === 0 && !adding && (
        <p className="rounded-xl border border-dashed border-border p-4 text-sm text-text-secondary" data-testid="destinations-empty">
          {ar ? 'لا وجهات بعد. العملاء المحتملون يُحفظون في مركز العملاء المحتملين دون إبلاغ أي منصة.' : 'No destinations yet. Leads are stored in the Leads Centre and no platform is told.'}
        </p>
      )}

      <div className="space-y-3">
        {(destinations.data?.data ?? []).map((d) => <DestinationCard key={d.id} projectId={projectId} destination={d} formName={forms.data?.find((f) => f.id === d.lead_form_id)?.name ?? null} />)}
      </div>

      <p className="text-xs text-text-secondary" data-testid="planned-kinds">
        {ar ? 'واجهات رسمية لم تُبنَ بعد (لا يمكن إنشاء وجهة لها):' : 'Official interfaces not built yet (a destination for them cannot be created):'} {PLANNED.join(' · ')}
      </p>

      <Deliveries projectId={projectId} />
    </section>
  )
}

function NewDestination({ projectId, forms, onDone }: { projectId: string; forms: Array<{ id: string; name: string }>; onDone: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const create = useCreateDestination(projectId)
  const [kind, setKind] = useState<ConversionKind>('ga4')
  const [name, setName] = useState('')
  const [formId, setFormId] = useState('')
  const [config, setConfig] = useState<Record<string, string>>({})
  const [secrets, setSecrets] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const spec = KIND[kind]

  const submit = () => create.mutate(
    { kind, name: name.trim() || KIND[kind][ar ? 'ar' : 'en'], lead_form_id: formId || null, config, secrets },
    { onSuccess: onDone, onError: (e) => setError(isAxiosError(e) && typeof e.response?.data?.message === 'string' ? e.response.data.message : (ar ? 'تعذّر الحفظ.' : 'Could not save.')) },
  )

  return (
    <Card className="space-y-3 p-4" data-testid="destination-form">
      <label className="block text-sm font-semibold text-text-primary">
        {ar ? 'النوع' : 'Kind'}
        <select className={`${input} mt-1`} value={kind} onChange={(e) => { setKind(e.target.value as ConversionKind); setConfig({}); setSecrets({}) }} data-testid="destination-kind">
          {(Object.keys(KIND) as ConversionKind[]).map((k) => <option key={k} value={k}>{KIND[k][ar ? 'ar' : 'en']}</option>)}
        </select>
      </label>
      <label className="block text-sm font-semibold text-text-primary">
        {ar ? 'الاسم' : 'Name'}
        <input className={`${input} mt-1`} value={name} onChange={(e) => setName(e.target.value)} data-testid="destination-name" />
      </label>
      {spec.config.map((c) => (
        <label key={c.key} className="block text-sm font-semibold text-text-primary">
          {c[ar ? 'ar' : 'en']}
          <input className={`${input} mt-1`} dir="ltr" placeholder={c.placeholder} value={config[c.key] ?? ''} onChange={(e) => setConfig({ ...config, [c.key]: e.target.value })} data-testid={`destination-config-${c.key}`} />
        </label>
      ))}
      {spec.secrets.map((c) => (
        <label key={c.key} className="block text-sm font-semibold text-text-primary">
          {c[ar ? 'ar' : 'en']}
          <input className={`${input} mt-1`} type="password" autoComplete="off" dir="ltr" value={secrets[c.key] ?? ''} onChange={(e) => setSecrets({ ...secrets, [c.key]: e.target.value })} data-testid={`destination-secret-${c.key}`} />
          <span className="block text-xs font-normal text-text-secondary">{ar ? 'يُخزَّن مُشفَّرًا ولا يُعرض مرة أخرى.' : 'Stored encrypted and never shown again.'}</span>
        </label>
      ))}
      <label className="block text-sm font-semibold text-text-primary">
        {ar ? 'النموذج' : 'Form'}
        <select className={`${input} mt-1`} value={formId} onChange={(e) => setFormId(e.target.value)} data-testid="destination-form-scope">
          <option value="">{ar ? 'كل نماذج المشروع' : 'Every form in the project'}</option>
          {forms.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
        </select>
      </label>
      {error && <p role="alert" className="text-sm text-danger" data-testid="destination-error">{error}</p>}
      <div className="flex gap-2">
        <Button onClick={submit} loading={create.isPending} data-testid="destination-save">{ar ? 'حفظ في وضع الاختبار' : 'Save in test mode'}</Button>
        <Button variant="ghost" onClick={onDone}>{ar ? 'إلغاء' : 'Cancel'}</Button>
      </div>
    </Card>
  )
}

function DestinationCard({ projectId, destination: d, formName }: { projectId: string; destination: ConversionDestination; formName: string | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const update = useUpdateDestination(projectId)
  const remove = useDeleteDestination(projectId)
  const diagnose = useDiagnose(projectId)
  const [confirming, setConfirming] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const [diagnosis, setDiagnosis] = useState<Diagnosis | null>(null)
  const [error, setError] = useState<string | null>(null)
  const fail = (e: unknown) => setError(isAxiosError(e) && typeof e.response?.data?.message === 'string' ? e.response.data.message : (ar ? 'تعذّر التنفيذ.' : 'That did not work.'))
  const counts = d.last_7_days
  const sendsFromServer = d.kind !== 'gtm_web'

  return (
    <Card className="space-y-3 p-4" data-testid={`destination-${d.id}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-base font-bold text-text-primary">{d.name}</p>
          <p className="text-xs text-text-secondary">{KIND[d.kind][ar ? 'ar' : 'en']} · {formName ?? (ar ? 'كل النماذج' : 'every form')}</p>
        </div>
        <Badge tone={STATUS_TONE[d.status]} data-testid={`destination-status-${d.id}`}>{{ test: ar ? 'اختبار — لا يرسل' : 'Test — sends nothing', live: ar ? 'مباشر' : 'Live', off: ar ? 'متوقف' : 'Off' }[d.status]}</Badge>
      </div>

      {sendsFromServer && (
        <p className="text-xs text-text-secondary" data-testid={`destination-counts-${d.id}`}>
          {ar ? 'آخر ٧ أيام' : 'Last 7 days'}: {ar ? 'أُرسل' : 'sent'} {counts.sent ?? 0} · {ar ? 'فشل' : 'failed'} {counts.failed ?? 0} · {ar ? 'بلا موافقة' : 'no consent'} {counts.skipped_consent ?? 0} · {ar ? 'اختبار' : 'test'} {counts.test ?? 0}
        </p>
      )}
      {d.last_error?.error && <p className="rounded-lg bg-danger/10 p-2 text-xs text-danger" data-testid={`destination-last-error-${d.id}`}>{d.last_error.error}</p>}
      {d.missing_for_live.length > 0 && d.status !== 'live' && <p className="text-xs text-warning">{ar ? 'ينقص قبل التفعيل:' : 'Needed before it can go live:'} {d.missing_for_live.join(', ')}</p>}
      {error && <p role="alert" className="text-xs text-danger" data-testid={`destination-action-error-${d.id}`}>{error}</p>}

      <div className="flex flex-wrap items-center gap-2">
        {sendsFromServer && <Button size="sm" variant="secondary" loading={diagnose.isPending} onClick={() => diagnose.mutate(d.id, { onSuccess: setDiagnosis, onError: fail })} data-testid={`destination-diagnose-${d.id}`}>{ar ? 'تشخيص' : 'Diagnose'}</Button>}
        {d.status !== 'live' && <Button size="sm" onClick={() => setConfirming((v) => !v)} data-testid={`destination-golive-${d.id}`}>{ar ? 'تفعيل مباشر' : 'Go live'}</Button>}
        {d.status === 'live' && <Button size="sm" variant="secondary" onClick={() => update.mutate({ id: d.id, status: 'test' }, { onError: fail })}>{ar ? 'العودة للاختبار' : 'Back to test'}</Button>}
        {d.status !== 'off' && <Button size="sm" variant="ghost" onClick={() => update.mutate({ id: d.id, status: 'off' }, { onError: fail })}>{ar ? 'إيقاف' : 'Turn off'}</Button>}
        <Button size="sm" variant="ghost" onClick={() => remove.mutate(d.id, { onError: fail })} data-testid={`destination-delete-${d.id}`}>{ar ? 'حذف' : 'Delete'}</Button>
      </div>

      {confirming && (
        <div className="space-y-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm" data-testid={`destination-confirm-${d.id}`}>
          <p className="text-text-primary">
            {d.kind === 'gtm_web'
              ? (ar ? 'سيُحمَّل هذا الحاوية على صفحة النموذج العامة.' : 'This container will be loaded on the public form page.')
              : (ar ? 'سيُرسل إلى هذه المنصة بيانات حقيقية مُشفَّرة (هاش) عن كل زائر وافق على ذلك.' : 'Real, hashed identifiers of every visitor who agreed will be sent to this platform.')}
          </p>
          <label className="flex items-center gap-2 text-text-primary">
            <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} data-testid={`destination-confirm-box-${d.id}`} />
            {ar ? 'أؤكد وأتحمل مسؤولية التفعيل' : 'I confirm and take responsibility for going live'}
          </label>
          <Button size="sm" disabled={!confirmed} onClick={() => update.mutate({ id: d.id, status: 'live', confirm_live: true }, { onSuccess: () => { setConfirming(false); setConfirmed(false); setError(null) }, onError: fail })} data-testid={`destination-live-${d.id}`}>{ar ? 'تفعيل الآن' : 'Go live now'}</Button>
        </div>
      )}

      {diagnosis && (
        <div className="space-y-1 rounded-lg bg-surface-secondary p-3 text-xs" data-testid={`destination-diagnosis-${d.id}`}>
          <p className="font-bold text-text-primary">
            {diagnosis.validation === null
              ? (ar ? 'لم يُرسل شيء. الحمولة أدناه هي ما كان سيُبنى؛ وجهة في وضع الاختبار أو بلا بيانات اعتماد لا يُتصل بها أبدًا.' : 'Nothing was sent. The payload below is what would be built; a destination in test mode, or without its credentials, is never contacted.')
              : (ar ? 'أُرسلت الحمولة التجريبية (بيانات وهمية) إلى واجهة التحقق لدى المنصة فقط.' : 'The synthetic payload was sent to the platform\u2019s checking interface only.')}
          </p>
          {diagnosis.validation && <p className={diagnosis.validation.ok ? 'text-success' : 'text-danger'}>{diagnosis.validation.ok ? (ar ? 'قبلته المنصة.' : 'The platform accepted it.') : diagnosis.validation.error}</p>}
          <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-all text-text-secondary" dir="ltr">{JSON.stringify(diagnosis.payload.body, null, 2)}</pre>
        </div>
      )}
    </Card>
  )
}

function Deliveries({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const deliveries = useDeliveries(projectId)
  const retry = useRetryDelivery(projectId)
  const rows = deliveries.data ?? []
  if (rows.length === 0) return null

  return (
    <div className="space-y-2" data-testid="deliveries">
      <h3 className="text-sm font-bold text-text-primary">{ar ? 'آخر المحاولات' : 'Recent deliveries'}</h3>
      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full min-w-[560px] text-start text-xs">
          <thead className="text-text-muted"><tr><th className="p-2 text-start">{ar ? 'الحدث' : 'Event'}</th><th className="p-2 text-start">{ar ? 'الحالة' : 'Status'}</th><th className="p-2 text-start">{ar ? 'المحاولات' : 'Attempts'}</th><th className="p-2 text-start">{ar ? 'التفاصيل' : 'Detail'}</th><th className="p-2" /></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t border-border" data-testid={`delivery-${r.id}`}>
                <td className="p-2">{r.event_name}</td>
                <td className="p-2"><Badge tone={DELIVERY_TONE[r.status]}>{{ queued: ar ? 'في الانتظار' : 'Queued', sent: ar ? 'أُرسل' : 'Sent', failed: ar ? 'فشل' : 'Failed', skipped_consent: ar ? 'بلا موافقة' : 'No consent', test: ar ? 'اختبار' : 'Test' }[r.status]}</Badge></td>
                <td className="p-2 tabular-nums">{r.attempts}</td>
                <td className="p-2 text-text-secondary">{r.error ?? (r.response_code ? `HTTP ${r.response_code}` : '')}</td>
                <td className="p-2">{r.status === 'failed' && <Button size="sm" variant="ghost" onClick={() => retry.mutate(r.id)} data-testid={`delivery-retry-${r.id}`}>{ar ? 'إعادة' : 'Retry'}</Button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
