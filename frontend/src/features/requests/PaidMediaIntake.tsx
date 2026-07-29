import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, CheckCircle2, Copy, FileText, Megaphone, Paperclip, RotateCcw, X } from 'lucide-react'
import {
  deleteUploadFile, getRequestMeta, startUploadSession, submitRequest, uploadRequestFile,
  type RequestSubmitPayload,
} from './api'
import { ContactVerification } from './ContactVerification'
import {
  PAID_COPY, fieldsForNeeds, groupFields, type PaidFieldDef,
} from './paidMediaFields'
import {
  CUSTOM_REQUEST_KEY, mergedRequiredFields, servicesForKeys, servicesInCategory, usePaidMediaCatalog,
  type PaidService, type PaidServiceCatalog,
} from '@/features/paid-media/publicCatalog'
import { MultiSelectField, SelectField, TagInput, type Option } from '@/components/forms'
import { Button } from '@/components/ui/Button'
import { FormField, TextInput, TextareaField } from '@/components/ui/form'
import { controlClass } from '@/components/ui/Field'
import { DateField } from '@/components/ui/DateField'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

interface UploadFile {
  localId: string
  name: string
  size: number
  status: 'uploading' | 'done' | 'error'
  pct: number
  id?: number
  error?: string
  file?: File
}

interface Applicant {
  contact_name: string
  contact_email: string
  contact_phone: string
  company_name: string
}
const EMPTY_APPLICANT: Applicant = { contact_name: '', contact_email: '', contact_phone: '', company_name: '' }

type StepKey = 'services' | 'applicant' | 'brief' | 'tracking' | 'content' | 'review'

/** Parse `?services=a,b,c` into a clean key list (order preserved, blanks dropped). */
function parseServices(raw: string | null): string[] {
  return (raw ?? '').split(',').map((s) => s.trim()).filter(Boolean)
}

/**
 * Paid-media dynamic intake — activated by `?module=paid-media`. Services are preselected from
 * `?services=` (engine keys), shown as editable chips; the visible fields adapt to the deduped union
 * of the selected services' `needs`. All answers live in one state object so navigating back/next
 * never loses input.
 */
export function PaidMediaIntake() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const dir = ar ? 'rtl' : 'ltr'
  const Arrow = ar ? ArrowLeft : ArrowRight
  const copy = PAID_COPY[locale]
  const removeWord = ar ? 'إزالة' : 'Remove'

  const [searchParams] = useSearchParams()
  const catalogQuery = usePaidMediaCatalog()
  const catalog: PaidServiceCatalog | undefined = catalogQuery.data

  // The paid-media request type key (module === 'paid_media'), resolved from public meta.
  const metaQuery = useQuery({ queryKey: ['requests', 'meta'], queryFn: getRequestMeta })
  const paidType = metaQuery.data?.types.find((t) => t.module === 'paid_media')?.key ?? 'paid_media'

  // URL selections are the INITIAL state; the user edits from here (never re-picks from scratch).
  const [selectedKeys, setSelectedKeys] = useState<string[]>(() => parseServices(searchParams.get('services')))
  const [answers, setAnswers] = useState<Record<string, unknown>>({})
  const [customRequest, setCustomRequest] = useState('')
  const [serviceNotes, setServiceNotes] = useState<Record<string, string>>({})
  const [applicant, setApplicant] = useState<Applicant>(EMPTY_APPLICANT)
  const [currency, setCurrency] = useState('SAR')
  const [notes, setNotes] = useState('')
  const [step, setStep] = useState(0)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const setAnswer = (token: string, value: unknown) => setAnswers((a) => ({ ...a, [token]: value }))
  const setApp = <K extends keyof Applicant>(k: K, v: Applicant[K]) => setApplicant((p) => ({ ...p, [k]: v }))

  // Once the catalog loads, drop any `?services=` keys that aren't in the catalog (ignore unknowns).
  const prunedUnknowns = useRef(false)
  useEffect(() => {
    if (prunedUnknowns.current || !catalog) return
    prunedUnknowns.current = true
    const known = new Set(catalog.services.map((s) => s.key))
    setSelectedKeys((prev) => prev.filter((k) => known.has(k)))
  }, [catalog])

  const resolved: PaidService[] = useMemo(() => servicesForKeys(catalog, selectedKeys), [catalog, selectedKeys])
  const resolvedKeys = useMemo(() => resolved.map((s) => s.key), [resolved])
  const needs = useMemo(() => mergedRequiredFields(catalog, selectedKeys), [catalog, selectedKeys])
  const hasCustom = resolvedKeys.includes(CUSTOM_REQUEST_KEY)

  const briefFields = groupFields(needs, 'brief')
  const trackingFields = groupFields(needs, 'tracking')
  const contentFields = groupFields(needs, 'content').filter((f) => f.control !== 'files')
  const hasFileField = fieldsForNeeds(needs).some((f) => f.control === 'files')

  // Dynamic step list — group steps appear only when they carry at least one required field.
  const stepKeys: StepKey[] = [
    'services',
    'applicant',
    ...(briefFields.length ? (['brief'] as const) : []),
    ...(trackingFields.length ? (['tracking'] as const) : []),
    'content',
    'review',
  ]
  const clampedStep = Math.min(step, stepKeys.length - 1)
  const currentKey = stepKeys[clampedStep]

  const stepLabel: Record<StepKey, string> = {
    services: copy.stepServices, applicant: copy.stepApplicant, brief: copy.stepBrief,
    tracking: copy.stepTracking, content: copy.stepContent, review: copy.stepReview,
  }

  // ---- Attachments (upload token held in memory only) ----
  const [uploadToken, setUploadToken] = useState<string | null>(null)
  const [files, setFiles] = useState<UploadFile[]>([])
  const anyUploading = files.some((f) => f.status === 'uploading')
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
    const selected = Array.from(list)
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

  // Options for the "add / edit services" multi-select, grouped by taxonomy category (engine-fed).
  const serviceOptions: Option[] = useMemo(() => {
    if (!catalog) return []
    return catalog.categories.flatMap((c) =>
      servicesInCategory(catalog, c.key).map((s) => ({
        value: s.key,
        label_ar: s.label_ar,
        label_en: s.label_en,
        description: ar ? s.description_ar : s.description_en,
        icon: s.icon,
        group: ar ? c.label_ar : c.label_en,
      })),
    )
  }, [catalog, ar])

  const mutation = useMutation({ mutationFn: submitRequest })
  const apiError = mutation.isError ? toApiError(mutation.error) : null

  function validate(key: StepKey): boolean {
    const e: Record<string, string> = {}
    if (key === 'services') {
      if (resolvedKeys.length === 0) e.services = copy.errServices
      if (hasCustom && !customRequest.trim()) e.custom = copy.errCustom
    }
    if (key === 'applicant') {
      if (applicant.contact_name.trim().length < 2) e.contact_name = copy.errName
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(applicant.contact_email)) e.contact_email = copy.errEmail
      if (!/^\+[1-9]\d{7,14}$/.test(applicant.contact_phone.trim())) e.contact_phone = copy.errPhone
      if (applicant.company_name.trim().length < 2) e.company_name = copy.errCompany
    }
    if (key === 'content' && anyUploading) e.files = copy.waitUploads
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const next = () => { if (validate(currentKey)) setStep(Math.min(clampedStep + 1, stepKeys.length - 1)) }
  const back = () => setStep(Math.max(clampedStep - 1, 0))

  const removeService = (key: string) => setSelectedKeys((prev) => prev.filter((k) => k !== key))

  const servicesSummary = resolved.map((s) => (ar ? s.label_ar : s.label_en)).join(ar ? '، ' : ', ')

  const submit = () => {
    if (mutation.isPending || anyUploading || !contactVerified || resolvedKeys.length === 0) return
    if (hasCustom && !customRequest.trim()) { setStep(0); validate('services'); return }
    // Prune answers to the CURRENT needs so fields from a removed service never leak into the payload.
    const details: Record<string, unknown> = Object.fromEntries(Object.entries(answers).filter(([k]) => needs.includes(k)))
    if (hasCustom) details[CUSTOM_REQUEST_KEY] = customRequest.trim()
    const svcNotes = Object.fromEntries(Object.entries(serviceNotes).filter(([k, v]) => resolvedKeys.includes(k) && v.trim()))
    const budgetRaw = answers.budget
    const platforms = (answers.platforms as string[] | undefined) ?? undefined
    const payload: RequestSubmitPayload = {
      type: paidType,
      contact_name: applicant.contact_name.trim(),
      contact_email: applicant.contact_email.trim(),
      contact_phone: applicant.contact_phone || undefined,
      company_name: applicant.company_name || undefined,
      objective: servicesSummary || undefined,
      budget: budgetRaw ? Number(budgetRaw) : undefined,
      currency,
      priority: 'medium',
      services: resolvedKeys,
      service_details: details,
      metadata: {
        locale,
        notes: notes || undefined,
        platforms,
        services: resolvedKeys,
        service_details: details,
        service_notes: Object.keys(svcNotes).length ? svcNotes : undefined,
      },
      upload_token: uploadToken ?? undefined,
      website: '',
      phone_verification_id: verifiedIds.phone,
      email_verification_id: verifiedIds.email,
    }
    mutation.mutate(payload)
  }

  if (mutation.isSuccess && mutation.data) {
    const r = mutation.data
    const trackUrl = `${window.location.origin}/requests/track?token=${r.tracking_token}`
    return <PaidSuccessView reference={r.reference} type={r.type} trackUrl={trackUrl} ar={ar} dir={dir} />
  }

  // ---- Field renderer: one control per needs token ----
  function renderField(f: PaidFieldDef) {
    const label = ar ? f.labelAr : f.labelEn
    const hint = ar ? f.hintAr : f.hintEn
    const opts: Option[] = (f.options ?? []).map((o) => ({ value: o.value, label_ar: o.ar, label_en: o.en }))
    switch (f.control) {
      case 'text':
      case 'url':
        return (
          <TextInput
            key={f.token} label={label} hint={hint}
            type={f.control === 'url' ? 'url' : 'text'} dir={f.control === 'url' ? 'ltr' : undefined}
            value={(answers[f.token] as string) ?? ''} onChange={(e) => setAnswer(f.token, e.target.value)}
          />
        )
      case 'textarea':
        return (
          <TextareaField
            key={f.token} label={label} hint={hint} maxLength={1500}
            value={(answers[f.token] as string) ?? ''} onChange={(e) => setAnswer(f.token, e.target.value)}
          />
        )
      case 'budget':
        return (
          <div key={f.token} className="grid gap-4 sm:grid-cols-2">
            <TextInput
              label={label} inputMode="decimal" dir="ltr"
              value={(answers[f.token] as string) ?? ''}
              onChange={(e) => setAnswer(f.token, e.target.value.replace(/[^\d.]/g, ''))}
            />
            <FormField label={copy.currency}>
              <select className={controlClass} value={currency} onChange={(e) => setCurrency(e.target.value)}>
                {['SAR', 'AED', 'USD', 'EGP'].map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </FormField>
          </div>
        )
      case 'select':
        return (
          <SelectField
            key={f.token} label={label} hint={hint} options={opts}
            value={(answers[f.token] as string) ?? null} onChange={(v) => setAnswer(f.token, v ?? undefined)}
          />
        )
      case 'multi':
        return (
          <MultiSelectField
            key={f.token} label={label} hint={hint} options={opts}
            value={(answers[f.token] as string[]) ?? []} onChange={(v) => setAnswer(f.token, v)}
          />
        )
      case 'tags':
        return (
          <TagInput
            key={f.token} label={label} hint={hint}
            value={(answers[f.token] as string[]) ?? []} onChange={(v) => setAnswer(f.token, v)}
          />
        )
      case 'date':
        return (
          <FormField key={f.token} label={label} hint={hint}>
            <DateField value={(answers[f.token] as string) ?? ''} onChange={(v) => setAnswer(f.token, v)} />
          </FormField>
        )
      case 'datetime':
        return (
          <FormField key={f.token} label={label} hint={hint}>
            <DateField withTime value={(answers[f.token] as string) ?? ''} onChange={(v) => setAnswer(f.token, v)} />
          </FormField>
        )
      case 'files':
        return null // rendered by the shared attachments block
    }
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
        <h1 className="font-heading text-2xl font-extrabold sm:text-3xl">{copy.heading}</h1>
        <p className="mt-1.5 text-sm text-text-secondary">{copy.intro}</p>

        {/* Stepper */}
        <ol className="mt-6 flex flex-wrap gap-x-2 gap-y-1.5 text-xs font-semibold">
          {stepKeys.map((k, i) => (
            <li key={k} className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 ${i === clampedStep ? 'bg-brand-primary-soft text-brand-700' : i < clampedStep ? 'text-brand-600' : 'text-text-muted'}`}>
              <span className={`tnum flex h-5 w-5 items-center justify-center rounded-full text-[11px] ${i <= clampedStep ? 'bg-brand-600 text-white' : 'bg-surface-secondary'}`}>{i < clampedStep ? <Check size={12} /> : i + 1}</span>
              {stepLabel[k]}
            </li>
          ))}
        </ol>

        <div className="mt-6 rounded-2xl border border-border bg-surface p-5 sm:p-6">
          {/* ---- Services ---- */}
          {currentKey === 'services' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between gap-3">
                <h2 className="text-base font-bold text-text-primary">{copy.servicesTitle}</h2>
                <span className="tnum rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary">{copy.selectedCount}: {resolvedKeys.length}</span>
              </div>

              {catalogQuery.isLoading && <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />}

              {/* Catalog failure: honest error + retry. NEVER a static/demo fallback list. */}
              {catalogQuery.isError && (
                <div role="alert" className="flex flex-col items-start gap-3 rounded-xl border border-danger/30 bg-[var(--negative-background)] p-4">
                  <p className="text-sm font-semibold text-danger">{copy.loadError}</p>
                  <Button variant="secondary" onClick={() => void catalogQuery.refetch()}>
                    <RotateCcw size={15} className="me-1.5" /> {copy.retry}
                  </Button>
                </div>
              )}

              {!catalogQuery.isLoading && !catalogQuery.isError && (
                <>
                  {resolved.length === 0 && (
                    <p className="rounded-lg bg-surface-secondary px-3 py-2.5 text-sm text-text-muted">{copy.servicesEmpty}</p>
                  )}

                  <ul className="space-y-2.5">
                    {resolved.map((s) => (
                      <li key={s.key} className="rounded-xl border border-border bg-surface p-3">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <div className="text-sm font-bold text-text-primary">{ar ? s.label_ar : s.label_en}</div>
                            {(ar ? s.description_ar : s.description_en) && (
                              <div className="mt-0.5 text-xs text-text-muted">{ar ? s.description_ar : s.description_en}</div>
                            )}
                          </div>
                          <button
                            type="button" onClick={() => removeService(s.key)} aria-label={`${removeWord} ${ar ? s.label_ar : s.label_en}`}
                            className="shrink-0 rounded-md p-1 text-text-muted hover:bg-surface-hover hover:text-danger"
                          >
                            <X size={16} />
                          </button>
                        </div>
                        <input
                          className={`${controlClass} mt-2.5 min-h-[44px] py-2 text-sm`} placeholder={copy.perServiceNote}
                          value={serviceNotes[s.key] ?? ''} onChange={(e) => setServiceNotes((p) => ({ ...p, [s.key]: e.target.value }))}
                        />
                      </li>
                    ))}
                  </ul>

                  {/* Add-only picker (value stays empty; picked services surface as the cards above, never
                      duplicated as trigger chips). Removal is via each card's × control. */}
                  <MultiSelectField
                    label={copy.editServices}
                    options={serviceOptions.filter((o) => !selectedKeys.includes(o.value))}
                    value={[]}
                    onChange={(vals) => setSelectedKeys((prev) => Array.from(new Set([...prev, ...vals])))}
                    placeholder={copy.editServices} searchable bulkActions={false}
                  />
                  {errors.services && <p className="text-sm text-danger">{errors.services}</p>}

                  {hasCustom && (
                    <div className="rounded-xl border border-brand-400/40 bg-brand-primary-soft/40 p-3">
                      <h3 className="mb-2 text-sm font-bold text-text-primary">{copy.customTitle}</h3>
                      <TextareaField
                        label={copy.customLabel} required maxLength={2000}
                        value={customRequest} onChange={(e) => setCustomRequest(e.target.value)}
                        error={errors.custom}
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {/* ---- Applicant ---- */}
          {currentKey === 'applicant' && (
            <div className="space-y-4">
              <h2 className="text-base font-bold text-text-primary">{copy.applicantTitle}</h2>
              <TextInput label={copy.name} value={applicant.contact_name} onChange={(e) => setApp('contact_name', e.target.value)} required error={errors.contact_name} />
              <TextInput label={copy.email} type="email" value={applicant.contact_email} onChange={(e) => setApp('contact_email', e.target.value)} required error={errors.contact_email} />
              <div className="grid gap-4 sm:grid-cols-2">
                <TextInput label={copy.phone} value={applicant.contact_phone} onChange={(e) => setApp('contact_phone', e.target.value)} inputMode="tel" dir="ltr" placeholder="+9665XXXXXXXX" required error={errors.contact_phone} />
                <TextInput label={copy.company} value={applicant.company_name} onChange={(e) => setApp('company_name', e.target.value)} required error={errors.company_name} />
              </div>
            </div>
          )}

          {/* ---- Brief ---- */}
          {currentKey === 'brief' && (
            <div className="space-y-4">
              <h2 className="text-base font-bold text-text-primary">{copy.briefTitle}</h2>
              {briefFields.map(renderField)}
            </div>
          )}

          {/* ---- Tracking ---- */}
          {currentKey === 'tracking' && (
            <div className="space-y-4">
              <h2 className="text-base font-bold text-text-primary">{copy.trackingTitle}</h2>
              {trackingFields.map(renderField)}
            </div>
          )}

          {/* ---- Content, files & notes ---- */}
          {currentKey === 'content' && (
            <div className="space-y-4">
              <h2 className="text-base font-bold text-text-primary">{copy.contentTitle}</h2>
              {contentFields.map(renderField)}

              {(hasFileField || true) && (
                <div className="space-y-2">
                  <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border bg-surface-secondary px-4 py-6 text-center hover:border-brand-400">
                    <Paperclip size={22} className="text-text-muted" />
                    <span className="text-sm font-semibold text-text-secondary">{copy.chooseFiles}</span>
                    <span className="text-xs text-text-muted">{copy.filesHint}</span>
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
                </div>
              )}

              <TextareaField label={copy.notes} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} />
            </div>
          )}

          {/* ---- Review ---- */}
          {currentKey === 'review' && (
            <div className="space-y-3">
              <h2 className="text-base font-bold text-text-primary">{copy.reviewTitle}</h2>
              <dl className="divide-y divide-border rounded-xl border border-border">
                <Row k={copy.services} v={servicesSummary || copy.none} />
                {hasCustom && customRequest.trim() && <Row k={copy.customTitle} v={customRequest.trim()} />}
                <Row k={copy.name} v={applicant.contact_name} />
                <Row k={copy.email} v={applicant.contact_email} />
                {applicant.company_name && <Row k={copy.company} v={applicant.company_name} />}
                {fieldsForNeeds(needs).filter((f) => f.control !== 'files').map((f) => {
                  const raw = answers[f.token]
                  const val = Array.isArray(raw) ? raw.join(ar ? '، ' : ', ') : (raw as string | undefined)
                  if (!val) return null
                  return <Row key={f.token} k={ar ? f.labelAr : f.labelEn} v={String(val)} />
                })}
                {answers.budget ? <Row k={ar ? 'الميزانية' : 'Budget'} v={`${answers.budget as string} ${currency}`} /> : null}
                <Row k={copy.attachments} v={files.filter((f) => f.status === 'done').length ? files.filter((f) => f.status === 'done').map((f) => f.name).join(ar ? '، ' : ', ') : copy.none} />
              </dl>

              <div className="rounded-xl border border-border bg-surface-secondary p-3">
                <h3 className="text-base font-bold text-text-primary">{copy.verifyTitle}</h3>
                <div className="mt-2">
                  <ContactVerification phone={applicant.contact_phone.trim()} email={applicant.contact_email.trim()} ar={ar} onChange={(ids) => setVerifiedIds((p) => ({ ...p, ...ids }))} />
                </div>
              </div>

              {apiError && (
                <div className="rounded-lg bg-[var(--negative-background)] px-4 py-3 text-sm text-danger" role="alert">
                  <p>{apiError.message}</p>
                  {apiError.errors && (
                    <ul className="mt-1 list-disc ps-5">
                      {Object.entries(apiError.errors).map(([k, msgs]) => <li key={k}>{k}: {(msgs as string[]).join(', ')}</li>)}
                    </ul>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Nav */}
          <div className="mt-6 flex items-center justify-between border-t border-border pt-4">
            <Button variant="ghost" onClick={back} disabled={clampedStep === 0}>{copy.back}</Button>
            {currentKey !== 'review' ? (
              <Button onClick={next} disabled={currentKey === 'content' && anyUploading}>{copy.next} <Arrow size={15} className="ms-1.5" /></Button>
            ) : (
              <Button onClick={submit} loading={mutation.isPending} disabled={anyUploading || !contactVerified || resolvedKeys.length === 0}>{copy.submit}</Button>
            )}
          </div>
        </div>

        <div className="mt-6">
          <Link to="/" className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {ar ? 'العودة للصفحة الرئيسية' : 'Back to home'}</Link>
        </div>
      </main>
    </div>
  )
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex gap-3 px-4 py-2.5 text-sm">
      <dt className="w-32 shrink-0 text-text-muted">{k}</dt>
      <dd className="min-w-0 flex-1 break-words font-medium text-text-primary">{v}</dd>
    </div>
  )
}

function PaidSuccessView({ reference, type, trackUrl, ar, dir }: { reference: string; type: string; trackUrl: string; ar: boolean; dir: string }) {
  const [copied, setCopied] = useState<'ref' | 'url' | null>(null)
  const copyTo = (which: 'ref' | 'url', value: string) => {
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
          <button type="button" onClick={() => copyTo('ref', reference)} className="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft">{copied === 'ref' ? <Check size={13} /> : <Copy size={13} />}{ar ? 'نسخ' : 'Copy'}</button>
        </div>
        <div className="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface px-4 py-3">
          <span className="min-w-0"><span className="block text-xs text-text-muted">{ar ? 'رابط التتبع' : 'Tracking link'}</span><span className="block truncate font-mono text-xs" dir="ltr">{trackUrl}</span></span>
          <button type="button" onClick={() => copyTo('url', trackUrl)} className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft">{copied === 'url' ? <Check size={13} /> : <Copy size={13} />}{ar ? 'نسخ' : 'Copy'}</button>
        </div>
        <div className="rounded-xl bg-surface-secondary px-4 py-2.5 text-xs text-text-muted">{ar ? 'الخدمة' : 'Service'}: <span className="font-semibold text-text-secondary">{type}</span></div>
      </div>
      <div className="mt-6 flex gap-3">
        <a href={trackUrl}><Button variant="secondary">{ar ? 'تتبع الطلب' : 'Track request'}</Button></a>
        <Link to="/"><Button variant="ghost">{ar ? 'الصفحة الرئيسية' : 'Home'}</Button></Link>
      </div>
    </div>
  )
}
