import { useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { isAxiosError } from 'axios'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageIntro } from '@/components/ui/PageIntro'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { listUsers } from '@/features/projects/api'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { useForm, useFormAction, useUpdateForm } from './api'
import {
  FIELD_TYPES, addField, addStep, earlierFields, mapFor, moveField, optionsToText, removeField, removeStep, retype, setLoc, textToOptions, updateField,
} from './builderState'
import { FormRenderer } from './FormRenderer'
import { pick } from './formLogic'
import type { ConditionOp, FormDefinition, FormField } from './types'

/**
 * FORMS-HUB-001 — the form builder: the definition on one side, the form as a visitor meets it on the other.
 *
 * Every edit is a pure function of the definition (`builderState.ts`); this screen wires them to inputs and
 * never holds a second copy of the form. Saving sends the whole definition and the SERVER normalises it — a
 * refusal comes back as text and is shown as it is, because the server knows which rule was broken.
 *
 * A published version never changes: saving after publishing starts the next draft, and the live form stays
 * on the old version until the new one is published.
 */
const TYPE_LABEL: Record<string, { ar: string; en: string }> = {
  text: { ar: 'نص قصير', en: 'Short text' }, textarea: { ar: 'نص طويل', en: 'Long text' }, email: { ar: 'بريد إلكتروني', en: 'Email' },
  phone: { ar: 'جوال', en: 'Mobile' }, number: { ar: 'رقم', en: 'Number' }, select: { ar: 'قائمة', en: 'Dropdown' },
  radio: { ar: 'اختيار واحد', en: 'Single choice' }, checkbox: { ar: 'اختيارات متعددة', en: 'Multiple choice' }, date: { ar: 'تاريخ', en: 'Date' },
}
const OP_LABEL: Record<ConditionOp, { ar: string; en: string }> = {
  equals: { ar: 'يساوي', en: 'equals' }, not_equals: { ar: 'لا يساوي', en: 'is not' }, in: { ar: 'أحد', en: 'is one of' }, filled: { ar: 'مكتمل', en: 'is filled' },
}
const input = 'w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm text-text-primary'

export function FormBuilderPage() {
  const { formId } = useParams()
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const base = useLocation().pathname.startsWith('/agency') ? '/agency' : '/app'
  const form = useForm(currentProjectId, formId)
  const update = useUpdateForm(currentProjectId, formId)
  const publish = useFormAction(currentProjectId, formId, 'publish')
  const archive = useFormAction(currentProjectId, formId, 'archive')
  const users = useQuery({ queryKey: ['tenant-users'], queryFn: listUsers, staleTime: 60_000 })

  const [name, setName] = useState('')
  const [definition, setDefinition] = useState<FormDefinition | null>(null)
  const [ownerId, setOwnerId] = useState<number | null>(null)
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)

  useEffect(() => {
    if (form.data && definition === null) {
      setName(form.data.name)
      setDefinition(form.data.definition)
      setOwnerId(form.data.default_owner_id)
    }
  }, [form.data, definition])

  if (form.isLoading || (form.isSuccess && definition === null)) return <Skeleton className="h-64" />
  if (form.isError || !form.data || definition === null) return <ErrorState title={ar ? 'تعذّر تحميل النموذج' : 'The form could not be loaded'} error={form.error} ar={ar} />

  const detail = form.data
  const edit = (next: FormDefinition) => { setDefinition(next); setMessage(null) }
  const failure = (e: unknown): string => (isAxiosError(e) && typeof e.response?.data?.message === 'string' ? e.response.data.message : (ar ? 'تعذّر الحفظ.' : 'Could not save.'))

  const save = () => update.mutate(
    { name, definition, default_owner_id: ownerId },
    { onSuccess: () => setMessage({ tone: 'ok', text: ar ? 'حُفظت المسودة.' : 'Draft saved.' }), onError: (e) => setMessage({ tone: 'error', text: failure(e) }) },
  )
  const saveAndPublish = () => update.mutate(
    { name, definition, default_owner_id: ownerId },
    {
      onSuccess: () => publish.mutate(undefined, {
        onSuccess: () => setMessage({ tone: 'ok', text: ar ? 'نُشر النموذج.' : 'Form published.' }),
        onError: (e) => setMessage({ tone: 'error', text: failure(e) }),
      }),
      onError: (e) => setMessage({ tone: 'error', text: failure(e) }),
    },
  )

  const busy = update.isPending || publish.isPending || archive.isPending

  return (
    <div className="space-y-6" data-testid="form-builder">
      <PageIntro
        title={name || (ar ? 'نموذج' : 'Form')}
        purpose={ar ? 'عدّل الحقول والمنطق والموافقة، وشاهد النموذج كما يراه الزائر قبل النشر.' : 'Edit the fields, logic and consent, and see the form as a visitor does before it goes live.'}
        actions={(
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={detail.status === 'published' ? 'success' : detail.status === 'archived' ? 'warning' : 'neutral'}>
              {{ draft: ar ? 'مسودة' : 'Draft', published: ar ? 'منشور' : 'Published', archived: ar ? 'مؤرشف' : 'Archived' }[detail.status]}
            </Badge>
            <Link to={`${base}/forms`} className="text-sm font-semibold text-text-secondary hover:text-brand-600">{ar ? 'كل النماذج' : 'All forms'}</Link>
          </div>
        )}
      />

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4">
          <Card className="space-y-3 p-4">
            <label className="block text-sm font-semibold text-text-primary" htmlFor="form-name">{ar ? 'اسم النموذج (داخلي)' : 'Form name (internal)'}</label>
            <input id="form-name" className={input} value={name} onChange={(e) => { setName(e.target.value); setMessage(null) }} data-testid="form-name" />
            <label className="block text-sm font-semibold text-text-primary" htmlFor="form-owner">{ar ? 'يُسند العميل المحتمل إلى' : 'Assign new leads to'}</label>
            <select id="form-owner" className={input} value={ownerId ?? ''} onChange={(e) => { setOwnerId(e.target.value === '' ? null : Number(e.target.value)); setMessage(null) }} data-testid="form-owner">
              <option value="">{ar ? 'بدون إسناد تلقائي' : 'No automatic assignment'}</option>
              {(users.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </Card>

          {definition.steps.map((step, si) => (
            <Card key={step.id} className="space-y-3 p-4" data-testid={`step-${si}`}>
              <div className="flex items-center gap-2">
                <input
                  className={input}
                  aria-label={ar ? 'عنوان الخطوة' : 'Step title'}
                  value={pick(step.title, ar)}
                  onChange={(e) => edit({ ...definition, steps: definition.steps.map((s, k) => (k === si ? { ...s, title: setLoc(s.title, ar ? 'ar' : 'en', e.target.value) } : s)) })}
                />
                {definition.steps.length > 1 && (
                  <button type="button" onClick={() => edit(removeStep(definition, si))} aria-label={ar ? 'حذف الخطوة' : 'Remove step'} className="text-text-muted hover:text-danger"><Trash2 size={16} aria-hidden /></button>
                )}
              </div>

              {step.fields.map((f, fi) => (
                <FieldEditor
                  key={f.id}
                  field={f}
                  ar={ar}
                  earlier={earlierFields(definition, si, fi)}
                  onChange={(patch) => edit(updateField(definition, si, fi, patch))}
                  onRetype={(t) => edit(retype(definition, si, fi, t))}
                  onMove={(by) => edit(moveField(definition, si, fi, by))}
                  onRemove={() => edit(removeField(definition, si, fi))}
                  testid={`field-editor-${si}-${fi}`}
                />
              ))}

              <div className="flex flex-wrap gap-1.5" data-testid={`add-field-${si}`}>
                {FIELD_TYPES.map((t) => (
                  <button key={t} type="button" onClick={() => edit(addField(definition, si, t))} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-border px-2 py-1 text-xs font-semibold text-text-secondary hover:border-brand-400" data-testid={`add-${si}-${t}`}>
                    <Plus size={12} aria-hidden /> {TYPE_LABEL[t][ar ? 'ar' : 'en']}
                  </button>
                ))}
              </div>
            </Card>
          ))}

          <Button variant="secondary" onClick={() => edit(addStep(definition))} data-testid="add-step"><Plus size={16} aria-hidden /> {ar ? 'خطوة جديدة' : 'Add step'}</Button>

          <Card className="space-y-3 p-4">
            <label className="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <input type="checkbox" checked={definition.consent.required} onChange={(e) => edit({ ...definition, consent: { ...definition.consent, required: e.target.checked } })} data-testid="consent-required" />
              {ar ? 'اطلب موافقة الزائر قبل الإرسال' : 'Ask for the visitor’s consent before sending'}
            </label>
            <textarea
              className={input}
              rows={3}
              aria-label={ar ? 'نص الموافقة' : 'Consent text'}
              value={pick(definition.consent.text, ar)}
              onChange={(e) => edit({ ...definition, consent: { ...definition.consent, text: setLoc(definition.consent.text, ar ? 'ar' : 'en', e.target.value) } })}
              data-testid="consent-text"
            />
            <textarea
              className={input}
              rows={2}
              aria-label={ar ? 'رسالة الشكر' : 'Thank-you message'}
              value={pick(definition.thank_you.message, ar)}
              onChange={(e) => edit({ ...definition, thank_you: { ...definition.thank_you, message: setLoc(definition.thank_you.message, ar ? 'ar' : 'en', e.target.value) } })}
              data-testid="thank-you-text"
            />
            <div className="flex items-center gap-3">
              <label className="text-sm font-semibold text-text-primary" htmlFor="accent">{ar ? 'اللون' : 'Accent'}</label>
              <input id="accent" type="color" value={definition.style.accent ?? '#2563eb'} onChange={(e) => edit({ ...definition, style: { ...definition.style, accent: e.target.value } })} />
            </div>
          </Card>

          {message && <p role={message.tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border p-3 text-sm ${message.tone === 'error' ? 'border-danger/40 bg-danger/10 text-danger' : 'border-border bg-surface text-text-primary'}`} data-testid="builder-message">{message.text}</p>}

          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" onClick={save} loading={update.isPending && !publish.isPending} disabled={busy} data-testid="form-save">{ar ? 'حفظ المسودة' : 'Save draft'}</Button>
            <Button onClick={saveAndPublish} loading={publish.isPending} disabled={busy} data-testid="form-publish">{ar ? 'حفظ ونشر' : 'Save & publish'}</Button>
            {detail.status !== 'archived' && (
              <Button variant="ghost" onClick={() => archive.mutate(undefined, { onSuccess: () => setMessage({ tone: 'ok', text: ar ? 'أُرشف النموذج ولم يعد يستقبل إرسالات.' : 'Form archived; it no longer accepts submissions.' }) })} disabled={busy} data-testid="form-archive">{ar ? 'أرشفة' : 'Archive'}</Button>
            )}
          </div>
          {detail.definition_published && detail.status === 'published' && (
            <p className="text-xs text-text-secondary">{ar ? `النسخة المنشورة: ${detail.definition_version}. أي تعديل يبدأ نسخة جديدة كمسودة.` : `Live version: ${detail.definition_version}. A change starts a new draft.`}</p>
          )}
        </div>

        <div className="lg:sticky lg:top-4 lg:self-start">
          <Card className="space-y-3 p-4" data-testid="form-preview">
            <p className="text-xs font-bold uppercase tracking-wide text-text-secondary">{ar ? 'معاينة' : 'Preview'}</p>
            <FormRenderer key={JSON.stringify(definition)} definition={definition} ar={ar} onSubmit={() => undefined} preview />
          </Card>
        </div>
      </div>
    </div>
  )
}

function FieldEditor({ field, ar, earlier, onChange, onRetype, onMove, onRemove, testid }: {
  field: FormField
  ar: boolean
  earlier: FormField[]
  onChange: (patch: Partial<FormField>) => void
  onRetype: (t: FormField['type']) => void
  onMove: (by: -1 | 1) => void
  onRemove: () => void
  testid: string
}) {
  const lang = ar ? 'ar' : 'en'
  const mapped = mapFor(field.type)
  const cond = field.show_if
  const target = earlier.find((f) => f.id === cond?.field)

  return (
    <div className="space-y-2 rounded-xl border border-border p-3" data-testid={testid}>
      <div className="flex items-center gap-2">
        <input className={input} aria-label={ar ? 'نص السؤال' : 'Question'} placeholder={ar ? 'نص السؤال' : 'Question'} value={pick(field.label, ar)} onChange={(e) => onChange({ label: setLoc(field.label, lang, e.target.value) })} data-testid={`${testid}-label`} />
        <select className="rounded-lg border border-border bg-surface px-2 py-1.5 text-sm" aria-label={ar ? 'نوع الحقل' : 'Field type'} value={field.type} onChange={(e) => onRetype(e.target.value as FormField['type'])} data-testid={`${testid}-type`}>
          {FIELD_TYPES.map((t) => <option key={t} value={t}>{TYPE_LABEL[t][lang]}</option>)}
        </select>
        <button type="button" onClick={() => onMove(-1)} aria-label={ar ? 'للأعلى' : 'Move up'} className="text-text-muted hover:text-text-primary"><ArrowUp size={16} aria-hidden /></button>
        <button type="button" onClick={() => onMove(1)} aria-label={ar ? 'للأسفل' : 'Move down'} className="text-text-muted hover:text-text-primary"><ArrowDown size={16} aria-hidden /></button>
        <button type="button" onClick={onRemove} aria-label={ar ? 'حذف الحقل' : 'Remove field'} className="text-text-muted hover:text-danger" data-testid={`${testid}-remove`}><Trash2 size={16} aria-hidden /></button>
      </div>

      <div className="flex flex-wrap items-center gap-3 text-sm">
        <label className="inline-flex items-center gap-1.5">
          <input type="checkbox" checked={Boolean(field.required) || Boolean(field.maps_to)} disabled={Boolean(field.maps_to)} onChange={(e) => onChange({ required: e.target.checked })} />
          {ar ? 'مطلوب' : 'Required'}
        </label>
        {mapped && (
          <label className="inline-flex items-center gap-1.5">
            <input type="checkbox" checked={field.maps_to === mapped} onChange={(e) => onChange({ maps_to: e.target.checked ? mapped : undefined, required: e.target.checked ? true : field.required })} data-testid={`${testid}-maps`} />
            {ar ? `يُحفظ كـ ${{ name: 'اسم العميل المحتمل', email: 'بريد العميل المحتمل', phone: 'جوال العميل المحتمل' }[mapped]}` : `Saved as the lead’s ${mapped}`}
          </label>
        )}
      </div>

      {field.options && (
        <textarea className={input} rows={3} aria-label={ar ? 'الخيارات (سطر لكل خيار: القيمة|الاسم)' : 'Options (one per line: value|label)'} value={optionsToText(field.options)} onChange={(e) => onChange({ options: textToOptions(e.target.value) })} data-testid={`${testid}-options`} />
      )}

      {(field.type === 'number' || field.type === 'text' || field.type === 'textarea') && (
        <div className="flex flex-wrap gap-2 text-xs">
          {(field.type === 'number' ? ['min', 'max'] : ['min_length', 'max_length']).map((k) => (
            <label key={k} className="inline-flex items-center gap-1">
              {{ min: ar ? 'الأدنى' : 'Min', max: ar ? 'الأقصى' : 'Max', min_length: ar ? 'أقل طول' : 'Min length', max_length: ar ? 'أقصى طول' : 'Max length' }[k]}
              <input
                type="number"
                className="w-20 rounded-lg border border-border bg-surface px-2 py-1"
                value={(field.validation as Record<string, number | undefined> | undefined)?.[k] ?? ''}
                onChange={(e) => onChange({ validation: { ...field.validation, [k]: e.target.value === '' ? undefined : Number(e.target.value) } })}
              />
            </label>
          ))}
        </div>
      )}

      {earlier.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-xs" data-testid={`${testid}-logic`}>
          <label className="inline-flex items-center gap-1.5">
            <input type="checkbox" checked={Boolean(cond)} onChange={(e) => onChange({ show_if: e.target.checked ? { field: earlier[earlier.length - 1].id, op: 'filled', value: null } : undefined })} data-testid={`${testid}-logic-on`} />
            {ar ? 'أظهره فقط عندما' : 'Show only when'}
          </label>
          {cond && (
            <>
              <select className="rounded-lg border border-border bg-surface px-2 py-1" aria-label={ar ? 'الحقل' : 'Field'} value={cond.field} onChange={(e) => onChange({ show_if: { ...cond, field: e.target.value } })}>
                {earlier.map((f) => <option key={f.id} value={f.id}>{pick(f.label, ar) || f.id}</option>)}
              </select>
              <select className="rounded-lg border border-border bg-surface px-2 py-1" aria-label={ar ? 'الشرط' : 'Condition'} value={cond.op} onChange={(e) => onChange({ show_if: { ...cond, op: e.target.value as ConditionOp, value: e.target.value === 'filled' ? null : cond.value } })}>
                {(Object.keys(OP_LABEL) as ConditionOp[]).map((o) => <option key={o} value={o}>{OP_LABEL[o][lang]}</option>)}
              </select>
              {cond.op !== 'filled' && (target?.options
                ? (
                  <select className="rounded-lg border border-border bg-surface px-2 py-1" aria-label={ar ? 'القيمة' : 'Value'} value={Array.isArray(cond.value) ? cond.value[0] ?? '' : cond.value ?? ''} onChange={(e) => onChange({ show_if: { ...cond, value: cond.op === 'in' ? [e.target.value] : e.target.value } })}>
                    <option value="" />
                    {target.options.map((o) => <option key={o.value} value={o.value}>{pick(o.label, ar)}</option>)}
                  </select>
                )
                : <input className="w-32 rounded-lg border border-border bg-surface px-2 py-1" aria-label={ar ? 'القيمة' : 'Value'} value={Array.isArray(cond.value) ? cond.value.join(',') : cond.value ?? ''} onChange={(e) => onChange({ show_if: { ...cond, value: cond.op === 'in' ? e.target.value.split(',').map((x) => x.trim()).filter(Boolean) : e.target.value } })} />)}
            </>
          )}
        </div>
      )}
    </div>
  )
}
