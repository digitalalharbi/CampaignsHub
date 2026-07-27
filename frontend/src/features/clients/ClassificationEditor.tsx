import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getTaxonomy, updateClassification, type ClientClassification } from './api'
import { CLIENT_STATUS_LABELS, INDUSTRY_LABELS, labelOf, PRIORITY_LABELS, SERVICE_LEVEL_LABELS } from './labels'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/** Inline classification editor — every field is a real, validated, persisted mutation (not display-only). */
export function ClassificationEditor({ clientId, current, onClose }: { clientId: string; current: ClientClassification; onClose: () => void }) {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const qc = useQueryClient()
  const taxonomy = useQuery({ queryKey: ['app', 'clients', 'taxonomy'], queryFn: getTaxonomy, staleTime: 300_000 })
  const [form, setForm] = useState<ClientClassification>(current)

  const mutation = useMutation({
    mutationFn: () => updateClassification(clientId, {
      client_status: form.client_status,
      service_level: form.service_level,
      industry: form.industry,
      owner_id: form.owner_id,
      priority: form.priority,
      default_currency: form.default_currency || null,
      timezone: form.timezone || null,
      language: form.language,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['app', 'client', clientId] })
      qc.invalidateQueries({ queryKey: ['app', 'clients'] })
      onClose()
    },
  })

  const set = (p: Partial<ClientClassification>) => setForm((f) => ({ ...f, ...p }))
  const field = 'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'

  return (
    <form onSubmit={(e) => { e.preventDefault(); mutation.mutate() }} className="mt-4 grid gap-3 rounded-xl border border-border bg-surface-secondary p-4 sm:grid-cols-2" aria-label={t('cc_classification')}>
      <label className="text-xs font-semibold text-text-secondary">{t('cc_filter_status')}
        <select className={field} value={form.client_status ?? ''} onChange={(e) => set({ client_status: e.target.value })}>
          {(taxonomy.data?.client_statuses ?? []).map((s) => <option key={s} value={s}>{labelOf(CLIENT_STATUS_LABELS, s, lang)}</option>)}
        </select>
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('service_level')}
        <select className={field} value={form.service_level ?? ''} onChange={(e) => set({ service_level: e.target.value || null })}>
          <option value="">—</option>
          {(taxonomy.data?.service_levels ?? []).map((s) => <option key={s} value={s}>{labelOf(SERVICE_LEVEL_LABELS, s, lang)}</option>)}
        </select>
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('industry')}
        <select className={field} value={form.industry ?? ''} onChange={(e) => set({ industry: e.target.value || null })}>
          <option value="">—</option>
          {(taxonomy.data?.industries ?? []).map((s) => <option key={s} value={s}>{labelOf(INDUSTRY_LABELS, s, lang)}</option>)}
        </select>
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('cc_owner')}
        <select className={field} value={form.owner_id ?? ''} onChange={(e) => set({ owner_id: e.target.value ? Number(e.target.value) : null })}>
          <option value="">—</option>
          {(taxonomy.data?.assignable_users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </select>
      </label>
      <label className="text-xs font-semibold text-text-secondary">{t('cc_priority')}
        <select className={field} value={form.priority ?? 'normal'} onChange={(e) => set({ priority: e.target.value })}>
          {(taxonomy.data?.priorities ?? ['low', 'normal', 'high']).map((s) => <option key={s} value={s}>{labelOf(PRIORITY_LABELS, s, lang)}</option>)}
        </select>
      </label>
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

      {mutation.isError && <p className="text-xs text-danger sm:col-span-2">{t('error_generic')}</p>}
      <div className="flex items-center gap-2 sm:col-span-2">
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">{t('cc_save')}</button>
        <button type="button" onClick={onClose} className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-secondary">{t('cc_cancel')}</button>
      </div>
    </form>
  )
}
