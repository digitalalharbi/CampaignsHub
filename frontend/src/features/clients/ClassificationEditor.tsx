import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getTaxonomy, updateClassification, type ClientClassification } from './api'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { CreatableSelect, MultiSelectField, SearchableSelect, SelectField } from '@/components/forms'
import { createOptionFromDraft, useTaxonomyOptions } from '@/features/taxonomy/taxonomyApi'

/** Bilingual copy kept local to the feature (never in the global i18n dictionary). */
const CE_COPY = {
  ar: { tags: 'الوسوم', enabledServices: 'الخدمات المفعّلة', tagsHint: 'ابحث أو أضف وسمًا', servicesHint: 'اختر الخدمات المقدَّمة لهذا العميل' },
  en: { tags: 'Tags', enabledServices: 'Enabled services', tagsHint: 'Search or add a tag', servicesHint: 'Services provided to this client' },
} as const

/** Inline classification editor — every field is a real, validated, persisted mutation (not display-only). */
export function ClassificationEditor({ clientId, current, onClose }: { clientId: string; current: ClientClassification; onClose: () => void }) {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const c = CE_COPY[lang]
  const qc = useQueryClient()
  const hasPermission = useAuth((s) => s.hasPermission)
  const canCreate = hasPermission('options.create')

  // Owners come from the client taxonomy meta (real users, not an enum); every enum select comes from the engine.
  const taxonomy = useQuery({ queryKey: ['app', 'clients', 'taxonomy'], queryFn: getTaxonomy, staleTime: 300_000 })
  const statusTax = useTaxonomyOptions('client.status')
  const serviceLevelTax = useTaxonomyOptions('client.service_level')
  const industryTax = useTaxonomyOptions('client.industry')
  const priorityTax = useTaxonomyOptions('client.priority')
  const tagsTax = useTaxonomyOptions('client.tags')
  const servicesTax = useTaxonomyOptions('request.service') // the agency's service catalogue → enabled_services

  const [form, setForm] = useState<ClientClassification>(current)
  const [tags, setTags] = useState<string[]>(current.tags ?? [])
  const [enabledServices, setEnabledServices] = useState<string[]>(current.enabled_services ?? [])

  const mutation = useMutation({
    mutationFn: () => {
      const patch: Partial<ClientClassification> = {
        client_status: form.client_status,
        service_level: form.service_level,
        industry: form.industry,
        owner_id: form.owner_id,
        priority: form.priority,
        default_currency: form.default_currency || null,
        timezone: form.timezone || null,
        language: form.language,
      }
      // The read-back omits these arrays, so only send them when the user actually set values (never clobber).
      if (tags.length > 0) patch.tags = tags
      if (enabledServices.length > 0) patch.enabled_services = enabledServices
      return updateClassification(clientId, patch)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['app', 'client', clientId] })
      qc.invalidateQueries({ queryKey: ['app', 'clients'] })
      onClose()
    },
  })

  const set = (p: Partial<ClientClassification>) => setForm((f) => ({ ...f, ...p }))
  const ownerOptions = (taxonomy.data?.assignable_users ?? []).map((u) => ({ value: String(u.id), label: u.name }))
  const field = 'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'

  return (
    <form onSubmit={(e) => { e.preventDefault(); mutation.mutate() }} className="mt-4 grid gap-3 rounded-xl border border-border bg-surface-secondary p-4 sm:grid-cols-2" aria-label={t('cc_classification')}>
      <SearchableSelect
        label={t('cc_filter_status')}
        value={form.client_status}
        onChange={(v) => set({ client_status: v })}
        options={statusTax.options}
        loading={statusTax.isPending}
        optionsError={statusTax.isError ? t('error_generic') : null}
        onRetry={() => statusTax.refetch()}
        clearable={false}
      />
      <SelectField
        label={t('service_level')}
        value={form.service_level}
        onChange={(v) => set({ service_level: v })}
        options={serviceLevelTax.options}
        loading={serviceLevelTax.isPending}
        optionsError={serviceLevelTax.isError ? t('error_generic') : null}
        onRetry={() => serviceLevelTax.refetch()}
      />
      <SearchableSelect
        label={t('industry')}
        value={form.industry}
        onChange={(v) => set({ industry: v })}
        options={industryTax.options}
        loading={industryTax.isPending}
        optionsError={industryTax.isError ? t('error_generic') : null}
        onRetry={() => industryTax.refetch()}
      />
      <SearchableSelect
        label={t('cc_owner')}
        value={form.owner_id != null ? String(form.owner_id) : null}
        onChange={(v) => set({ owner_id: v ? Number(v) : null })}
        options={ownerOptions}
        loading={taxonomy.isPending}
      />
      <SelectField
        label={t('cc_priority')}
        value={form.priority ?? 'normal'}
        onChange={(v) => set({ priority: v })}
        options={priorityTax.options}
        loading={priorityTax.isPending}
        optionsError={priorityTax.isError ? t('error_generic') : null}
        onRetry={() => priorityTax.refetch()}
        clearable={false}
      />
      <label className="text-xs font-semibold text-text-secondary">{t('cc_currency')}
        <input className={field} value={form.default_currency ?? ''} maxLength={3} placeholder="SAR" onChange={(e) => set({ default_currency: e.target.value.toUpperCase() })} />
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('cc_timezone')}
        <input className={field} value={form.timezone ?? ''} placeholder="Asia/Riyadh" onChange={(e) => set({ timezone: e.target.value })} />
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('cc_language')}
        <select className={field} value={form.language ?? 'ar'} onChange={(e) => set({ language: e.target.value })}>
          <option value="ar">العربية</option>
          <option value="en">English</option>
        </select>
      </label>

      <div className="sm:col-span-2">
        {canCreate ? (
          <CreatableSelect
            mode="multi"
            label={c.tags}
            hint={c.tagsHint}
            value={tags}
            onChange={setTags}
            options={tagsTax.options}
            loading={tagsTax.isPending}
            optionsError={tagsTax.isError ? t('error_generic') : null}
            onRetry={() => tagsTax.refetch()}
            onCreate={(draft) => createOptionFromDraft('client.tags', draft)}
          />
        ) : (
          <MultiSelectField
            label={c.tags}
            hint={c.tagsHint}
            value={tags}
            onChange={setTags}
            options={tagsTax.options}
            loading={tagsTax.isPending}
            optionsError={tagsTax.isError ? t('error_generic') : null}
            onRetry={() => tagsTax.refetch()}
          />
        )}
      </div>

      <div className="sm:col-span-2">
        <MultiSelectField
          label={c.enabledServices}
          hint={c.servicesHint}
          value={enabledServices}
          onChange={setEnabledServices}
          options={servicesTax.options}
          loading={servicesTax.isPending}
          optionsError={servicesTax.isError ? t('error_generic') : null}
          onRetry={() => servicesTax.refetch()}
        />
      </div>

      {mutation.isError && <p className="text-xs text-danger sm:col-span-2">{t('error_generic')}</p>}
      <div className="flex items-center gap-2 sm:col-span-2">
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">{t('cc_save')}</button>
        <button type="button" onClick={onClose} className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-secondary">{t('cc_cancel')}</button>
      </div>
    </form>
  )
}
