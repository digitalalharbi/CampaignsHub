import { useEffect, useMemo, useRef, useState } from 'react'
import { isClientAudience } from './InteractiveReport'
import { ScopeCheckList } from './ScopeCheckList'
import { orderByReportability } from '@/features/campaigns/campaignRelevance'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bookmark, Info, Trash2 } from 'lucide-react'
import {
  createScopeTemplate,
  deleteScopeTemplate,
  explainScope,
  listScopeTemplates,
  scopeOptions,
  searchScopeAxis,
  type ReportScopeShape,
  type ScopeOptions,
  type ScopeTemplate,
} from './api'
import { CANONICAL_OBJECTIVES } from './reportSetupCatalog'
import { Button } from '@/components/ui/Button'
import { MultiSelectField } from '@/components/forms/MultiSelectField'
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
    covers: 'ما سيغطيه هذا التقرير',
    ranInPeriod: 'مرتّبة حسب ما عمل خلال فترة التقرير — الحالة اليوم لا تقرّر ما إذا كانت الحملة قد عملت وقتها.',
    ranGroup: 'عملت في هذه الفترة',
    noActivityGroup: 'لم تُسجّل نشاطًا في هذه الفترة',
    coversAll: 'لم تُحدَّد أي فلترة — سيغطي التقرير المشروع كاملًا خلال فترته.',
    axisCount: (n: number) => `${n} محدَّد`,
    platforms: 'المنصات',
    accounts: 'الحسابات الإعلانية',
    campaigns: 'الحملات',
    adSets: 'المجموعات الإعلانية',
    ads: 'الإعلانات',
    /*
     * CONTENT-TERMINOLOGY-001 — a content item is not an ad, and must not answer to its name.
     *
     * This axis read «الإعلانات» — the SAME word as the ad axis directly above it, in both
     * languages. Two different rungs of the hierarchy, one label: an operator narrowing by content
     * could not tell which control they were using, and the owner's ruling is explicit that Content
     * and Ads stay named apart. A creative is reused by several ads and has its own grain, which is
     * why the axis exists separately at all.
     */
    creatives: 'المحتويات',
    objectives: 'هدف التقرير (قالب القياس)',
    destinations: 'الوجهة',
    scope: 'النطاق',
    allCampaigns: 'كل الحملات',
    selectedCampaigns: 'حملات محددة',
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
    covers: 'What this report will cover',
    ranInPeriod: 'Ordered by what ran during the report’s period — today’s status does not decide whether a campaign ran then.',
    ranGroup: 'Ran in this period',
    noActivityGroup: 'No activity recorded in this period',
    coversAll: 'Nothing is narrowed — the report covers the whole project for its period.',
    axisCount: (n: number) => `${n} selected`,
    subtitle: 'Choose what this report covers. Anything you leave alone stays unbounded — the whole project.',
    platforms: 'Platforms',
    accounts: 'Ad accounts',
    campaigns: 'Campaigns',
    adSets: 'Ad sets',
    ads: 'Ads',
    creatives: 'Content',
    objectives: 'Report objective (measurement template)',
    destinations: 'Destination',
    scope: 'Scope',
    allCampaigns: 'All campaigns',
    selectedCampaigns: 'Selected campaigns',
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
type ListAxis = 'providers' | 'account_ids' | 'campaign_ids' | 'ad_set_ids' | 'ad_ids' | 'creative_ids' | 'objectives' | 'destinations' | 'paths' | 'metrics'


export function ReportScopePicker({
  projectId,
  value,
  onChange,
  audience,
}: {
  projectId: string
  value: ReportScopeShape
  onChange: (next: ReportScopeShape) => void
  /**
   * CLIENT-REPORT-AUDIENCE — who the report is FOR, which decides what may be named in it.
   *
   * «اسم واختيار الحملة احذفه من التقارير … ممكن استبداله باختيار المنصات». The builder offered
   * every internal entity by name — ad accounts, campaigns, ad sets, ads, creatives — with a
   * select-all across them, on the screen that produces a client's report. A campaign called
   * «Meta — Prospecting Broad KSA 3.2» is the agency's own working vocabulary, not something a
   * client should read, and the requirement is explicit that a client report may name platforms as
   * aggregate channels and must not name the hierarchy underneath.
   *
   * INTERNAL audiences keep the whole hierarchy. That is the other half of the requirement and it
   * is not a courtesy: a media buyer scoping an internal report to two ad sets is doing the job the
   * hierarchy exists for, and removing it globally would break the operator surface to fix the
   * client one.
   *
   * Undefined is treated as CLIENT — the builder's own default, and the safer direction to be wrong
   * in: an operator who cannot narrow by campaign asks why, while a client who reads one never knows
   * it happened.
   */
  audience?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()

  /*
   * Named-entity axes are the ones that print an internal name. Platforms, marketing paths,
   * objectives and metrics are not: a platform is the channel a client already knows they bought,
   * and a path or an objective describes what the money was for rather than what it was called.
   */
  const namesInternalEntities = !isClientAudience(audience ?? 'client')
  /*
   * REPORT-SCOPE-HIERARCHY-001 — the ad account, campaign and ad rungs are offered for EVERY audience.
   * This is the operator's own dialog, and hiding the rungs from a client-audience report left the
   * operator no way to say which accounts it covers — which is how one client's report carried
   * another's. What the CLIENT then sees stays stripped server-side (`ClientEntityBoundary`); only
   * ad sets and content, which this release does not require, keep the old audience rule.
   */

  /*
   * REPORT-SCOPE-SELECTION-001 — the campaign list knows which period it is being asked about.
   *
   * The period is in the query KEY as well as in the request. Serving June's answer from cache for a
   * July report is how a campaign that ran all July ends up filed under «did not run» — the same
   * mistake as reading today's status, arriving by a different route.
   */
  const period = { from: value.from, to: value.to }
  const filters = {
    providers: value.providers,
    account_ids: value.account_ids,
    // Only an explicit «filter campaigns by objective» narrows the list the operator picks campaigns from.
    objectives: value.objective_filter ? value.objectives : undefined,
    destinations: value.destinations,
  }
  const options = useQuery({
    queryKey: ['report-scope-options', projectId, period.from, period.to, filters],
    queryFn: () => scopeOptions(projectId, period, filters),
    retry: false,
    // The previous answer stays on screen while the narrowed one loads, so the form does not flash empty.
    placeholderData: (previous) => previous,
  })
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
  /*
   * The whole selection at once, for the axes that use a real multi-select.
   *
   * `toggle` flips one id and is what a chip needs; a multi-select hands back the full array. The
   * empty-axis rule is the same in both and is the part that matters: an axis with nothing chosen
   * is REMOVED from the scope rather than stored as an empty list, because «[]» and «absent» mean
   * different things to the report — one is «narrowed to nothing», the other is «not narrowed».
   */
  /*
   * `latest` is the scope AS LAST WRITTEN, so two changes before a re-render compose instead of the
   * second discarding the first (see `ScopeCheckList`).
   */
  const latest = useRef(value)
  latest.current = value
  const emit = (out: ReportScopeShape) => {
    latest.current = out
    onChange(out)
  }
  const set = (axis: ListAxis, next: string[]) => {
    const out = { ...latest.current }
    if (next.length === 0) delete out[axis]
    else out[axis] = next
    emit(out)
  }

  const toggle = (axis: ListAxis, id: string) => {
    const current = latest.current[axis] ?? []
    const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
    const out = { ...latest.current }
    if (next.length === 0) delete out[axis]
    else out[axis] = next
    emit(out)
  }

  const setDate = (key: 'from' | 'to', v: string) => {
    const out = { ...latest.current }
    if (!v) delete out[key]
    else out[key] = v
    emit(out)
  }

  const boundCount = useMemo(
    () => Object.entries(value).filter(([, v]) => (Array.isArray(v) ? v.length > 0 : Boolean(v))).length,
    [value],
  )

  /*
   * The campaigns, ordered by what ran in the REPORT'S window — ENTITY-RELEVANCE-ORDERING-001.
   *
   * Through `orderByReportability` and NOT `orderByRelevance`, and the difference is the whole
   * clause. The relevance rule answers «what can an operator act on now», and its first move is to
   * file anything completed as stopped however much it spent — right for the campaigns workspace and
   * wrong here, because a campaign completed in August may have been the largest spender in the July
   * report being built, and it would be buried under campaigns running today that contributed
   * nothing to that month.
   *
   * With no period the server states no `last_active_on` and this returns the list untouched: an
   * absence of a question is not an answer.
   */
  const campaignItems = useMemo(() => {
    const rows = (options.data?.campaigns ?? []).map((c) => ({
      campaign_id: c.id,
      status: c.status,
      last_active_on: c.last_active_on ?? null,
      spend: null,
      name: c.name,
    }))

    const ordered = orderByReportability(rows, period)

    /*
     * REPORT-SCOPE-SELECTION-001 §B — the ordering's boundary, made visible.
     *
     * Ordering by reportability told an operator nothing they could see: scrolling 120 rows, «did
     * not run in this period» and «further down the alphabet» look identical, and that distinction
     * is the entire reason the order is not alphabetical. The heading decides EMPHASIS, never
     * membership — nothing is filtered out, exactly as the ordering promised.
     *
     * Through `Option.group`, which `MultiSelectField` already renders as a header. A second
     * grouping mechanism here would be a second opinion about what a heading is.
     *
     * No period asked about means NO CLAIM — not «did not run». That is the same rule
     * `orderByReportability` follows when it returns the list untouched, and the reason it is a rule
     * is that the absence of a question is not an answer.
     */
    const asked = Boolean(period.from && period.to)

    return ordered.map((c) => ({
      id: c.campaign_id,
      label: c.name,
      group: asked ? (c.last_active_on === null ? t.noActivityGroup : t.ranGroup) : undefined,
    }))
  }, [options.data, period.from, period.to, t])

  /*
   * The hierarchy below the accounts: campaigns exist only inside the chosen accounts, and ads only
   * inside the ticked campaigns — or, with none ticked, inside every campaign the accounts leave.
   */
  const accountsChosen = (value.account_ids ?? []).length > 0
  const campaignName = useMemo(() => new Map(campaignItems.map((c) => [c.id, c.label])), [campaignItems])
  const visibleAds = useMemo(() => {
    const within = new Set((value.campaign_ids ?? []).length > 0 ? value.campaign_ids : campaignItems.map((c) => c.id))

    return (options.data?.ads ?? []).filter((a) => within.has(a.campaign_id))
  }, [options.data, value.campaign_ids, campaignItems])

  /*
   * A selection can outlive the choice above it — an account unticked, a campaign unticked. The ids
   * that no longer sit inside the hierarchy are dropped, because the server refuses a campaign
   * outside its account and an ad outside its campaign. Only once the narrowed lists have arrived.
   */
  useEffect(() => {
    if (!options.data || options.isPlaceholderData) return
    const campaigns = new Set(accountsChosen ? campaignItems.map((c) => c.id) : [])
    const ads = new Set(accountsChosen ? visibleAds.map((a) => a.id) : [])
    const keepCampaigns = (value.campaign_ids ?? []).filter((id) => campaigns.has(id))
    const keepAds = (value.ad_ids ?? []).filter((id) => ads.has(id))
    if (keepCampaigns.length === (value.campaign_ids ?? []).length && keepAds.length === (value.ad_ids ?? []).length) return
    const out = { ...value }
    if (keepCampaigns.length === 0) delete out.campaign_ids
    else out.campaign_ids = keepCampaigns
    if (keepAds.length === 0) delete out.ad_ids
    else out.ad_ids = keepAds
    onChange(out)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options.data, options.isPlaceholderData, campaignItems, visibleAds, accountsChosen])

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

      {/*
        REPORT-SCOPE-SELECTION-001 §C — what this scope actually covers, before it is exported.

        `explain()` has been on the server since the scope object existed, and three endpoints
        returned it — a saved scope, a template, the result of saving. None of them was the BUILDER,
        so the one sentence that matters while somebody is choosing — «selecting two ad sets does not
        narrow to ad-set grain, because no metric is stored there» — was reachable everywhere except
        the screen where the choice is made.

        Asked of the server rather than derived here: the rule is the generator's, and a second copy
        would disagree with it the first time an axis changed depth.
      */}
      <ScopeStatement projectId={projectId} value={value} t={t} ar={ar} />

      <Chips
        label={t.platforms}
        items={o.providers.map((p) => ({ id: p, label: p }))}
        selected={value.providers ?? []}
        onToggle={(id) => toggle('providers', id)}
        ar={ar}
        t={t}
      />

      <ScopeCheckList
        kind="accounts"
        label={t.accounts}
        required
        items={o.accounts.map((a) => ({
          id: a.id,
          label: a.name,
          // REPORT-BOUND-ACCOUNTS-001 — a bound account with nothing yet is listed and SAYS so.
          sub: a.state === 'unsynced' ? `${a.provider} · ${ar ? 'لم تتم مزامنته بعد' : 'not synced yet'}` : a.state === 'no_data' ? `${a.provider} · ${ar ? 'لا بيانات بعد' : 'no data yet'}` : a.provider,
        }))}
        selected={value.account_ids ?? []}
        onChange={(next) => set('account_ids', next)}
        ar={ar}
      />

      {/*
        REPORT-SETUP-001 — Objective, then Destination, then Campaigns: each narrows the next.
        Six canonical objectives, whatever native objective each provider spelt; the native word stays
        in the data. «Marketing paths» is gone — it was a second, overlapping way to say the same thing.
      */}
      <Chips
        label={t.objectives}
        items={CANONICAL_OBJECTIVES.map((x) => ({ id: x.id, label: ar ? x.ar : x.en }))}
        selected={CANONICAL_OBJECTIVES.filter((x) => x.members.every((m) => (value.objectives ?? []).includes(m))).map((x) => x.id)}
        onToggle={(id) => {
          const group = CANONICAL_OBJECTIVES.find((x) => x.id === id)
          if (!group) return
          const current = value.objectives ?? []
          const on = group.members.every((m) => current.includes(m))
          set('objectives', on ? current.filter((m) => !group.members.includes(m)) : [...new Set([...current, ...group.members])])
        }}
        ar={ar}
        t={t}
      />
      {/*
        REPORT-OBJECTIVE-TEMPLATE-001 — the chips above choose the report's measurement template (the funnel and KPIs it presents). They
        never remove a platform or a campaign by themselves: filtering campaigns by their stored objective is this separate, explicit switch.
      */}
      {(value.objectives?.length ?? 0) > 0 && (
        <label className="flex cursor-pointer items-start gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-xs" data-testid="scope-objective-filter-row">
          <input
            type="checkbox"
            data-testid="scope-objective-filter"
            checked={value.objective_filter === true}
            onChange={(e) => onChange({ ...value, objective_filter: e.target.checked ? true : undefined })}
            className="mt-0.5 h-4 w-4 accent-brand-600"
          />
          <span>
            <b className="block font-semibold">{ar ? 'تصفية الحملات حسب الهدف' : 'Filter campaigns by objective'}</b>
            <span className="text-text-secondary">
              {ar
                ? 'اتركه مغلقًا لتشمل كل المنصات والحملات المصرّح بها. عند تفعيله تُستبعد الحملات التي هدفها المسجّل خارج الأهداف المختارة.'
                : 'Leave it off to include every authorised platform and campaign. When on, campaigns whose stored objective is outside the chosen ones are excluded.'}
            </span>
          </span>
        </label>
      )}

      {(o.destinations?.length ?? 0) > 0 && (
        <Chips
          label={t.destinations}
          items={(o.destinations ?? []).map((d) => ({ id: d.key, label: `${ar ? d.labels.ar : d.labels.en} (${d.campaign_count})` }))}
          selected={value.destinations ?? []}
          onToggle={(id) => toggle('destinations', id)}
          ar={ar}
          t={t}
        />
      )}

      {/*
        Campaigns of the SELECTED accounts only, as visible checkboxes. Nothing ticked means every
        campaign inside that choice — never every campaign of the project.
      */}
      {accountsChosen && (
        <ScopeCheckList
          kind="campaigns"
          label={t.campaigns}
          note={period.from && period.to ? t.ranInPeriod : undefined}
          truncated={o.truncated?.campaigns}
          limit={o.limit}
          items={campaignItems.map((c) => ({ id: c.id, label: c.label, group: c.group }))}
          selected={value.campaign_ids ?? []}
          onChange={(next) => set('campaign_ids', next)}
          emptyMeans={ar ? 'كل حملات الحسابات المحددة' : 'All campaigns of the selected accounts'}
          ar={ar}
        />
      )}

      {namesInternalEntities && o.ad_sets.length > 0 && (
        <ScopeSelect
          label={t.adSets}
          note={t.grainCampaign}
          truncated={o.truncated?.ad_sets}
        axis="ad_sets"
        projectId={projectId}
          limit={o.limit}
          items={o.ad_sets.map((s) => ({ id: s.id, label: s.name }))}
          selected={value.ad_set_ids ?? []}
          onChange={(next) => set('ad_set_ids', next)}
          ar={ar}
          t={t}
        />
      )}

      {accountsChosen && visibleAds.length > 0 && (
        <ScopeCheckList
          kind="ads"
          label={t.ads}
          truncated={o.truncated?.ads}
          limit={o.limit}
          items={visibleAds.map((a) => ({ id: a.id, label: a.name, sub: campaignName.get(a.campaign_id) }))}
          selected={value.ad_ids ?? []}
          onChange={(next) => set('ad_ids', next)}
          emptyMeans={ar ? 'كل إعلانات الحملات المحددة' : 'All ads of the selected campaigns'}
          ar={ar}
        />
      )}

      {namesInternalEntities && o.creatives.length > 0 && (
        <ScopeSelect
          label={t.creatives}
          note={t.grainCreatives}
          truncated={o.truncated?.creatives}
        axis="creatives"
        projectId={projectId}
          limit={o.limit}
          items={o.creatives.map((c) => ({ id: c.id, label: c.name }))}
          selected={value.creative_ids ?? []}
          onChange={(next) => set('creative_ids', next)}
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

/**
 * UX-MULTISELECT-SCALE-001 — the entity axes, at the cardinality a real account has.
 *
 * `Chips` lays every option out flat. That is right for the five marketing paths and the eight
 * platforms, which are a fixed short list. It is wrong for campaigns, ad sets, ads, creatives and
 * accounts: the live estate holds hundreds, and a wall of hundreds of chips is not a control — an
 * operator cannot find «Ramadan» in it, and the server's own cap means the one they want may not
 * even be on screen.
 *
 * So those axes use the product's shared `MultiSelectField`, which is what every other multi-select surface
 * already uses: a search box, Select all / Clear all, and the selection kept as removable chips.
 * Same component, same behaviour, one place to fix.
 *
 * The truncation notice is kept exactly as it was and rendered ABOVE the control, because that is
 * where an operator concludes their campaign does not exist — and it now matters more, not less: a
 * search box that finds nothing is the moment somebody needs to be told the list was capped
 * server-side rather than that their campaign is missing.
 */
function ScopeSelect({
  label,
  note,
  items,
  selected,
  onChange,
  truncated,
  limit,
  ar,
  t,
  axis,
  projectId,
  kind,
}: {
  label: string
  /**
   * The grain warning, carried across from `Chips` — «No metrics are stored at this level».
   *
   * Dropping it while moving these axes to a real control lost the sentence that tells an operator
   * an ad-set selection narrows the CAMPAIGNS behind it rather than the figures themselves. A test
   * written long before this change caught it, which is the whole reason it was written.
   */
  note?: string
  /**
   * `group` is optional and MEANS «no claim» when absent — see `campaignItems`.
   *
   * Carried on the item rather than computed here, because only the caller knows which axis has an
   * activity answer and which does not: the server's own axis SEARCH returns `{id, name}` and says
   * nothing about the window, so a searched row stays ungrouped rather than being filed under
   * «recorded no activity» on the strength of a question nobody asked.
   */
  items: Array<{ id: string; label: string; group?: string }>
  selected: string[]
  onChange: (next: string[]) => void
  truncated?: boolean
  limit?: number
  ar: boolean
  t: typeof COPY.ar
  /**
   * UX-MULTISELECT-SCALE-001 — the axis to SEARCH on the server, where its rows are too many to send.
   *
   * Absent for the axes that are small and closed — objectives, paths, metrics — where the whole set
   * fits and a round trip per keystroke would buy nothing.
   */
  axis?: string
  projectId?: string
  /** A language-independent name for the rung, for tests and tools that must not depend on the label. */
  kind?: string
}) {
  const [term, setTerm] = useState('')

  /*
   * The server's answer for what the reader typed, and only once they have typed something.
   *
   * Three characters rather than one: a single letter matches most of a large account and the
   * request it costs answers a question nobody asked. Below that the local filter inside
   * `MultiSelectField` is still working on the list already in hand, which is the common case.
   */
  const remote = useQuery({
    queryKey: ['scope-axis-search', projectId, axis, term],
    queryFn: () => searchScopeAxis(projectId ?? '', axis ?? '', term),
    enabled: Boolean(projectId) && Boolean(axis) && term.trim().length >= 3,
  })

  const found = (remote.data?.[axis ?? ''] as Array<{ id: string; name: string }> | undefined) ?? null

  /*
   * What the control offers: the server's matches when it has any, the page's own list otherwise.
   *
   * The SELECTED ids are merged in either way. A chip whose option is missing renders as a raw id —
   * so an operator who picks an ad set by search and then clears the box would watch their own
   * selection turn into a UUID.
   */
  /*
   * A SEARCHED row carries no group, and that is the honest shape.
   *
   * `searchAxis` answers «which rows match this term», not «which ran in the report's window», so
   * filing its results under «recorded no activity» would print an answer to a question the server
   * was never asked. They render ungrouped, beside the grouped page list they replace.
   */
  const offered: Array<{ id: string; label: string; group?: string }> =
    found === null ? items : found.map((r) => ({ id: r.id, label: r.name }))

  /*
   * Every name this control has ever shown, kept for as long as the picker is open.
   *
   * `items` is the page's bounded list and `found` is one search's answer. A selection made from a
   * search is in NEITHER once the box is cleared — so a first version of this merge fell back to
   * `{ id, label: id }` and the chip turned into a UUID the moment the reader deleted what they had
   * typed. The test caught it, which is what it was written for.
   *
   * A ref rather than state: remembering a label changes nothing on screen by itself, and making it
   * state would re-render the control on every answer that taught it a name it already had.
   */
  const known = useRef(new Map<string, string>())
  for (const i of [...items, ...offered]) {
    known.current.set(i.id, i.label)
  }

  const byId = new Map(offered.map((i) => [i.id, i]))
  for (const id of selected) {
    if (!byId.has(id)) {
      byId.set(id, { id, label: known.current.get(id) ?? id })
    }
  }
  const options = [...byId.values()]

  if (items.length === 0 && selected.length === 0) return null

  return (
    <div data-testid={`scope-select-${label}`} data-kind={kind}>
      <div className="mb-1.5 flex flex-wrap items-center gap-2">
        <span className="text-xs font-bold text-text-primary">{label}</span>
        {selected.length > 0 && (
          <span className="tnum rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-600">
            {selected.length} {t.selected}
          </span>
        )}
      </div>

      {note && (
        <p className="mb-1.5 flex items-start gap-1 text-[10px] text-text-secondary">
          <Info size={11} className="mt-px shrink-0" /> {note}
        </p>
      )}

      {truncated === true && (
        <p
          data-testid={`scope-truncated-${label}`}
          className="mb-1.5 flex items-start gap-1 text-[10px] font-semibold text-warning"
        >
          <Info size={11} className="mt-px shrink-0" />
          {/*
            The sentence changed with the capability. It used to say «narrow the scope to reach the
            rest», which was the honest instruction while there was no other way — and it asked an
            operator to change the thing they were building in order to see it. Searching now reaches
            past the bound, so that is what it says.
          */}
          {ar
            ? `يُعرض ${limit ?? items.length} فقط — ابحث بالاسم للوصول إلى البقية`
            : `Showing ${limit ?? items.length} only — search by name to reach the rest`}
        </p>
      )}

      <MultiSelectField
        label=""
        value={selected}
        onChange={onChange}
        options={options.map((i) => ({ value: i.id, label: i.label, group: i.group }))}
        /* Typed into the box, answered by the server — see the note on `axis`. */
        onSearchChange={axis === undefined ? undefined : setTerm}
        /*
         * Always searchable on these axes. `MultiSelectField` decides for itself above seven
         * options, and that is the right default elsewhere — here the axis is known to be large
         * even on the days it happens to be short, and a control that changes shape with the data
         * teaches an operator two different screens.
         */
        searchable
      />
    </div>
  )
}

/**
 * The scope in words, kept live with the choices above it.
 *
 * Keyed on the scope, so React Query dedupes while an operator toggles the same chip twice and does
 * not re-ask for an answer it already holds. A failure is silent: this explains a choice, it does
 * not gate one, and an error panel over a builder would be a worse outcome than no sentence.
 */
function ScopeStatement({
  projectId,
  value,
  t,
  ar,
}: {
  projectId: string
  value: ReportScopeShape
  t: typeof COPY.en
  ar: boolean
}) {
  const q = useQuery({
    queryKey: ['report-scope-explain', projectId, value],
    queryFn: () => explainScope(projectId, value),
    enabled: Boolean(projectId),
  })

  if (q.isError || q.data === undefined) {
    return null
  }

  const rows = q.data.explain

  return (
    <div data-testid="scope-statement" className="rounded-xl border border-border bg-surface-secondary/40 p-3">
      <span className="block text-[11px] font-bold text-text-secondary">{t.covers}</span>

      {rows.length === 0 ? (
        <p data-testid="scope-statement-all" className="mt-1 text-[11px] leading-relaxed text-text-secondary">
          {t.coversAll}
        </p>
      ) : (
        <ul className="mt-1 space-y-1">
          {rows.map((row) => (
            <li key={row.axis} data-testid={`scope-statement-${row.axis}`} className="text-[11px] leading-relaxed">
              <span className="font-semibold text-text-primary">
                {AXIS_LABEL[row.axis] ? (ar ? AXIS_LABEL[row.axis].ar : AXIS_LABEL[row.axis].en) : row.axis}
              </span>
              <span className="text-text-muted"> · {t.axisCount(row.count)}</span>
              {/*
                The GRAIN note, always — including where it is the reassuring one.
                
                «Narrows every figure» and «no metrics are stored at this level» are both facts a
                reader needs, and showing only the warning would teach an operator that the absence
                of a note means nothing was worth saying.
              */}
              <span className="block text-text-secondary">{ar ? row.note_ar : row.note_en}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** The axes, named as the controls above name them, so the statement reads as a summary of them. */
const AXIS_LABEL: Record<string, { ar: string; en: string }> = {
  client_ids: { ar: 'العملاء', en: 'Clients' },
  project_ids: { ar: 'المشاريع', en: 'Projects' },
  providers: { ar: 'المنصات', en: 'Platforms' },
  account_ids: { ar: 'الحسابات الإعلانية', en: 'Ad accounts' },
  campaign_ids: { ar: 'الحملات', en: 'Campaigns' },
  ad_set_ids: { ar: 'المجموعات الإعلانية', en: 'Ad sets' },
  ad_ids: { ar: 'الإعلانات', en: 'Ads' },
  creative_ids: { ar: 'المحتويات', en: 'Content' },
  objectives: { ar: 'الأهداف', en: 'Objectives' },
  paths: { ar: 'المسارات التسويقية', en: 'Marketing paths' },
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
