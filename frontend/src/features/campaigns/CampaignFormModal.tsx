import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createCampaign, updateCampaign, type CampaignInput } from './api'
import type { UnifiedCampaign } from './types'
import { alertLabel, deriveKpi, funnelLabel, kpiLabel, templateLabel } from './objectiveKpi'
import { listUsers } from '@/features/projects/api'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { DateField } from '@/components/ui/DateField'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { CreatableSelect, ErrorSummary, MultiSelectField, SelectField, useFormDraft, type FieldError } from '@/components/forms'
import { createOptionFromDraft, flattenOptions, useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'

const CURRENCIES = ['SAR', 'USD', 'AED', 'EGP', 'KWD', 'BHD', 'QAR']

// Common attribution choices across ad platforms (stored as strings; backend allows free-form up to 60 chars).
const ATTRIBUTION_MODELS = [
  { value: 'last_click', ar: 'آخر نقرة', en: 'Last click' },
  { value: 'data_driven', ar: 'مبني على البيانات', en: 'Data-driven' },
  { value: 'first_click', ar: 'أول نقرة', en: 'First click' },
  { value: 'linear', ar: 'خطّي', en: 'Linear' },
]
const ATTRIBUTION_WINDOWS = ['1d_click', '7d_click', '7d_click_1d_view', '28d_click', '28d_click_1d_view']

const CF_COPY = {
  ar: {
    platforms: 'المنصات', regions: 'المناطق', audiences: 'الجماهير', conversionEvents: 'أحداث التحويل',
    creativeTypes: 'أنواع المحتوى الإبداعي', tags: 'الوسوم',
    attributionModel: 'نموذج الإسناد', attributionWindow: 'نافذة الإسناد',
    derivedTitle: 'مؤشرات مشتقة من الهدف', primaryKpi: 'المؤشر الأساسي', secondaryKpi: 'مؤشرات ثانوية',
    funnel: 'مرحلة المسار', template: 'قالب التقرير', alerts: 'تنبيهات مقترحة',
    errTitle: 'يرجى تصحيح الأخطاء التالية',
  },
  en: {
    platforms: 'Platforms', regions: 'Regions', audiences: 'Audiences', conversionEvents: 'Conversion events',
    creativeTypes: 'Creative types', tags: 'Tags',
    attributionModel: 'Attribution model', attributionWindow: 'Attribution window',
    derivedTitle: 'KPIs derived from the objective', primaryKpi: 'Primary KPI', secondaryKpi: 'Secondary KPIs',
    funnel: 'Funnel stage', template: 'Report template', alerts: 'Suggested alerts',
    errTitle: 'Please fix the following errors',
  },
} as const

/** RHF field name → input id, so the ErrorSummary's click-to-focus lands on the right control. */
const FIELD_IDS: Record<string, string> = {
  name: 'campaign-name', total_budget: 'campaign-budget', budget_currency: 'campaign-currency',
  starts_on: 'campaign-start', ends_on: 'campaign-end', owner_id: 'campaign-owner', audience: 'campaign-audience',
}

interface Props {
  open: boolean
  onClose: () => void
  projectId: string
  campaign?: UnifiedCampaign | null
}

export function CampaignFormModal({ open, onClose, projectId, campaign }: Props) {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const c = CF_COPY[locale]
  const queryClient = useQueryClient()
  const hasPermission = useAuth((s) => s.hasPermission)
  const canCreate = hasPermission('options.create')
  const isEdit = Boolean(campaign)

  const schema = useMemo(
    () =>
      z
        .object({
          name: z.string().trim().min(1, t('error')).max(160),
          total_budget: z
            .string()
            .refine((v) => v === '' || (!Number.isNaN(Number(v)) && Number(v) >= 0), t('error')),
          budget_currency: z.string().length(3),
          starts_on: z.string(),
          ends_on: z.string(),
          audience: z.string(),
          owner_id: z.string(),
          attribution_model: z.string(),
          attribution_window: z.string(),
        })
        .refine((d) => !d.starts_on || !d.ends_on || d.ends_on >= d.starts_on, {
          path: ['ends_on'],
          message: t('error'),
        }),
    [t],
  )

  type FormValues = z.infer<typeof schema>

  const defaults: FormValues = useMemo(
    () => ({
      name: campaign?.name ?? '',
      total_budget: campaign?.total_budget != null ? String(campaign.total_budget) : '',
      budget_currency: campaign?.budget_currency ?? 'SAR',
      starts_on: campaign?.starts_on ?? '',
      ends_on: campaign?.ends_on ?? '',
      audience: campaign?.audience ?? '',
      owner_id: campaign?.owner_id != null ? String(campaign.owner_id) : '',
      attribution_model: campaign?.attribution_model ?? '',
      attribution_window: campaign?.attribution_window ?? '',
    }),
    [campaign],
  )

  const {
    register,
    handleSubmit,
    reset,
    setError,
    watch,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults })

  // Draft autosave for the new-campaign flow (create only) — survives an accidental close/refresh.
  const draft = useFormDraft<FormValues>(`campaign:new:${projectId}`, defaults)

  // Taxonomy-driven controlled fields (kept out of RHF; they submit arrays / keys).
  const [objective, setObjective] = useState<string | null>(null)
  const [platforms, setPlatforms] = useState<string[]>([])
  const [regions, setRegions] = useState<string[]>([])
  const [audiences, setAudiences] = useState<string[]>([])
  const [conversionEvents, setConversionEvents] = useState<string[]>([])
  const [creativeTypes, setCreativeTypes] = useState<string[]>([])
  const [tags, setTags] = useState<string[]>([])

  const objectiveTax = useTaxonomyOptions('campaign.objective')
  const platformsTax = useTaxonomyOptions('campaign.platforms')
  const regionsTax = useTaxonomyOptions('campaign.regions')
  const audiencesTax = useTaxonomyOptions('campaign.audiences')
  const conversionEventsTax = useTaxonomyOptions('campaign.conversion_events')
  const creativeTypesTax = useTaxonomyOptions('campaign.creative_types')
  const tagsTax = useTaxonomyOptions('campaign.tags')

  // Reset every field when the modal opens or the edited campaign changes. Only `regions` round-trips from the
  // API (the read resource does not expose the other arrays yet), so the rest reset to empty.
  useEffect(() => {
    if (!open) return
    // Create mode restores any autosaved draft; edit mode always mirrors the persisted campaign.
    reset(isEdit ? defaults : { ...defaults, ...draft.value })
    setObjective(campaign?.objective ?? 'other')
    setRegions(campaign?.regions ?? [])
    setPlatforms([])
    setAudiences([])
    setConversionEvents([])
    setCreativeTypes([])
    setTags([])
    // draft.value is intentionally read once on open, not tracked, to avoid clobbering live edits.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, defaults, reset, campaign, isEdit])

  // Persist new-campaign field changes to the draft (create only); cleared on a successful submit.
  useEffect(() => {
    if (!open || isEdit) return
    const sub = watch((values) => draft.setValue(values as FormValues))
    return () => sub.unsubscribe()
  }, [open, isEdit, watch, draft])

  const users = useQuery({ queryKey: ['users'], queryFn: () => listUsers(), enabled: open })

  // Derive the KPI set / funnel / template / alerts from the selected objective's engine metadata.
  const derived = useMemo(() => {
    const row = flattenOptions(objectiveTax.data ?? []).find((o) => o.key === objective)
    return deriveKpi(row?.metadata)
  }, [objectiveTax.data, objective])

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CampaignInput = {
        name: values.name,
        objective: objective ?? undefined,
        total_budget: values.total_budget === '' ? null : Number(values.total_budget),
        budget_currency: values.budget_currency,
        starts_on: values.starts_on || null,
        ends_on: values.ends_on || null,
        audience: values.audience || null,
        owner_id: values.owner_id === '' ? null : Number(values.owner_id),
        attribution_model: values.attribution_model || null,
        attribution_window: values.attribution_window || null,
        target_kpi: derived.primary
          ? { primary: derived.primary, secondary: derived.secondary, funnel: derived.funnel, template: derived.template }
          : null,
        regions, // regions round-trips, so send it always (incl. empty to clear)
      }
      // The read resource does not echo these arrays, so only send them when set — never clobber existing values.
      if (platforms.length > 0) payload.platforms = platforms
      if (audiences.length > 0) payload.audiences = audiences
      if (conversionEvents.length > 0) payload.conversion_events = conversionEvents
      if (creativeTypes.length > 0) payload.creative_types = creativeTypes
      if (tags.length > 0) payload.tags = tags
      return isEdit ? updateCampaign(projectId, campaign!.id, payload) : createCampaign(projectId, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
      if (!isEdit) draft.clear()
      reset(defaults)
      onClose()
    },
    onError: (err) => {
      const apiError = toApiError(err)
      if (apiError.errors) {
        for (const [field, messages] of Object.entries(apiError.errors)) {
          if (field in defaults) setError(field as keyof FormValues, { message: messages[0] })
        }
      }
    },
  })

  const apiError = mutation.isError ? toApiError(mutation.error) : null
  const summaryErrors: FieldError[] = Object.entries(errors).flatMap(([field, e]) =>
    e?.message ? [{ field: FIELD_IDS[field] ?? field, message: String(e.message) }] : [],
  )

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('edit_campaign') : t('new_campaign')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button loading={mutation.isPending} onClick={handleSubmit((v) => mutation.mutate(v))}>
            {t('save')}
          </Button>
        </>
      }
    >
      <form className="space-y-3" onSubmit={handleSubmit((v) => mutation.mutate(v))}>
        {summaryErrors.length > 0 && <ErrorSummary errors={summaryErrors} title={c.errTitle} />}
        {apiError && !apiError.errors && <Alert severity="danger" title={apiError.message} />}

        <Field label={t('campaign_name')} htmlFor="campaign-name" required error={errors.name?.message}>
          <Input id="campaign-name" {...register('name')} data-autofocus />
        </Field>

        <SelectField
          label={t('objective_field')}
          value={objective}
          onChange={setObjective}
          options={objectiveTax.options}
          loading={objectiveTax.isPending}
          optionsError={objectiveTax.isError ? t('error') : null}
          onRetry={() => objectiveTax.refetch()}
          clearable={false}
        />

        {derived.primary && (
          <div className="rounded-xl border border-border bg-surface-secondary p-3 text-xs">
            <div className="mb-2 font-semibold text-text-secondary">{c.derivedTitle}</div>
            <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div>
                <dt className="text-text-muted">{c.primaryKpi}</dt>
                <dd className="font-semibold text-brand-700">{kpiLabel(derived.primary, locale)}</dd>
              </div>
              {derived.secondary.length > 0 && (
                <div>
                  <dt className="text-text-muted">{c.secondaryKpi}</dt>
                  <dd className="font-medium text-text-primary">{derived.secondary.map((k) => kpiLabel(k, locale)).join('، ')}</dd>
                </div>
              )}
              {derived.funnel && (
                <div>
                  <dt className="text-text-muted">{c.funnel}</dt>
                  <dd className="font-medium text-text-primary">{funnelLabel(derived.funnel, locale)}</dd>
                </div>
              )}
              {derived.template && (
                <div>
                  <dt className="text-text-muted">{c.template}</dt>
                  <dd className="font-medium text-text-primary">{templateLabel(derived.template, locale)}</dd>
                </div>
              )}
            </dl>
            {derived.alerts.length > 0 && (
              <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <span className="text-text-muted">{c.alerts}:</span>
                {derived.alerts.map((a) => (
                  <span key={a} className="rounded-full bg-warning/15 px-2 py-0.5 font-medium text-warning">{alertLabel(a, locale)}</span>
                ))}
              </div>
            )}
          </div>
        )}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('owner_label')} htmlFor="campaign-owner">
            <Select
              id="campaign-owner"
              {...register('owner_id')}
              placeholder="—"
              options={(users.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))}
            />
          </Field>
          <Field label={t('currency_label')} htmlFor="campaign-currency" error={errors.budget_currency?.message}>
            <Select id="campaign-currency" {...register('budget_currency')} options={CURRENCIES.map((cur) => ({ value: cur, label: cur }))} />
          </Field>
        </div>

        {/* Attribution governance — model + window, stored on the campaign (backend-validated). */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={c.attributionModel} htmlFor="campaign-attr-model">
            <Select id="campaign-attr-model" {...register('attribution_model')} placeholder="—"
              options={ATTRIBUTION_MODELS.map((m) => ({ value: m.value, label: locale === 'ar' ? m.ar : m.en }))} />
          </Field>
          <Field label={c.attributionWindow} htmlFor="campaign-attr-window">
            <Select id="campaign-attr-window" {...register('attribution_window')} placeholder="—"
              options={ATTRIBUTION_WINDOWS.map((w) => ({ value: w, label: w }))} />
          </Field>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('budget_label')} htmlFor="campaign-budget" error={errors.total_budget?.message}>
            <Input id="campaign-budget" type="number" min="0" step="0.01" {...register('total_budget')} />
          </Field>
          <Field label={t('start_date')} htmlFor="campaign-start" error={errors.starts_on?.message}>
            <DateField id="campaign-start" value={watch('starts_on') ?? ''} onChange={(v) => setValue('starts_on', v, { shouldValidate: true, shouldDirty: true })} />
          </Field>
        </div>

        <Field label={t('end_date')} htmlFor="campaign-end" error={errors.ends_on?.message}>
          <DateField id="campaign-end" value={watch('ends_on') ?? ''} onChange={(v) => setValue('ends_on', v, { shouldValidate: true, shouldDirty: true })} />
        </Field>

        <TaxonomyMultiSelect label={c.platforms} defKey="campaign.platforms" value={platforms} onChange={setPlatforms} tax={platformsTax} canCreate={canCreate} errText={t('error')} />
        <TaxonomyMultiSelect label={c.creativeTypes} defKey="campaign.creative_types" value={creativeTypes} onChange={setCreativeTypes} tax={creativeTypesTax} canCreate={canCreate} errText={t('error')} />
        <TaxonomyMultiSelect label={c.regions} defKey="campaign.regions" value={regions} onChange={setRegions} tax={regionsTax} canCreate={canCreate} errText={t('error')} />
        <TaxonomyMultiSelect label={c.audiences} defKey="campaign.audiences" value={audiences} onChange={setAudiences} tax={audiencesTax} canCreate={canCreate} errText={t('error')} />
        <TaxonomyMultiSelect label={c.conversionEvents} defKey="campaign.conversion_events" value={conversionEvents} onChange={setConversionEvents} tax={conversionEventsTax} canCreate={canCreate} errText={t('error')} />
        <TaxonomyMultiSelect label={c.tags} defKey="campaign.tags" value={tags} onChange={setTags} tax={tagsTax} canCreate={canCreate} errText={t('error')} />

        <Field label={t('audience_label')} htmlFor="campaign-audience">
          <Textarea id="campaign-audience" rows={3} {...register('audience')} />
        </Field>
      </form>
    </Modal>
  )
}

/** A taxonomy-backed multi-select: creatable (drawer → createOption) when permitted, plain otherwise. */
function TaxonomyMultiSelect({
  label, defKey, value, onChange, tax, canCreate, errText,
}: {
  label: string
  defKey: string
  value: string[]
  onChange: (v: string[]) => void
  tax: ReturnType<typeof useTaxonomyOptions>
  canCreate: boolean
  errText: string
}) {
  const shared = {
    label,
    value,
    onChange,
    options: tax.options,
    loading: tax.isPending,
    optionsError: tax.isError ? errText : null,
    onRetry: () => tax.refetch(),
  }
  return canCreate ? (
    <CreatableSelect mode="multi" {...shared} onCreate={(draft) => createOptionFromDraft(defKey, draft)} />
  ) : (
    <MultiSelectField {...shared} />
  )
}
