import { phoneError } from '@/lib/phone'
import { DEFAULT_DIAL_CODE, PhoneField, phoneFieldValue } from '@/components/ui/PhoneField'
import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, CheckCircle2, Copy, FileText, Megaphone, Paperclip, RotateCcw, X } from 'lucide-react'
import {
  deleteUploadFile, getRequestMeta, startUploadSession, submitRequest, uploadRequestFile,
  type RequestSubmitPayload, type RequestType,
} from './api'
import { ContactVerification } from './ContactVerification'
import { PaidMediaIntake } from './PaidMediaIntake'
import { Button } from '@/components/ui/Button'
import { EmailInput, FormField, TextInput, TextareaField } from '@/components/ui/form'
import { controlClass } from '@/components/ui/Field'
import { DateField } from '@/components/ui/DateField'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

const DRAFT_KEY = 'ch-request-draft-v2' // v2: stores only non-sensitive {type, step, ts}
const DRAFT_TTL_MS = 24 * 60 * 60 * 1000
const PLATFORMS = ['Meta', 'Google', 'TikTok', 'Snapchat', 'X', 'LinkedIn']

// Maps a homepage decision-section handoff value to a request module, so a journey card lands the
// visitor on the right service (dynamic paid-advertising form vs influencer/UGC form), skipping step 0.
// Both the legacy `?service=` param and the v4 `?module=` param feed this same map. `module=paid-media`
// is intercepted earlier (renders PaidMediaIntake), so here it only matters for `module=influencer-marketing`.
const SERVICE_TO_MODULE: Record<string, string> = {
  'paid-media': 'paid_media',
  'influencer-marketing': 'influencer_marketing',
}

interface UploadFile {
  localId: string
  name: string
  size: number
  status: 'uploading' | 'done' | 'error'
  pct: number
  id?: number
  error?: string
  file?: File // kept in memory for retry (never persisted)
}

type Meta = Record<string, unknown>
interface FormState {
  type: string
  contact_name: string
  contact_email: string
  contact_phone: string
  /** The country chosen in the phone control, without a `+`. Never sent — it only sets the default region. */
  dial_code: string
  company_name: string
  objective: string
  budget: string
  currency: string
  priority: 'critical' | 'high' | 'medium' | 'low'
  start_date: string
  due_date: string
  meta: Meta
}

const EMPTY: FormState = {
  type: '', contact_name: '', contact_email: '', contact_phone: '', dial_code: DEFAULT_DIAL_CODE, company_name: '',
  objective: '', budget: '', currency: 'SAR', priority: 'medium', start_date: '', due_date: '', meta: {},
}

/** Which detail fields to show for a given service module. */
function detailFields(module: string): { key: string; labelAr: string; labelEn: string; type?: 'textarea' }[] {
  switch (module) {
    case 'paid_media':
      return [
        { key: 'audience', labelAr: 'الجمهور المستهدف', labelEn: 'Target audience' },
        { key: 'regions', labelAr: 'المناطق', labelEn: 'Regions' },
        { key: 'landing_url', labelAr: 'صفحة الهبوط أو المتجر', labelEn: 'Landing page / store' },
        { key: 'creatives_status', labelAr: 'حالة الإعلانات', labelEn: 'Creatives status' },
        { key: 'tracking_status', labelAr: 'حالة القياس والتتبع', labelEn: 'Tracking status' },
      ]
    case 'tracking':
      return [
        { key: 'site_or_app', labelAr: 'الموقع أو التطبيق', labelEn: 'Website or app' },
        { key: 'platform_tech', labelAr: 'المنصة التقنية', labelEn: 'Tech platform' },
        { key: 'ga4', labelAr: 'GA4', labelEn: 'GA4' },
        { key: 'gtm', labelAr: 'GTM', labelEn: 'GTM' },
        { key: 'pixels', labelAr: 'Pixels / CAPI', labelEn: 'Pixels / CAPI' },
        { key: 'events', labelAr: 'الأحداث المطلوبة', labelEn: 'Required events', type: 'textarea' },
      ]
    case 'reporting':
      return [
        { key: 'report_period', labelAr: 'الفترة', labelEn: 'Period' },
        { key: 'report_type', labelAr: 'نوع التقرير', labelEn: 'Report type' },
        { key: 'report_audience', labelAr: 'جمهور التقرير', labelEn: 'Report audience' },
        { key: 'report_language', labelAr: 'اللغة', labelEn: 'Language' },
        { key: 'formats', labelAr: 'الصيغ (PDF/XLSX/CSV)', labelEn: 'Formats (PDF/XLSX/CSV)' },
      ]
    case 'analytics':
      return [
        { key: 'analysis_period', labelAr: 'الفترة المطلوب تحليلها', labelEn: 'Analysis period' },
        { key: 'current_kpi', labelAr: 'المؤشر الأساسي الحالي', labelEn: 'Current primary KPI' },
        { key: 'current_results', labelAr: 'النتائج الحالية (CPA/CPL/ROAS)', labelEn: 'Current results (CPA/CPL/ROAS)' },
        { key: 'challenge', labelAr: 'المشكلة أو التحدي', labelEn: 'Problem or challenge', type: 'textarea' },
      ]
    case 'influencer_marketing':
      return [
        { key: 'collaboration_type', labelAr: 'نوع التعاون', labelEn: 'Collaboration type' },
        { key: 'influencer_tier', labelAr: 'فئة المؤثرين', labelEn: 'Influencer tier' },
        { key: 'cities', labelAr: 'المدن', labelEn: 'Cities' },
        { key: 'content_count', labelAr: 'عدد المحتويات', labelEn: 'Content count' },
      ]
    default:
      return []
  }
}

/**
 * Intake entry point. `?module=paid-media` activates the dynamic, service-driven paid-media intake;
 * every other entry keeps the original anonymous flow unchanged.
 */
export function RequestIntakePage() {
  const [params] = useSearchParams()
  if (params.get('module') === 'paid-media') return <PaidMediaIntake />
  return <DefaultIntake />
}

function DefaultIntake() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const dir = ar ? 'rtl' : 'ltr'
  const Arrow = ar ? ArrowLeft : ArrowRight

  const metaQuery = useQuery({ queryKey: ['requests', 'meta'], queryFn: getRequestMeta })
  const types = metaQuery.data?.types ?? []
  // Announced but not openable (INFL-SOON-001). A separate list from `types`, by design — see the grid.
  const comingSoon = metaQuery.data?.coming_soon ?? []

  // Journey handoff from the homepage decision cards: `?service=` or `?module=` presets the module and
  // skips step 0. (`module=paid-media` never reaches here — RequestIntakePage renders PaidMediaIntake for it.)
  const [searchParams] = useSearchParams()
  const serviceModule =
    SERVICE_TO_MODULE[searchParams.get('service') ?? ''] ?? SERVICE_TO_MODULE[searchParams.get('module') ?? '']
  const servicePresetApplied = useRef(false)

  const [form, setForm] = useState<FormState>(EMPTY)
  const [step, setStep] = useState(0)

  // NON-SENSITIVE draft only: the selected service + current step (never name/email/phone/objective/
  // ad-account data/files/tokens). Expires after 24h; nothing personally identifying is ever persisted.
  useEffect(() => {
    try {
      const raw = localStorage.getItem(DRAFT_KEY)
      if (!raw) return
      const d = JSON.parse(raw) as { type?: string; step?: number; ts?: number }
      if (!d.ts || Date.now() - d.ts > DRAFT_TTL_MS) { localStorage.removeItem(DRAFT_KEY); return }
      if (d.type) setForm((f) => ({ ...f, type: d.type! }))
      if (typeof d.step === 'number') setStep(Math.min(Math.max(d.step, 0), 4))
    } catch { /* ignore malformed draft */ }
  }, [])
  const [errors, setErrors] = useState<Record<string, string>>({})
  /** The refusal shown after pressing an announced-but-unopenable service (INFL-SOON-001). */
  const [soonNotice, setSoonNotice] = useState<string | null>(null)

  // Persist ONLY the non-sensitive service + step (+ timestamp for expiry). No PII ever touches storage.
  useEffect(() => {
    try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ type: form.type, step, ts: Date.now() })) } catch { /* quota */ }
  }, [form.type, step])

  // Preselect the service that matches `?service=` once the meta list loads, then jump past the service
  // step (step 0). Applied once and takes precedence over any restored draft; the choice stays editable
  // (the user can go Back to step 0). No param → unchanged behaviour.
  useEffect(() => {
    if (servicePresetApplied.current || !serviceModule || types.length === 0) return
    const match = types.find((t) => t.module === serviceModule)
    if (!match) return
    servicePresetApplied.current = true
    setForm((f) => ({ ...f, type: match.key }))
    setStep((s) => (s < 1 ? 1 : s))
  }, [serviceModule, types])

  const clearDraft = () => {
    try { localStorage.removeItem(DRAFT_KEY) } catch { /* noop */ }
    setForm(EMPTY); setStep(0); setErrors({})
  }

  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setForm((f) => ({ ...f, [k]: v }))

  /*
   * The one canonical form of what is in the phone control, or null while it is not readable yet.
   *
   * Used for validation, for the OTP challenge and for the payload alike, so the number that gets
   * verified is character-for-character the number that gets submitted.
   */
  const canonicalPhone = phoneFieldValue(form.contact_phone, form.dial_code)
  const setMeta = (k: string, v: unknown) => setForm((f) => ({ ...f, meta: { ...f.meta, [k]: v } }))

  // Attachments — the upload token lives in memory ONLY (a token is sensitive; never localStorage).
  const [uploadToken, setUploadToken] = useState<string | null>(null)
  const [files, setFiles] = useState<UploadFile[]>([])
  const [notes, setNotes] = useState('')
  const anyUploading = files.some((f) => f.status === 'uploading')
  // Verified contact ids (OTP). A final submit requires BOTH phone and email verified.
  const [verifiedIds, setVerifiedIds] = useState<{ phone?: string; email?: string }>({})
  const contactVerified = Boolean(verifiedIds.phone && verifiedIds.email)

  async function ensureSession(): Promise<string | null> {
    if (uploadToken) return uploadToken
    try {
      const { upload_token } = await startUploadSession()
      setUploadToken(upload_token)
      return upload_token
    } catch { return null }
  }

  async function uploadOne(token: string, localId: string, file: File) {
    setFiles((prev) => prev.map((f) => (f.localId === localId ? { ...f, status: 'uploading', pct: 0, error: undefined } : f)))
    try {
      const meta = await uploadRequestFile(token, file, (pct) => setFiles((prev) => prev.map((f) => (f.localId === localId ? { ...f, pct } : f))))
      setFiles((prev) => prev.map((f) => (f.localId === localId ? { ...f, status: 'done', id: meta.id, pct: 100 } : f)))
    } catch (err) {
      const msg = toApiError(err).errors?.file?.[0] ?? toApiError(err).message
      setFiles((prev) => prev.map((f) => (f.localId === localId ? { ...f, status: 'error', error: msg } : f)))
    }
  }

  async function addFiles(list: FileList | null) {
    if (!list || list.length === 0) return
    const selected = Array.from(list) // snapshot BEFORE any await (the input may be cleared right after)
    const token = await ensureSession()
    if (!token) return
    for (const file of selected) {
      const localId = crypto.randomUUID()
      setFiles((prev) => [...prev, { localId, name: file.name, size: file.size, status: 'uploading', pct: 0, file }])
      await uploadOne(token, localId, file)
    }
  }

  async function retryFile(f: UploadFile) {
    const token = await ensureSession()
    if (token && f.file) await uploadOne(token, f.localId, f.file)
  }

  async function removeFile(f: UploadFile) {
    if (f.id && uploadToken) await deleteUploadFile(uploadToken, f.id).catch(() => undefined)
    setFiles((prev) => prev.filter((x) => x.localId !== f.localId))
  }

  const selectedType: RequestType | undefined = types.find((t) => t.key === form.type)
  const module = selectedType?.module ?? ''
  const showBudget = module === 'paid_media' || module === 'influencer_marketing'
  const showPlatforms = module === 'paid_media' || module === 'influencer_marketing'

  const STEPS = ar
    ? ['الخدمة', 'مقدّم الطلب والنشاط', 'الهدف والمنصات والتتبع', 'الميزانية والمدة', 'المرفقات والملاحظات', 'المراجعة']
    : ['Service', 'Applicant & business', 'Objective, platforms & tracking', 'Budget & timeline', 'Attachments & notes', 'Review']

  const mutation = useMutation({
    mutationFn: submitRequest,
    onSuccess: () => { try { localStorage.removeItem(DRAFT_KEY) } catch { /* noop */ } },
  })
  const apiError = mutation.isError ? toApiError(mutation.error) : null

  function validateStep(s: number): boolean {
    const e: Record<string, string> = {}
    if (s === 0 && !form.type) e.type = ar ? 'اختر نوع الخدمة' : 'Select a service'
    if (s === 1) {
      if (form.contact_name.trim().length < 2) e.contact_name = ar ? 'الاسم مطلوب' : 'Name is required'
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.contact_email)) e.contact_email = ar ? 'بريد غير صحيح' : 'Invalid email'
      /*
       * PHONE-001 — accept the number the way the customer writes it.
       *
       * This demanded a leading «+», so «0501234567» was refused IN THE BROWSER and never reached the
       * backend that would have normalised it. The customer was told to add a country code they had no
       * reason to think was missing, on the public intake form, before they could ask for anything.
       */
      if (canonicalPhone === null) e.contact_phone = phoneError(ar)
      if (form.company_name.trim().length < 2) e.company_name = ar ? 'اسم المنشأة مطلوب' : 'Company name is required'
    }
    if (s === 2 && form.objective.trim().length < 5) e.objective = ar ? 'اذكر هدف الطلب باختصار' : 'Describe the objective'
    if (s === 4 && anyUploading) e.files = ar ? 'انتظر اكتمال رفع الملفات قبل المتابعة' : 'Wait for uploads to finish before continuing'
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const next = () => { if (validateStep(step)) setStep((s) => Math.min(s + 1, STEPS.length - 1)) }
  const back = () => setStep((s) => Math.max(s - 1, 0))

  const submit = () => {
    if (mutation.isPending || anyUploading || !contactVerified) return // block until phone+email verified
    const platforms = (form.meta.platforms as string[] | undefined) ?? []
    const payload: RequestSubmitPayload = {
      type: form.type,
      contact_name: form.contact_name.trim(),
      contact_email: form.contact_email.trim(),
      contact_phone: canonicalPhone ?? undefined,
      company_name: form.company_name || undefined,
      objective: form.objective || undefined,
      budget: showBudget && form.budget ? Number(form.budget) : undefined,
      currency: form.currency,
      priority: form.priority,
      start_date: form.start_date || undefined,
      due_date: form.due_date || undefined,
      metadata: { ...form.meta, platforms, notes: notes || undefined, locale },
      upload_token: uploadToken ?? undefined,
      website: '', // honeypot stays empty
      phone_verification_id: verifiedIds.phone,
      email_verification_id: verifiedIds.email,
    }
    mutation.mutate(payload)
  }

  // ---- Success view ----
  if (mutation.isSuccess && mutation.data) {
    const r = mutation.data
    const trackUrl = `${window.location.origin}/requests/track?token=${r.tracking_token}`
    return <SuccessView reference={r.reference} type={r.type} trackUrl={trackUrl} ar={ar} dir={dir} />
  }

  const togglePlatform = (p: string) => {
    const cur = (form.meta.platforms as string[] | undefined) ?? []
    setMeta('platforms', cur.includes(p) ? cur.filter((x) => x !== p) : [...cur, p])
  }

  return (
    <div dir={dir} className="min-h-screen bg-background text-text-primary">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-3xl items-center gap-2.5 px-4 sm:px-6">
          <Link to="/" className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold">CampaignsHub</span>
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <h1 className="font-heading text-2xl font-extrabold sm:text-3xl">{ar ? 'طلب إدارة حملة' : 'Campaign management request'}</h1>
        <p className="mt-1.5 text-sm text-text-secondary">{ar ? 'أكمل الخطوات وسنراجع طلبك ونتواصل معك عبر رابط تتبع آمن.' : 'Complete the steps; we’ll review your request and follow up via a secure tracking link.'}</p>

        {/* Stepper */}
        <ol className="mt-6 flex flex-wrap gap-x-2 gap-y-1.5 text-xs font-semibold">
          {STEPS.map((label, i) => (
            <li key={label} className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 ${i === step ? 'bg-brand-primary-soft text-brand-700' : i < step ? 'text-brand-600' : 'text-text-muted'}`}>
              <span className={`tnum flex h-5 w-5 items-center justify-center rounded-full text-[11px] ${i <= step ? 'bg-brand-600 text-white' : 'bg-surface-secondary'}`}>{i < step ? <Check size={12} /> : i + 1}</span>
              {label}
            </li>
          ))}
        </ol>

        <div className="mt-6 rounded-2xl border border-border bg-surface p-5 sm:p-6">
          {/* Step 0 — Service */}
          {step === 0 && (
            <div className="space-y-3">
              <FieldTitle ar={ar} title={ar ? 'اختر نوع الخدمة' : 'Choose a service'} />
              {metaQuery.isLoading && <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />}
              {metaQuery.isError && <p className="text-sm text-danger">{ar ? 'تعذّر تحميل الخدمات، حدّث الصفحة.' : 'Could not load services, please refresh.'}</p>}
              <div className="grid gap-2.5 sm:grid-cols-2">
                {types.map((t) => (
                  <button
                    key={t.key} type="button" onClick={() => set('type', t.key)} aria-pressed={form.type === t.key}
                    className={`min-h-[56px] rounded-xl border px-4 py-3 text-start text-sm font-semibold transition-colors ${form.type === t.key ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border bg-surface hover:border-brand-400'}`}
                  >
                    {ar ? t.name_ar : t.name_en}
                  </button>
                ))}

                {/*
                  INFL-SOON-001 — named, and not openable.
                  A withdrawn service used to be absent from this list entirely, so somebody looking
                  for influencer work read the catalogue, did not find it, and concluded the product
                  does not do it. It is announced here instead — in the same grid so the columns stay
                  balanced at every width, and visibly inert so nobody spends time on a dead end.
                  It comes from `coming_soon`, a different list from the one this form submits, so it
                  cannot become a selection: there is no `set('type', …)` on this branch to forget.
                */}
                {comingSoon.map((t) => (
                  <button
                    key={t.key}
                    type="button"
                    data-testid={`service-soon-${t.key}`}
                    aria-disabled="true"
                    onClick={() => setSoonNotice(ar ? t.note_ar : t.note_en)}
                    className="min-h-[56px] cursor-not-allowed rounded-xl border border-dashed border-border-strong bg-surface-secondary px-4 py-3 text-start text-sm font-semibold text-text-secondary"
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      {ar ? t.name_ar : t.name_en}
                      <span className="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-text-muted">
                        {ar ? 'قريبًا' : 'Coming soon'}
                      </span>
                    </span>
                  </button>
                ))}
              </div>
              {/*
                The refusal is stated once, below the grid, and only after a press — a permanent
                notice beside a card nobody has touched is noise, and an alert() would be a modal the
                reader has to dismiss before they can carry on choosing.
              */}
              {soonNotice && (
                <p data-testid="service-soon-notice" role="status" className="rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-sm text-text-secondary">
                  {soonNotice}
                </p>
              )}
              {errors.type && <p className="text-sm text-danger">{errors.type}</p>}
            </div>
          )}

          {/* Step 1 — Applicant */}
          {step === 1 && (
            <div className="space-y-4">
              <FieldTitle ar={ar} title={ar ? 'معلومات مقدّم الطلب' : 'Applicant information'} />
              <TextInput label={ar ? 'الاسم' : 'Name'} value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} required error={errors.contact_name} />
              <EmailInput label={ar ? 'البريد الإلكتروني' : 'Email'} value={form.contact_email} onChange={(e) => set('contact_email', e.target.value)} required error={errors.contact_email} />
              <div className="grid gap-4 sm:grid-cols-2">
                {/* PHONE-SA-001 — the product's one phone control, the same one registration uses. */}
                <PhoneField
                  id="contact_phone"
                  label={ar ? 'رقم الجوال' : 'Mobile number'}
                  ar={ar}
                  value={form.contact_phone}
                  onChange={(v) => set('contact_phone', v)}
                  dialCode={form.dial_code}
                  onDialCodeChange={(v) => set('dial_code', v)}
                  required
                  error={errors.contact_phone}
                  hint={ar ? 'بمفتاح الدولة أو بدونه — كلاهما مقبول.' : 'With or without a country code — both are accepted.'}
                />
                <TextInput label={ar ? 'اسم النشاط أو الشركة' : 'Company'} value={form.company_name} onChange={(e) => set('company_name', e.target.value)} required error={errors.company_name} />
              </div>
            </div>
          )}

          {/* Step 2 — Details (service-specific) */}
          {step === 2 && (
            <div className="space-y-4">
              <FieldTitle ar={ar} title={ar ? 'تفاصيل الطلب' : 'Request details'} />
              <TextareaField label={ar ? 'هدف الطلب' : 'Objective'} value={form.objective} onChange={(e) => set('objective', e.target.value)} maxLength={2000} required error={errors.objective} />
              {detailFields(module).map((f) => (
                f.type === 'textarea' ? (
                  <TextareaField key={f.key} label={ar ? f.labelAr : f.labelEn} value={(form.meta[f.key] as string) ?? ''} onChange={(e) => setMeta(f.key, e.target.value)} maxLength={1000} />
                ) : (
                  <TextInput key={f.key} label={ar ? f.labelAr : f.labelEn} value={(form.meta[f.key] as string) ?? ''} onChange={(e) => setMeta(f.key, e.target.value)} />
                )
              ))}
              {showPlatforms && (
                <FormField label={ar ? 'المنصات' : 'Platforms'}>
                  <div className="flex flex-wrap gap-2">
                    {PLATFORMS.map((p) => {
                      const on = ((form.meta.platforms as string[] | undefined) ?? []).includes(p)
                      return (
                        <button key={p} type="button" onClick={() => togglePlatform(p)} className={`rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${on ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:border-brand-400'}`}>{p}</button>
                      )
                    })}
                  </div>
                </FormField>
              )}
            </div>
          )}

          {/* Step 3 — Budget & timeline */}
          {step === 3 && (
            <div className="space-y-4">
              <FieldTitle ar={ar} title={ar ? 'الميزانية والمدة' : 'Budget & timeline'} />
              {showBudget ? (
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextInput label={ar ? 'الميزانية' : 'Budget'} value={form.budget} onChange={(e) => set('budget', e.target.value.replace(/[^\d.]/g, ''))} inputMode="decimal" dir="ltr" />
                  <FormField label={ar ? 'العملة' : 'Currency'}>
                    <select className={controlClass} value={form.currency} onChange={(e) => set('currency', e.target.value)}>
                      {['SAR', 'AED', 'USD', 'EGP'].map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </FormField>
                </div>
              ) : (
                <p className="text-sm text-text-muted">{ar ? 'لا تُطلب ميزانية لهذا النوع من الطلبات.' : 'A budget is not required for this request type.'}</p>
              )}
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField label={ar ? 'تاريخ البداية' : 'Start date'}>
                  <DateField value={form.start_date} onChange={(v) => set('start_date', v)} />
                </FormField>
                <FormField label={ar ? 'التاريخ المطلوب' : 'Needed by'}>
                  <DateField value={form.due_date} onChange={(v) => set('due_date', v)} />
                </FormField>
              </div>
              <FormField label={ar ? 'الأولوية' : 'Priority'}>
                <select className={controlClass} value={form.priority} onChange={(e) => set('priority', e.target.value as FormState['priority'])}>
                  <option value="low">{ar ? 'منخفضة' : 'Low'}</option>
                  <option value="medium">{ar ? 'متوسطة' : 'Medium'}</option>
                  <option value="high">{ar ? 'عالية' : 'High'}</option>
                  <option value="critical">{ar ? 'حرجة' : 'Critical'}</option>
                </select>
              </FormField>
              <p className="rounded-lg bg-surface-secondary px-3 py-2 text-xs text-text-muted">{ar ? 'رفع المرفقات الآمن يُضاف في الخطوة التالية من التطوير.' : 'Secure file attachments are added in the next development step.'}</p>
            </div>
          )}

          {/* Step 4 — Attachments & notes */}
          {step === 4 && (
            <div className="space-y-4">
              <FieldTitle ar={ar} title={ar ? 'المرفقات والملاحظات' : 'Attachments & notes'} />
              <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border bg-surface-secondary px-4 py-6 text-center hover:border-brand-400">
                <Paperclip size={22} className="text-text-muted" />
                <span className="text-sm font-semibold text-text-secondary">{ar ? 'اختر ملفات للرفع' : 'Choose files to upload'}</span>
                <span className="text-xs text-text-muted">{ar ? 'PDF، صور، Excel، CSV — حتى 10MB لكل ملف' : 'PDF, images, Excel, CSV — up to 10MB each'}</span>
                <input type="file" multiple className="hidden" onChange={(e) => { void addFiles(e.target.files); e.target.value = '' }} />
              </label>
              {errors.files && <p className="text-sm text-danger">{errors.files}</p>}
              <ul className="space-y-2">
                {files.map((f) => (
                  <li key={f.localId} className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2.5">
                    <FileText size={18} className="shrink-0 text-text-muted" />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium text-text-primary">{f.name}</span>
                      <span className="text-xs text-text-muted">{(f.size / 1024).toFixed(0)} KB{f.status === 'uploading' ? ` · ${f.pct}%` : ''}{f.status === 'error' ? ` · ${f.error ?? (ar ? 'فشل' : 'failed')}` : ''}</span>
                    </span>
                    {f.status === 'uploading' && <span className="tnum text-xs font-semibold text-brand-600">{f.pct}%</span>}
                    {f.status === 'done' && <Check size={16} className="shrink-0 text-success" />}
                    {f.status === 'error' && <button type="button" onClick={() => void retryFile(f)} className="text-text-muted hover:text-brand-600" aria-label="retry"><RotateCcw size={15} /></button>}
                    <button type="button" onClick={() => void removeFile(f)} className="shrink-0 text-text-muted hover:text-danger" aria-label="remove"><X size={16} /></button>
                  </li>
                ))}
              </ul>
              <TextareaField label={ar ? 'ملاحظات إضافية' : 'Additional notes'} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} />
            </div>
          )}

          {/* Step 5 — Review */}
          {step === 5 && (
            <div className="space-y-3">
              <FieldTitle ar={ar} title={ar ? 'مراجعة الطلب' : 'Review your request'} />
              <dl className="divide-y divide-border rounded-xl border border-border">
                <Row k={ar ? 'الخدمة' : 'Service'} v={selectedType ? (ar ? selectedType.name_ar : selectedType.name_en) : '—'} />
                <Row k={ar ? 'الاسم' : 'Name'} v={form.contact_name} />
                <Row k={ar ? 'البريد' : 'Email'} v={form.contact_email} />
                {form.company_name && <Row k={ar ? 'الشركة' : 'Company'} v={form.company_name} />}
                <Row k={ar ? 'الهدف' : 'Objective'} v={form.objective} />
                {showBudget && form.budget && <Row k={ar ? 'الميزانية' : 'Budget'} v={`${form.budget} ${form.currency}`} />}
                <Row k={ar ? 'الأولوية' : 'Priority'} v={form.priority} />
                <Row k={ar ? 'الملفات' : 'Files'} v={files.filter((f) => f.status === 'done').length ? files.filter((f) => f.status === 'done').map((f) => f.name).join('، ') : (ar ? 'لا يوجد' : 'None')} />
              </dl>

              <div className="rounded-xl border border-border bg-surface-secondary p-3">
                <FieldTitle ar={ar} title={ar ? 'تحقّق من وسيلة التواصل' : 'Verify your contact'} />
                <div className="mt-2">
                  <ContactVerification phone={canonicalPhone ?? ''} email={form.contact_email.trim()} ar={ar} onChange={(ids) => setVerifiedIds((p) => ({ ...p, ...ids }))} />
                </div>
              </div>

              {apiError && <p className="rounded-lg bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{apiError.message}</p>}
            </div>
          )}

          {/* Nav */}
          <div className="mt-6 flex items-center justify-between border-t border-border pt-4">
            <Button variant="ghost" onClick={back} disabled={step === 0}>{ar ? 'السابق' : 'Back'}</Button>
            {step < STEPS.length - 1 ? (
              <Button onClick={next} disabled={step === 4 && anyUploading}>{ar ? 'التالي' : 'Next'} <Arrow size={15} className="ms-1.5" /></Button>
            ) : (
              <Button onClick={submit} loading={mutation.isPending} disabled={anyUploading || !contactVerified}>{ar ? 'إرسال الطلب' : 'Submit request'}</Button>
            )}
          </div>
        </div>

        <div className="mt-6 flex items-center justify-between">
          <Link to="/" className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {ar ? 'العودة للصفحة الرئيسية' : 'Back to home'}</Link>
          <button type="button" onClick={clearDraft} className="text-sm text-text-muted hover:text-danger">{ar ? 'حذف المسودة' : 'Clear draft'}</button>
        </div>
      </main>
    </div>
  )
}

function FieldTitle({ title }: { title: string; ar: boolean }) {
  return <h2 className="text-base font-bold text-text-primary">{title}</h2>
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex gap-3 px-4 py-2.5 text-sm">
      <dt className="w-32 shrink-0 text-text-muted">{k}</dt>
      <dd className="min-w-0 flex-1 break-words font-medium text-text-primary">{v}</dd>
    </div>
  )
}

function SuccessView({ reference, type, trackUrl, ar, dir }: { reference: string; type: string; trackUrl: string; ar: boolean; dir: string }) {
  const [copied, setCopied] = useState<'ref' | 'url' | null>(null)
  const copy = (which: 'ref' | 'url', value: string) => {
    void navigator.clipboard?.writeText(value)
    setCopied(which)
    window.setTimeout(() => setCopied((c) => (c === which ? null : c)), 1500)
  }
  return (
    <div dir={dir} className="flex min-h-screen flex-col items-center justify-center bg-background px-5 text-center text-text-primary">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-success/15 text-success"><CheckCircle2 size={28} /></div>
      <h1 className="mt-5 font-heading text-2xl font-extrabold">{ar ? 'تم استلام طلبك' : 'Request received'}</h1>
      <p className="mt-2 max-w-md text-sm text-text-secondary">{ar ? 'احتفظ برقم الطلب ورابط التتبع لمتابعة الحالة.' : 'Keep your request number and tracking link to follow the status.'}</p>

      <div className="mt-6 w-full max-w-md space-y-2.5 text-start">
        <div className="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface px-4 py-3">
          <span><span className="block text-xs text-text-muted">{ar ? 'رقم الطلب' : 'Request number'}</span><span className="font-mono text-sm font-bold" dir="ltr">{reference}</span></span>
          <button type="button" onClick={() => copy('ref', reference)} className="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft">{copied === 'ref' ? <Check size={13} /> : <Copy size={13} />}{ar ? 'نسخ' : 'Copy'}</button>
        </div>
        <div className="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface px-4 py-3">
          <span className="min-w-0"><span className="block text-xs text-text-muted">{ar ? 'رابط التتبع' : 'Tracking link'}</span><span className="block truncate font-mono text-xs" dir="ltr">{trackUrl}</span></span>
          <button type="button" onClick={() => copy('url', trackUrl)} className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft">{copied === 'url' ? <Check size={13} /> : <Copy size={13} />}{ar ? 'نسخ' : 'Copy'}</button>
        </div>
        <div className="rounded-xl bg-surface-secondary px-4 py-2.5 text-xs text-text-muted">{ar ? 'الخدمة' : 'Service'}: <span className="font-semibold text-text-secondary">{type}</span></div>
        <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-2.5 text-xs text-text-secondary">{ar ? 'تأكيد البريد الإلكتروني: بانتظار اعتماد خدمة البريد' : 'Email confirmation: Awaiting mail credentials'}</div>
      </div>

      <div className="mt-6 flex gap-3">
        <a href={trackUrl}><Button variant="secondary">{ar ? 'تتبع الطلب' : 'Track request'}</Button></a>
        <Link to="/"><Button variant="ghost">{ar ? 'الصفحة الرئيسية' : 'Home'}</Button></Link>
      </div>
    </div>
  )
}
