import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bookmark, Info, Trash2 } from 'lucide-react'
import {
  createScopeTemplate,
  deleteScopeTemplate,
  listScopeTemplates,
  scopeOptions,
  type ReportScopeShape,
  type ScopeOptions,
  type ScopeTemplate,
} from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'

/**
 * §14.5 — choosing what a report covers.
 *
 * ## Why the deeper axes carry a note
 *
 * Operators think in ad sets and ads, so the picker offers them. But no metric is stored at that
 * grain in this system — the platforms' insights arrive per campaign — so selecting an ad set
 * narrows to the campaigns behind it. The picker says that where the choice is made, not in a
 * footnote: a reader who ticks three ads and then reads a spend figure is entitled to know it is the
 * campaigns' spend, and finding out later is finding out in front of a client.
 *
 * ## Omitted, not empty
 *
 * An axis nobody touched is left OUT of the payload rather than sent as `[]`. The two are different
 * on the server — absent means «no bound», and the empty list is reserved for the fail-closed case
 * where an intersection produced nothing — and blurring them here is how a filter quietly becomes
 * «everything».
 */

const COPY = {
  ar: {
    title: 'نطاق التقرير',
    subtitle: 'اختر ما يغطيه هذا التقرير. كل ما لا تختاره يبقى بلا تحديد — أي كل المشروع.',
    platforms: 'المنصات',
    accounts: 'الحسابات الإعلانية',
    campaigns: 'الحملات',
    adSets: 'المجموعات الإعلانية',
    ads: 'الإعلانات',
    creatives: 'الإعلانات',
    objectives: 'الأهداف',
    paths: 'المسارات التسويقية',
    metrics: 'المؤشرات المعروضة',
    from: 'من',
    to: 'إلى',
    none: 'لا توجد خيارات لهذا المشروع بعد.',
    loadError: 'تعذّر تحميل خيارات النطاق.',
    grainCampaign: 'لا تُخزَّن مؤشرات على هذا المستوى — الاختيار يضيّق الحملات التابعة له.',
    grainCreatives: 'يضيّق قسم الإعلانات وحده؛ إجماليات الحملات تبقى على مستوى الحملة.',
    templates: 'نطاقات محفوظة',
    saveTemplate: 'حفظ هذا النطاق',
    templateName: 'اسم النطاق',
    apply: 'تطبيق',
    remove: 'حذف',
    shared: 'لكل العملاء',
    clear: 'مسح التحديد',
    selected: 'محدَّد',
    saved: 'حُفظ.',
  },
  en: {
    title: 'Report scope',
    subtitle: 'Choose what this report covers. Anything you leave alone stays unbounded — the whole project.',
    platforms: 'Platforms',
    accounts: 'Ad accounts',
    campaigns: 'Campaigns',
    adSets: 'Ad sets',
    ads: 'Ads',
    creatives: 'Ads',
    objectives: 'Objectives',
    paths: 'Marketing paths',
    metrics: 'Metrics shown',
    from: 'From',
    to: 'To',
    none: 'No options for this project yet.',
    loadError: 'The scope options could not be loaded.',
    grainCampaign: 'No metrics are stored at this level — the choice narrows the campaigns behind it.',
    grainCreatives: 'Narrows the ad section only; campaign totals stay at campaign grain.',
    templates: 'Saved scopes',
    saveTemplate: 'Save this scope',
    templateName: 'Scope name',
    apply: 'Apply',
    remove: 'Remove',
    shared: 'All clients',
    clear: 'Clear',
    selected: 'selected',
    saved: 'Saved.',
  },
}

/** The axes this component edits as multi-selects, in the order they narrow from wide to deep. */
type ListAxis = 'providers' | 'account_ids' | 'campaign_ids' | 'ad_set_ids' | 'ad_ids' | 'creative_ids' | 'objectives' | 'paths' | 'metrics'

export function ReportScopePicker({
  projectId,
  value,
  onChange,
}: {
  projectId: string
  value: ReportScopeShape
  onChange: (next: ReportScopeShape) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()

  const options = useQuery({ queryKey: ['report-scope-options', projectId], queryFn: () => scopeOptions(projectId), retry: false })
  const templates = useQuery({ queryKey: ['report-scope-templates', projectId], queryFn: () => listScopeTemplates(projectId), retry: false })

  const [templateName, setTemplateName] = useState('')

  const save = useMutation({
    mutationFn: () => createScopeTemplate(projectId, { name: templateName.trim(), scope: value }),
    onSuccess: () => {
      setTemplateName('')
      void qc.invalidateQueries({ queryKey: ['report-scope-templates', projectId] })
    },
  })

  const remove = useMutation({
    mutationFn: (id: string) => deleteScopeTemplate(projectId, id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['report-scope-templates', projectId] }),
  })

  const o = options.data

  /**
   * Toggle one member of one axis.
   *
   * Emptying an axis DELETES the key rather than leaving `[]` behind — see the note at the top of
   * this file. The two shapes mean different things on the server and only one of them means «I
   * stopped narrowing by this».
   */
  const toggle = (axis: ListAxis, id: string) => {
    const current = value[axis] ?? []
    const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
    const out = { ...value }
    if (next.length === 0) delete out[axis]
    else out[axis] = next
    onChange(out)
  }

  const setDate = (key: 'from' | 'to', v: string) => {
    const out = { ...value }
    if (!v) delete out[key]
    else out[key] = v
    onChange(out)
  }

  const boundCount = useMemo(
    () => Object.entries(value).filter(([, v]) => (Array.isArray(v) ? v.length > 0 : Boolean(v))).length,
    [value],
  )

  if (options.isLoading) return <Skeleton className="h-40 w-full" />
  if (options.isError) {
    return <ErrorState title={t.loadError} error={options.error} onRetry={() => void options.refetch()} ar={ar} />
  }
  if (!o) return null

  return (
    <div className="space-y-4" data-testid="report-scope-picker">
      <div>
        <span className="block text-sm font-bold text-text-primary">{t.title}</span>
        <span className="mt-0.5 block text-[11px] text-text-secondary">{t.subtitle}</span>
      </div>

      <Chips
        label={t.platforms}
        items={o.providers.map((p) => ({ id: p, label: p }))}
        selected={value.providers ?? []}
        onToggle={(id) => toggle('providers', id)}
        ar={ar}
        t={t}
      />

      <Chips
        label={t.accounts}
        items={o.accounts.map((a) => ({ id: a.id, label: `${a.name} · ${a.provider}` }))}
        selected={value.account_ids ?? []}
        onToggle={(id) => toggle('account_ids', id)}
        ar={ar}
        t={t}
      />

      <Chips
        label={t.campaigns}
        truncated={o.truncated?.campaigns}
        limit={o.limit}
        items={o.campaigns.map((c) => ({ id: c.id, label: c.name }))}
        selected={value.campaign_ids ?? []}
        onToggle={(id) => toggle('campaign_ids', id)}
        ar={ar}
        t={t}
      />

      <Chips
        label={t.paths}
        items={o.paths.map((p) => ({ id: p.key, label: ar ? p.labels.ar : p.labels.en }))}
        selected={value.paths ?? []}
        onToggle={(id) => toggle('paths', id)}
        ar={ar}
        t={t}
      />

      <Chips
        label={t.objectives}
        items={o.objectives.map((x) => ({ id: x.key, label: ar ? x.labels.ar : x.labels.en }))}
        selected={value.objectives ?? []}
        onToggle={(id) => toggle('objectives', id)}
        ar={ar}
        t={t}
      />

      {o.ad_sets.length > 0 && (
        <Chips
          label={t.adSets}
        truncated={o.truncated?.ad_sets}
        limit={o.limit}
          note={t.grainCampaign}
          items={o.ad_sets.map((s) => ({ id: s.id, label: s.name }))}
          selected={value.ad_set_ids ?? []}
          onToggle={(id) => toggle('ad_set_ids', id)}
          ar={ar}
          t={t}
        />
      )}

      {o.ads.length > 0 && (
        <Chips
          label={t.ads}
        truncated={o.truncated?.ads}
        limit={o.limit}
          note={t.grainCampaign}
          items={o.ads.map((a) => ({ id: a.id, label: a.name }))}
          selected={value.ad_ids ?? []}
          onToggle={(id) => toggle('ad_ids', id)}
          ar={ar}
          t={t}
        />
      )}

      {o.creatives.length > 0 && (
        <Chips
          label={t.creatives}
        truncated={o.truncated?.creatives}
        limit={o.limit}
          note={t.grainCreatives}
          items={o.creatives.map((c) => ({ id: c.id, label: c.name }))}
          selected={value.creative_ids ?? []}
          onToggle={(id) => toggle('creative_ids', id)}
          ar={ar}
          t={t}
        />
      )}

      <Chips
        label={t.metrics}
        items={o.metrics.map((m) => ({ id: m.key, label: ar ? m.ar : m.en }))}
        selected={value.metrics ?? []}
        onToggle={(id) => toggle('metrics', id)}
        ar={ar}
        t={t}
      />

      <div className="grid grid-cols-2 gap-3">
        <Field label={t.from} htmlFor="scope-from">
          <DateField id="scope-from" value={value.from ?? ''} onChange={(v) => setDate('from', v)} />
        </Field>
        <Field label={t.to} htmlFor="scope-to">
          <DateField id="scope-to" value={value.to ?? ''} onChange={(v) => setDate('to', v)} />
        </Field>
      </div>

      {/* Reusable scopes — the half of §14.5 that makes next month's report a click rather than a re-pick. */}
      <div className="rounded-xl border border-border p-3">
        <span className="flex items-center gap-1.5 text-xs font-bold text-text-primary">
          <Bookmark size={13} /> {t.templates}
        </span>

        <div className="mt-2 flex flex-wrap gap-2">
          {(templates.data?.templates ?? []).map((tpl: ScopeTemplate) => (
            <span key={tpl.id} className="inline-flex items-center gap-1 rounded-lg bg-surface-secondary px-2 py-1 text-[11px]">
              <button type="button" className="font-semibold text-text-primary hover:text-brand-600" onClick={() => onChange(tpl.scope)}>
                {tpl.name}
              </button>
              {tpl.shared && <span className="text-text-muted">· {t.shared}</span>}
              <button type="button" aria-label={t.remove} className="text-text-muted hover:text-danger" onClick={() => remove.mutate(tpl.id)}>
                <Trash2 size={11} />
              </button>
            </span>
          ))}
        </div>

        <div className="mt-2 flex gap-2">
          <input
            value={templateName}
            onChange={(e) => setTemplateName(e.target.value)}
            placeholder={t.templateName}
            data-testid="scope-template-name"
            className="flex-1 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs outline-none focus:border-brand-500"
          />
          <Button
            variant="secondary"
            disabled={templateName.trim() === '' || boundCount === 0}
            loading={save.isPending}
            onClick={() => save.mutate()}
          >
            {t.saveTemplate}
          </Button>
        </div>
      </div>
    </div>
  )
}

function Chips({
  label,
  note,
  items,
  selected,
  onToggle,
  truncated,
  limit,
  ar,
  t,
}: {
  label: string
  note?: string
  items: Array<{ id: string; label: string }>
  selected: string[]
  onToggle: (id: string) => void
  /**
   * The server reached its cap on this axis and there are more.
   *
   * Said where the choice is made rather than at the top of the form, because that is where an
   * operator concludes their campaign does not exist. Undefined means the server did not say — which
   * is not the same as «nothing was truncated», and is rendered as nothing rather than as a promise.
   */
  truncated?: boolean
  limit?: number
  ar: boolean
  t: typeof COPY.ar
}) {
  if (items.length === 0) return null

  return (
    <div>
      <div className="mb-1.5 flex flex-wrap items-center gap-2">
        <span className="text-xs font-bold text-text-primary">{label}</span>
        {selected.length > 0 && (
          <span className="tnum rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-600">
            {selected.length} {t.selected}
          </span>
        )}
      </div>

      {note && (
        <p className="mb-1.5 flex items-start gap-1 text-[10px] text-text-muted">
          <Info size={11} className="mt-px shrink-0" /> {note}
        </p>
      )}

      {truncated === true && (
        <p
          data-testid={`scope-truncated-${label}`}
          className="mb-1.5 flex items-start gap-1 text-[10px] font-semibold text-warning"
        >
          <Info size={11} className="mt-px shrink-0" />
          {ar
            ? `يُعرض ${limit ?? items.length} فقط — استخدم البحث أو ضيّق النطاق للوصول إلى البقية`
            : `Showing ${limit ?? items.length} only — narrow the scope to reach the rest`}
        </p>
      )}

      <div className="flex flex-wrap gap-1.5">
        {items.map((item) => {
          const on = selected.includes(item.id)
          return (
            <button
              key={item.id}
              type="button"
              aria-pressed={on}
              data-testid={`scope-chip-${item.id}`}
              onClick={() => onToggle(item.id)}
              className={`rounded-lg border px-2.5 py-1 text-[11px] transition-colors ${
                on ? 'border-brand-500 bg-brand-500/10 font-semibold text-brand-600' : 'border-border text-text-secondary hover:border-border-strong'
              }`}
            >
              {item.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export type { ScopeOptions }
